<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AllergenValue;
use Platform\FoodAlchemist\Enums\EkPriceBasis;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * M4-03: GL-02-Recompute-Pipeline — EINE Transaktion pro Rezept (V-07):
 *   1. Yield + Zähler + Konfidenz (GL-02 §3.1, GL-01 §4.4)
 *   2. Allergene (GL-01: F7.1-Guard, GP-Auflösung via GpAggregateService = Prio-Kette 4.3)
 *   3. Zusatzstoffe (GL-09: gleicher Guard, MAX über Roh-Domäne)
 *   4. Kosten (GL-02 §3.2, T3-Kaskade; I7: Nenner = GERUNDETES yield)
 *   5. Nährwerte (GL-08: KEIN Guard, Rohmasse-Basis, 0-Substitution GT-02)
 *   6. Spec-Flags: spec_is_gluten_free normativ aus GL-01 (in allergene()); vegan/
 *      vegetarisch/halal/laktosefrei + Schwein/Rind dynamisch aus GP-Tags über alle
 *      Ebenen (specFlags(), 2026-07-06 — löst die statische recipes-Spalte ab).
 *
 * Entscheid A-1 (Empfehlung umgesetzt, 08_ENTSCHEIDUNGEN): Verluste MULTIPLIKATIV
 * (1−putz)×(1−gar) aus den Zutat-Feldern — DB-verifiziert über GT-1/GT-2.
 * A-2-Ziel: Yield- und Kosten-Pfad nutzen BEIDE die volle T1-Kaskade.
 * A-3: Kalkulations-Yield = COALESCE(yield_kg_manual, yield_kg).
 * A-5 (2026-08-03 gelockert): KEIN hartes Tiefenlimit mehr — Menü-/Komposit-Stapelung
 *   ([FIN]→[SUP]→Komponente→Basisrezept→Fond) ist legitim tief. Einzige Verknüpfungs-
 *   Invariante bleibt Zyklus-Schutz + Selbstreferenz. Regelwerk BR §4.3.
 * I9: vk_* wird hier NIEMALS geschrieben.
 */
class RecipeRecomputeService
{
    // A-5 (2026-08-03): Kein hartes Sub-Rezept-Tiefenlimit mehr (Regelwerk BR §4.3 gelockert).
    // Die Tiefen-BFS terminiert allein über das Visited-Set; Zyklen fängt pruefeVerknuepfung.

    private const PROPAGATION_LIMIT = 10;

    /** Team + WG-Default-Memo des aktuell laufenden Rezepts (Verlust-Kaskade, GL-02). */
    private ?Team $recomputeTeam = null;

    /** @var array<string, float> */
    private array $garverlustWgCache = [];

    /** @var array<string, float> */
    private array $putzverlustWgCache = [];

    public function __construct(
        private GpAggregateService $gpAggregate,
        private PriceService $preise,
    ) {}

    /** Pipeline für EIN Rezept — idempotent (I4), eine Transaktion (V-07). */
    public function recomputePipeline(int $recipeId, bool $cascadePrices = true): void
    {
        $this->laCache = [];                                       // Preis-Memo nie über Edits hinweg tragen
        $this->leadPrefCache = [];
        DB::transaction(function () use ($recipeId) {
            $recipe = FoodAlchemistRecipe::with(['ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe'])
                ->findOrFail($recipeId);
            // Verlust-Kaskade (GL-02): Team + WG-Default-Memo je Recompute-Lauf frisch.
            $this->recomputeTeam = Team::find($recipe->team_id);
            $this->garverlustWgCache = [];
            $this->putzverlustWgCache = [];
            $zutaten = $recipe->ingredients->filter(fn ($z) => $z->match_method !== MatchMethod::Ignored);

            $this->yieldUndZaehler($recipe, $zutaten);
            $this->allergene($recipe, $zutaten);
            $this->zusatzstoffe($recipe, $zutaten);
            $this->kosten($recipe, $zutaten);
            $this->naehrwerte($recipe, $zutaten);
            $this->specFlags($recipe, $zutaten);
            $recipe->save();
        });

        // Umbau-Spec Phase 5: Darreichungspreise folgen dem frischen EK (lazy resolved,
        // kein Konstruktor-Zyklus — DarreichungService hängt seinerseits an diesem Service).
        app(DarreichungService::class)->recomputeFuerRezept($recipeId);
        if ($cascadePrices) {
            try {
                app(PricingCascadeService::class)->recomputeRecipes([$recipeId]);
            } catch (\Throwable $e) {
                Log::warning("Preis-Kaskade für Rezept {$recipeId} fehlgeschlagen: {$e->getMessage()}");
            }
        }
    }

    /**
     * §3.3: Pipeline + alle transitiven Eltern per BFS (best effort, I8).
     *
     * @return array<int> betroffene recipe_ids (Kind + transitive Eltern) — für
     *                    gezielte UI-Signale (#511 kosten-aktualisiert)
     */
    public function recomputeAndPropagate(int $recipeId): array
    {
        return $this->recomputeMany([$recipeId]);
    }

    /**
     * V-049 · §3.3 für eine MENGE Rezepte: betroffene Vereinigung einmal, Topo einmal,
     * jedes Rezept **genau einmal** gerechnet.
     *
     * Vier Aufrufer haben in Wahrheit eine Menge (Lead-LA-Wechsel, GP-Ersetzung,
     * Kanal-B-Import, Signal-Fixer) und schleiften bisher `recomputeAndPropagate` in
     * einer `foreach`. Jeder Durchlauf baute die Eltern-BFS neu auf und rechnete jedes
     * *gemeinsame* Eltern-Rezept erneut — quadratisch in der Überlappung (200 Basisrezepte
     * in 5 Gerichten ⇒ diese 5 Gerichte 200-mal, je mit eigener Transaktion,
     * Darreichungs-Nachlauf und Paket-Stale-Marker). Korrekt war das immer (der letzte
     * Lauf gewinnt), nur teuer.
     *
     * `recomputeAndPropagate` ist ab hier der **Ein-Element-Fall** dieser Methode — eine
     * Propagations-Wahrheit, kein zweiter Weg. Reihenfolge und Rückgabe sind für ein
     * Element unverändert (Bestands-Tests belegen das).
     *
     * @param  array<int|string>  $recipeIds
     * @return array<int> betroffene recipe_ids (Start-Menge zuerst, dann transitive Eltern)
     */
    public function recomputeMany(array $recipeIds): array
    {
        // 1. Betroffene Menge sammeln (Starts + alle transitiven Eltern), NOCH nicht rechnen.
        $betroffen = $this->betroffeneRezepteMany($recipeIds);
        if ($betroffen === []) {
            return [];
        }

        // 2. Topologisch ordnen (Kinder vor Eltern) INNERHALB der betroffenen Menge und in
        //    dieser Reihenfolge rechnen. Diamond-sicher (P→Y→X ∧ P→X): sonst läse P ein noch
        //    nicht neu berechnetes Geschwister-Sub und bliebe dauerhaft stale (I8-Härtung).
        //    Über mehrere Starts hinweg ist genau das der Gewinn: die gemeinsamen Eltern
        //    stehen EINMAL in der Ordnung, nicht einmal je Start.
        foreach ($this->topoOrder($betroffen) as $id) {
            try {
                $this->recomputePipeline($id, false);
            } catch (\Throwable $e) {
                Log::warning("Recompute-Propagation: Rezept {$id} fehlgeschlagen — {$e->getMessage()} (I8: Edit nicht geblockt)");
            }
        }

        // K-07 / Doc 15 §M12: Auto-Pakete, die ein neu berechnetes Gericht enthalten,
        // als preis_stale markieren (GP-Preis-Änderung → Baustein-Preis veraltet).
        // Best-effort, außerhalb der Recompute-Transaktion (I8: Edit nicht blocken).
        try {
            $paketSvc = app(PaketService::class);
            foreach ($betroffen as $rid) {
                $paketSvc->markStaleForRecipe((int) $rid);
            }
        } catch (\Throwable $e) {
            Log::warning("K-07 markStaleForRecipe fehlgeschlagen: {$e->getMessage()}");
        }

        try {
            app(PricingCascadeService::class)->recomputeRecipes($betroffen);
        } catch (\Throwable $e) {
            Log::warning("Preis-Kaskade nach Bulk-Recompute fehlgeschlagen: {$e->getMessage()}");
        }

        return $betroffen;
    }

    /**
     * #511: die vom Recompute betroffene Menge (Start-Rezept + alle transitiven
     * Eltern per BFS, PROPAGATION_LIMIT-begrenzt) — OHNE zu rechnen. Der Editor
     * trägt damit das kosten-aktualisiert-Signal gezielt an die Eltern-Cockpits.
     *
     * @return array<int> recipe_ids (Start zuerst)
     */
    public function betroffeneRezepte(int $recipeId): array
    {
        return $this->betroffeneRezepteMany([$recipeId]);
    }

    /**
     * V-049: dieselbe BFS für eine **Menge** Start-Rezepte — eine Query je Ebene statt
     * einer je Ebene UND Start.
     *
     * Das Ergebnis ist mengengleich mit der Vereinigung der Einzel-BFS, nicht nur
     * ähnlich: die gemeinsame BFS vergibt je Knoten die *kleinste* Distanz zu irgendeinem
     * Start. Ist sie ≤ PROPAGATION_LIMIT, hätte auch die Einzel-BFS von genau diesem Start
     * den Knoten erreicht; ist sie größer, erreicht ihn keine. Der Äquivalenz-Riegel dazu
     * steht in `tests/Feature/RecomputeMengeTest.php` (inkl. Kette über die Grenze hinaus).
     *
     * @param  array<int|string>  $recipeIds
     * @return array<int> recipe_ids (Start-Menge zuerst, in Eingabe-Reihenfolge)
     */
    public function betroffeneRezepteMany(array $recipeIds): array
    {
        $besucht = [];
        $ebene = [];
        foreach ($recipeIds as $id) {
            $id = (int) $id;
            if (! isset($besucht[$id])) {
                $besucht[$id] = true;
                $ebene[] = $id;
            }
        }
        if ($ebene === []) {
            return [];
        }

        for ($tiefe = 0; $tiefe < self::PROPAGATION_LIMIT && $ebene !== []; $tiefe++) {
            $eltern = FoodAlchemistRecipeIngredient::whereIn('referenced_recipe_id', $ebene)
                ->whereNull('deleted_at')->distinct()->pluck('recipe_id')
                ->reject(fn ($id) => isset($besucht[$id]))->values()->all();
            foreach ($eltern as $parentId) {
                $besucht[$parentId] = true;
            }
            $ebene = $eltern;
        }

        return array_map('intval', array_keys($besucht));
    }

    /**
     * §3.4: Bulk in topologischer Ordnung (Kahn) — Kinder vor Eltern (A-4: verbindlich).
     *
     * @return array{berechnet: int, reihenfolge_ok: bool}
     *
     * @throws \RuntimeException bei Zyklus (mit beteiligten recipe_ids)
     */
    public function recomputeAll(): array
    {
        $ids = FoodAlchemistRecipe::pluck('id')->all();
        $kanten = FoodAlchemistRecipeIngredient::whereNotNull('referenced_recipe_id')->whereNull('deleted_at')
            ->distinct()->get(['recipe_id', 'referenced_recipe_id']);

        $inDegree = array_fill_keys($ids, 0);
        $parentsVon = [];                                          // sub → [parents]
        foreach ($kanten as $k) {
            // Elter-Rezept nicht im Set (z.B. soft-deleted, aber Zutat-Kante noch live) → Kante
            // KOMPLETT ignorieren. Sonst landet der verwaiste Parent nur in $parentsVon und die
            // Kahn-Schleife unten crasht mit „Undefined array key" (--$inDegree[$parent]).
            if (! isset($inDegree[$k->recipe_id])) {
                continue;
            }
            $inDegree[$k->recipe_id]++;                            // Eltern: je referenziertem DISTINCT Sub +1
            $parentsVon[$k->referenced_recipe_id][] = $k->recipe_id;
        }

        $queue = array_keys(array_filter($inDegree, fn ($d) => $d === 0));
        $order = [];
        while ($queue !== []) {
            $node = array_shift($queue);
            $order[] = $node;
            foreach ($parentsVon[$node] ?? [] as $parent) {
                if (--$inDegree[$parent] === 0) {
                    $queue[] = $parent;
                }
            }
        }
        if (count($order) < count($ids)) {
            $zyklus = array_keys(array_filter($inDegree, fn ($d) => $d > 0));
            throw new \RuntimeException('Zyklus im Sub-Rezept-Graph — beteiligte recipe_ids: '.implode(', ', $zyklus));
        }

        foreach ($order as $id) {
            $this->recomputePipeline($id, false);
        }

        FoodAlchemistRecipe::whereIn('id', $order)->pluck('team_id')->filter()->unique()
            ->each(function ($teamId) {
                if (($team = Team::find($teamId)) !== null) {
                    app(PricingCascadeService::class)->recomputeTeam($team);
                }
            });

        return ['berechnet' => count($order), 'reihenfolge_ok' => true];
    }

    /**
     * Kahn-Topo (Kinder vor Eltern) über eine TEILMENGE der Rezepte — nur Kanten
     * innerhalb der Menge zählen. Für den Inkrement-Propagations-Pfad, damit auch
     * Diamond-Abhängigkeiten korrekt geordnet neu berechnet werden. Bei (eigentlich
     * durch pruefeVerknuepfung ausgeschlossenem) Zyklus: Rest in Eingabereihenfolge anhängen.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function topoOrder(array $ids): array
    {
        $set = array_flip($ids);
        $kanten = FoodAlchemistRecipeIngredient::whereIn('recipe_id', $ids)
            ->whereNotNull('referenced_recipe_id')->whereNull('deleted_at')
            ->distinct()->get(['recipe_id', 'referenced_recipe_id']);

        $inDegree = array_fill_keys($ids, 0);
        $parentsVon = [];
        foreach ($kanten as $k) {
            if (isset($set[$k->referenced_recipe_id]) && isset($set[$k->recipe_id])) {
                $inDegree[$k->recipe_id]++;
                $parentsVon[$k->referenced_recipe_id][] = $k->recipe_id;
            }
        }

        $queue = array_keys(array_filter($inDegree, fn ($d) => $d === 0));
        $order = [];
        while ($queue !== []) {
            $node = array_shift($queue);
            $order[] = $node;
            foreach ($parentsVon[$node] ?? [] as $parent) {
                if (--$inDegree[$parent] === 0) {
                    $queue[] = $parent;
                }
            }
        }
        if (count($order) < count($ids)) {                          // Zyklus-Fallback: Rest anhängen
            foreach ($ids as $id) {
                if (! in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
        }

        return $order;
    }

    /**
     * §3.5: Verknüpfungs-Guards parent→sub — Selbstreferenz, Zyklus, Tiefe > 3 BLOCKT (A-5-Ziel).
     *
     * @return array{erlaubt: bool, grund: ?string, projizierte_tiefe: int}
     */
    public function pruefeVerknuepfung(int $parentId, int $subId): array
    {
        if ($parentId === $subId) {
            return ['erlaubt' => false, 'grund' => 'Selbstreferenz', 'projizierte_tiefe' => 0];
        }
        // BFS von sub abwärts: wird parent erreicht ⇒ Zyklus
        $ebene = [$subId];
        $besucht = [$subId => true];
        while ($ebene !== []) {
            $kinder = FoodAlchemistRecipeIngredient::whereIn('recipe_id', $ebene)
                ->whereNotNull('referenced_recipe_id')->whereNull('deleted_at')
                ->distinct()->pluck('referenced_recipe_id')->reject(fn ($id) => isset($besucht[$id]))->values()->all();
            if (in_array($parentId, $kinder, true)) {
                return ['erlaubt' => false, 'grund' => 'Zyklus', 'projizierte_tiefe' => 0];
            }
            foreach ($kinder as $k) {
                $besucht[$k] = true;
            }
            $ebene = $kinder;
        }

        // Projizierte Tiefe bleibt informativ (Ahnen des Parents + Sub-Baum), aber sie
        // BLOCKT nicht mehr (A-5 2026-08-03 gelockert): tiefe Menü-/Komposit-Ketten sind
        // legitim. Verworfen wird nur bei Selbstreferenz/Zyklus (oben geprüft).
        $tiefe = max(
            $this->ahnenHoehe($parentId) + $this->subtreeTiefe($subId),
            $this->subtreeTiefe($parentId),
        );

        return ['erlaubt' => true, 'grund' => null, 'projizierte_tiefe' => $tiefe];
    }

    /** Längster Aufwärts-Pfad bis zur Wurzel, inkl. Start-Rezept (A→B→C: C ⇒ 3). */
    private function ahnenHoehe(int $recipeId): int
    {
        $hoehe = 1;
        $ebene = [$recipeId];
        $besucht = [$recipeId => true];
        while ($ebene !== []) {                                    // Visited-Set garantiert Terminierung (kein Tiefen-Cap)
            $eltern = FoodAlchemistRecipeIngredient::whereIn('referenced_recipe_id', $ebene)
                ->whereNull('deleted_at')->distinct()->pluck('recipe_id')
                ->reject(fn ($id) => isset($besucht[$id]))->values()->all();
            if ($eltern === []) {
                break;
            }
            foreach ($eltern as $e) {
                $besucht[$e] = true;
            }
            $ebene = $eltern;
            $hoehe++;
        }

        return $hoehe;
    }

    /** Tiefe des Sub-Baums ab Rezept (Blatt = 1). */
    public function subtreeTiefe(int $recipeId): int
    {
        $tiefe = 1;
        $ebene = [$recipeId];
        $besucht = [$recipeId => true];
        while ($ebene !== []) {                                    // Visited-Set garantiert Terminierung (kein Tiefen-Cap)
            $kinder = FoodAlchemistRecipeIngredient::whereIn('recipe_id', $ebene)
                ->whereNotNull('referenced_recipe_id')->whereNull('deleted_at')
                ->distinct()->pluck('referenced_recipe_id')->reject(fn ($id) => isset($besucht[$id]))->values()->all();
            if ($kinder === []) {
                break;
            }
            foreach ($kinder as $k) {
                $besucht[$k] = true;
            }
            $ebene = $kinder;
            $tiefe++;
        }

        return $tiefe;
    }

    // ── 1. Yield + Zähler (§3.1) ────────────────────────────────────────

    private function yieldUndZaehler(FoodAlchemistRecipe $recipe, Collection $zutaten): void
    {
        $yieldG = 0.0;
        $nTotal = 0;
        $nUngemappt = 0;

        foreach ($zutaten as $z) {
            $nTotal++;
            if (! $this->istGemappt($z)) {
                $nUngemappt++;
            }
            if ($z->is_optional || $z->unit?->slug === 'qs') {
                continue;                                          // Yield-Beitrag 0 (T2)
            }
            // Ungemappte tragen zum Yield bei (Masse ist mapping-unabhängig, §3.1)
            $yieldG += $this->mengeAvg($z) * $this->grammFaktor($z)
                * (1 - $this->effektiverPutzverlust($z) / 100)       // A-1: multiplikativ (Entscheid)
                * (1 - $this->effektiverGarverlust($z) / 100);
        }

        $recipe->yield_kg = $yieldG > 0 ? round($yieldG / 1000, 3) : null;
        $recipe->n_ingredients_total = $nTotal;
        $recipe->n_ingredients_unmapped = $nUngemappt;
        // GL-01 §4.4 — Allergen-Konfidenz = schwächstes Glied aus:
        //   (a) Mapping-Vollständigkeit (leer → unknown, ungemappt → low; F7.1),
        //   (b) Allergen-Konfidenz der gemappten GPs — die QUELLE der Allergen-Werte,
        //       NICHT die Match-Methode (User 2026-07-31: „Daten kommen aus den GP-Allergenen"),
        //   (c) Sub-Rezept-Allergen-Konfidenz (§7 rekursiv, schwächstes Glied).
        $mappingRang = match (true) {
            $nTotal === 0 => 0,
            $this->hatUngemappteRelevante($zutaten) => 1,      // F7.1 (verfeinert): nur nicht-optionale ungemappte
            default => 3,
        };
        $recipe->allergens_confidence = self::RANG_KONF[min(
            $mappingRang,
            $this->gpKonfidenzRang($zutaten),
            $this->subKonfidenzRang($zutaten, 'allergens_confidence'),
        )];
    }

    /** Verlust-Kaskade (GL-02): Zutat-Wert → GP-Default → Team-WG-Default → 0. */
    private function effektiverGarverlust(FoodAlchemistRecipeIngredient $z): float
    {
        if ($z->cooking_loss_pct !== null) {
            return (float) $z->cooking_loss_pct;
        }
        if ($z->gp?->cooking_loss_default_pct !== null) {
            return (float) $z->gp->cooking_loss_default_pct;
        }

        return $this->teamVerlustDefault('garverlust', $z->gp?->commodity_group_code);
    }

    private function effektiverPutzverlust(FoodAlchemistRecipeIngredient $z): float
    {
        if ($z->trimming_loss_pct !== null) {
            return (float) $z->trimming_loss_pct;
        }
        if ($z->gp?->trimming_loss_default_pct !== null) {
            return (float) $z->gp->trimming_loss_default_pct;
        }

        return $this->teamVerlustDefault('putzverlust', $z->gp?->commodity_group_code);
    }

    /** Team-WG-Default (je Lauf gecacht); 0 wenn kein Team / kein Default hinterlegt. */
    private function teamVerlustDefault(string $art, ?string $wgCode): float
    {
        if ($this->recomputeTeam === null) {
            return 0.0;
        }
        $key = $wgCode ?? '*';
        if ($art === 'putzverlust') {
            if (! array_key_exists($key, $this->putzverlustWgCache)) {
                $this->putzverlustWgCache[$key] = app(TeamSettingsService::class)->putzverlustDefault($this->recomputeTeam, $wgCode) ?? 0.0;
            }

            return $this->putzverlustWgCache[$key];
        }
        if (! array_key_exists($key, $this->garverlustWgCache)) {
            $this->garverlustWgCache[$key] = app(TeamSettingsService::class)->garverlustDefault($this->recomputeTeam, $wgCode) ?? 0.0;
        }

        return $this->garverlustWgCache[$key];
    }

    // ── 2. Allergene (GL-01) ────────────────────────────────────────────

    private function allergene(FoodAlchemistRecipe $recipe, Collection $zutaten): void
    {
        $felder = FoodAlchemistGp::ALLERGEN_FIELDS;

        // §7 F7.1/F7.3 abschliessend: NUR eine ungemappte Pflicht-Zutat (bzw. das leere Rezept)
        // löscht das Profil. Eine niedrige Sub-/GP-Konfidenz deckelt allergens_confidence
        // (yieldUndZaehler), verwirft die Werte aber NICHT (F7.5) — sonst versteckt ein einziges
        // unsicheres Sub die bekannten Allergene aller übrigen Komponenten.
        if ($this->hatUngemappteRelevante($zutaten)) {                // F7.1: nur nicht-optionale ungemappte
            foreach ($felder as $f) {
                $recipe->{"allergen_{$f}"} = 'unbekannt';
            }
            $recipe->spec_is_gluten_free = null;
            $recipe->allergens_aggregated_at = now();              // Invariante 6 (Ziel: mitschreiben)

            return;
        }

        $raenge = array_fill_keys($felder, null);
        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            if ($z->gp_id !== null && $z->gp !== null) {           // GP-Pfad gewinnt
                $werte = $this->gpAggregate->allergene($z->gp);    // Prio-Kette 4.3 (Override>Mutter>LA-MAX)
                foreach ($felder as $f) {
                    if ($werte[$f]['source'] === 'keine') {
                        continue;                                  // kein Beitrag (NULL)
                    }
                    $rang = $werte[$f]['value']->rank();
                    $raenge[$f] = max($raenge[$f] ?? 0, $rang);
                }
            } elseif ($z->referencedRecipe !== null) {             // Sub-Pfad
                foreach ($felder as $f) {
                    $wert = AllergenValue::tryFrom((string) $z->referencedRecipe->{"allergen_{$f}"});
                    if ($wert === null || $wert === AllergenValue::Unbekannt) {
                        continue;                                  // unbekannt ⇒ kein Beitrag
                    }
                    $raenge[$f] = max($raenge[$f] ?? 0, $wert->rank());
                }
            }
        }

        foreach ($felder as $f) {
            $recipe->{"allergen_{$f}"} = $this->rangZuText($raenge[$f]);
        }
        $recipe->spec_is_gluten_free = match ($recipe->allergen_gluten) {
            'nicht_enthalten' => true,
            'enthalten', 'spuren' => false,
            default => null,
        };
        $recipe->allergens_aggregated_at = now();
    }

    // ── 3. Zusatzstoffe (GL-09) ─────────────────────────────────────────

    private function zusatzstoffe(FoodAlchemistRecipe $recipe, Collection $zutaten): void
    {
        $stoffe = array_keys(FoodAlchemistItemDeclaration::STOFFE);

        // §7 F7.1/F7.5 — identisch zu allergene(): unsicheres Sub deckelt, löscht nicht.
        if ($this->hatUngemappteRelevante($zutaten)) {                // F7.1: nur nicht-optionale ungemappte → alle 18 NULL
            foreach ($stoffe as $s) {
                $recipe->{"additive_{$s}"} = null;
            }
            $recipe->additive_aggregated_at = now();

            return;
        }

        $max = array_fill_keys($stoffe, null);
        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            $beitraege = null;
            if ($z->gp_id !== null && $z->gp !== null) {
                $beitraege = $this->gpAggregate->zusatzstoffe($z->gp);
            } elseif ($z->referencedRecipe !== null) {
                $beitraege = collect($stoffe)->mapWithKeys(fn ($s) => [$s => $z->referencedRecipe->{"additive_{$s}"}])->all();
            }
            foreach ($stoffe as $s) {
                $wert = $beitraege[$s] ?? null;
                if ($wert !== null) {
                    $max[$s] = max($max[$s] ?? 0, (int) $wert);    // SQL-MAX-Semantik: NULL ignoriert
                }
            }
        }
        foreach ($stoffe as $s) {
            $recipe->{"additive_{$s}"} = $max[$s];
        }
        $recipe->additive_aggregated_at = now();
    }

    // ── 4. Kosten (§3.2, T3) ────────────────────────────────────────────

    /**
     * Zeilen-Kosten je Zutat (Panel M4-05 „EK je Zeile") — exakt dieselbe
     * T3-Kaskade wie der Recompute, eine Regel-Stelle.
     *
     * @return array<int, ?float> [ingredient_id => Kosten € | null (unpriced/gefiltert)]
     */
    public function zeilenKosten(FoodAlchemistRecipe $recipe, ?Team $team = null): array
    {
        $this->recomputeTeam = $team;
        $this->laCache = [];
        $this->leadPrefCache = [];
        $zutaten = $recipe->ingredients->filter(fn ($z) => $z->match_method !== MatchMethod::Ignored);

        $out = [];
        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            [$kosten, $priced] = $this->zutatKosten($z);
            $out[$z->id] = $priced ? round($kosten, 2) : null;
        }

        return $out;
    }

    /**
     * Zeilen-Kosten + verlustbereinigte Masse je Zutat (Darreichungs-Deltas,
     * Umbau-Spec §1.3): Kosten = T3-Kaskade wie zeilenKosten(), Masse = derselbe
     * Raum wie yield (mengeAvg × grammFaktor × Putz-/Garverlust) — damit der
     * Delta-Mischpreis konsistent zur EK/kg-Referenz des Rezepts rechnet.
     *
     * @return array<int, array{kosten: ?float, masse_g: float}>
     */
    public function zeilenKostenUndMassen(FoodAlchemistRecipe $recipe, ?Team $team = null): array
    {
        $this->recomputeTeam = $team;
        $this->laCache = [];
        $this->leadPrefCache = [];
        $zutaten = $recipe->ingredients->filter(fn ($z) => $z->match_method !== MatchMethod::Ignored);

        $out = [];
        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            [$kosten, $priced] = $this->zutatKosten($z);
            $masseG = ($z->is_optional || $z->unit?->slug === 'qs') ? 0.0
                : $this->mengeAvg($z) * $this->grammFaktor($z)
                    * (1 - $this->effektiverPutzverlust($z) / 100)
                    * (1 - $this->effektiverGarverlust($z) / 100);
            $out[$z->id] = ['kosten' => $priced ? $kosten : null, 'masse_g' => $masseG];
        }

        return $out;
    }

    private function kosten(FoodAlchemistRecipe $recipe, Collection $zutaten): void
    {
        $ekTotal = 0.0;
        $nKosten = 0;
        $nPriced = 0;
        $basen = [];

        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            $nKosten++;
            [$kosten, $priced, $basis] = $this->zutatKosten($z);
            if ($priced) {
                $nPriced++;
                // Nur bepreiste Zeilen wiegen in der Basis — eine Lücke trägt keinen Cent
                // zum EK bei und darf die Aussage über die bepreiste Hälfte nicht verwässern.
                $basen[] = $basis ?? EkPriceBasis::Unknown;
            }
            $ekTotal += $kosten;
        }

        $recipe->ek_n_ingredients_total = $nKosten;
        $recipe->ek_n_ingredients_priced = $nPriced;
        // V-014: woher die Zahl kommt, wird mitgeführt statt vergessen (schwächstes Glied).
        $recipe->ek_price_basis = EkPriceBasis::aggregiere($basen);
        $recipe->ek_total_eur = $ekTotal > 0 ? round($ekTotal, 2) : null;
        // I7: Nenner = bereits GERUNDETES yield (Kalkulationswert = COALESCE manual, auto — A-3)
        $yield = $recipe->yield_kg_manual !== null ? (float) $recipe->yield_kg_manual : ($recipe->yield_kg !== null ? (float) $recipe->yield_kg : null);
        $recipe->ek_per_kg_eur = ($ekTotal > 0 && $yield !== null && $yield > 0)
            ? round($ekTotal / $yield, 2) : null;
    }

    // ── 5. Nährwerte (GL-08 — KEIN F7.1-Guard, Rohmasse-Basis) ──────────

    private function naehrwerte(FoodAlchemistRecipe $recipe, Collection $zutaten): void
    {
        $relevant = $zutaten->filter(fn ($z) => ! $z->is_optional
            && $z->unit?->slug !== 'qs'
            && $this->istGemappt($z));

        $totalG = 0.0;
        $summen = ['kcal' => 0.0, 'protein' => 0.0, 'fat' => 0.0, 'carbs' => 0.0, 'salt' => 0.0, 'sugar' => 0.0, 'satfat' => 0.0];
        $nMapped = 0;

        foreach ($relevant as $z) {
            // GL-08 §4.2 verbatim: NUR g/ml-Faktor — bewusst KEIN stk-Fallback (Lücke dokumentiert)
            $mengeG = $this->mengeAvg($z) * (float) ($z->unit?->default_in_g ?? $z->unit?->default_in_ml ?? 0);

            $werte = null;
            if ($z->referencedRecipe !== null) {                   // Sub-Pfad gewinnt (GL-08 4.1)
                $sub = $z->referencedRecipe;
                if ($sub->nutri_kcal_per_100g !== null) {
                    $werte = [
                        'kcal' => (float) $sub->nutri_kcal_per_100g,
                        'protein' => (float) ($sub->nutri_protein_g_per_100g ?? 0),
                        'fat' => (float) ($sub->nutri_fat_g_per_100g ?? 0),
                        'carbs' => (float) ($sub->nutri_carbs_g_per_100g ?? 0),
                        'salt' => (float) ($sub->nutri_salt_g_per_100g ?? 0),
                        'sugar' => (float) ($sub->nutri_sugar_g_per_100g ?? 0),
                        'satfat' => (float) ($sub->nutri_saturated_fat_g_per_100g ?? 0),
                    ];
                }
            } elseif ($z->gp !== null) {
                // GL-08-Verfeinerung (Salz-Fall): KURATIERTE GP-Werte (nutri_source='manual')
                // dürfen LA-Lücken füllen — z.B. Speisesalz-LAs mit sodium, aber ohne kcal
                // (Leit-Indikator schlug fehl → Salz trug 0 bei). KI-Schätzungen bleiben
                // weiterhin Panel-only und verfälschen keine Rezept-Nährwerte.
                $n = $this->gpAggregate->naehrwerte($z->gp, mitKiFallback: $z->gp->nutri_source === 'manual');
                if ($n['energy_kcal']['avg'] !== null) {           // kcal = Leit-Indikator
                    $werte = [
                        'kcal' => $n['energy_kcal']['avg'],
                        'protein' => $n['protein']['avg'] ?? 0.0,
                        'fat' => $n['fat']['avg'] ?? 0.0,
                        'carbs' => $n['carbs_absorbable']['avg'] ?? 0.0,
                        'salt' => $n['salt_g']['avg'] ?? 0.0,      // sodium×0.0025 (GL-08 §4.2)
                        'sugar' => $n['sugar']['avg'] ?? 0.0,
                        'satfat' => $n['saturated_fat']['avg'] ?? 0.0,
                    ];
                }
            }

            $totalG += $mengeG;                                    // auch unmapped „verdünnen" (Invariante 4)
            if ($mengeG > 0 && $werte !== null) {
                $nMapped++;
                foreach ($summen as $k => $v) {
                    $summen[$k] += ($werte[$k] ?? 0.0) * $mengeG / 100;  // fehlender Wert ⇒ 0-Anteil (GT-02)
                }
            }
        }

        $nTotal = $relevant->count();
        if ($nMapped > 0 && $totalG > 0) {
            $recipe->nutri_kcal_per_100g = round($summen['kcal'] * 100 / $totalG, 1);
            $recipe->nutri_protein_g_per_100g = round($summen['protein'] * 100 / $totalG, 2);
            $recipe->nutri_fat_g_per_100g = round($summen['fat'] * 100 / $totalG, 2);
            $recipe->nutri_carbs_g_per_100g = round($summen['carbs'] * 100 / $totalG, 2);
            $recipe->nutri_salt_g_per_100g = round($summen['salt'] * 100 / $totalG, 3);
            $recipe->nutri_sugar_g_per_100g = round($summen['sugar'] * 100 / $totalG, 2);
            $recipe->nutri_saturated_fat_g_per_100g = round($summen['satfat'] * 100 / $totalG, 2);
        } else {
            $recipe->nutri_kcal_per_100g = null;
            $recipe->nutri_protein_g_per_100g = null;
            $recipe->nutri_fat_g_per_100g = null;
            $recipe->nutri_carbs_g_per_100g = null;
            $recipe->nutri_salt_g_per_100g = null;
            $recipe->nutri_sugar_g_per_100g = null;
            $recipe->nutri_saturated_fat_g_per_100g = null;
        }
        $recipe->nutri_n_ingredients_total = $nTotal;
        $recipe->nutri_n_ingredients_mapped = $nMapped;
        $recipe->nutri_confidence = match (true) {                  // GL-08 §4.3 (erste zutreffende)
            $nTotal === 0, $nMapped === 0 => 'unknown',
            $nMapped === $nTotal => 'high',
            $nMapped / $nTotal >= 0.8 => 'medium',
            default => 'low',
        };
        $recipe->nutri_aggregated_at = now();
    }

    // ── 6. Spec-Flags (Diät/Herkunft, dynamisch über alle Ebenen) ───────
    //
    // Tags sind DYNAMISCH aus der Rezeptur (User-Prinzip 2026-07-06): kommt ein
    // nicht-veganer GP in ein Gericht, ist es nicht mehr vegan. Quelle = GP-Tags
    // (FoodAlchemistGp.tag_is_*); Sub-Rezepte liefern ihre bereits berechneten
    // spec_is_*-Werte (topologische Ordnung garantiert Kinder-vor-Eltern).
    //
    // Zwei Logiken:
    //  - ZUSICHERUNG (vegan/vegetarisch/halal/laktosefrei): AND mit NULL-Propagation —
    //    1 nur wenn ALLE bekannten Zutaten zusichern und NICHTS unbekannt ist;
    //    0 sobald eine verletzt; sonst NULL (unbekannt).
    //  - WARNUNG (enthält Schwein/Rind): OR — 1 sobald eine Zutat es trägt;
    //    0 nur wenn alles bekannt und nichts trägt; sonst NULL.
    // spec_is_gluten_free bleibt normativ aus der Allergen-Kette (allergene()).
    private const SPEC_ASSURE = ['is_vegan', 'is_vegetarian', 'is_halal', 'is_lactose_free'];

    private const SPEC_WARN = ['contains_pork', 'contains_beef'];

    private function specFlags(FoodAlchemistRecipe $recipe, Collection $zutaten): void
    {
        $felder = [...self::SPEC_ASSURE, ...self::SPEC_WARN];
        /** @var array<string, list<bool|null>> $werte */
        $werte = array_fill_keys($felder, []);
        $nTotal = 0;
        $nMapped = 0;

        foreach ($zutaten as $z) {
            if ($z->is_optional) {
                continue;
            }
            $nTotal++;
            if ($z->gp_id !== null && $z->gp !== null) {
                $nMapped++;
                foreach ($felder as $f) {
                    $werte[$f][] = $this->boolOrNull($z->gp->{"tag_{$f}"});
                }
            } elseif ($z->referencedRecipe !== null) {
                $nMapped++;
                foreach ($felder as $f) {
                    $werte[$f][] = $this->boolOrNull($z->referencedRecipe->{"spec_{$f}"});
                }
            } else {                                               // ungemappt ⇒ NULL-Beitrag
                foreach ($felder as $f) {
                    $werte[$f][] = null;
                }
            }
        }

        foreach (self::SPEC_ASSURE as $f) {
            $recipe->{"spec_{$f}"} = $this->mergeAssure($werte[$f]);
        }
        foreach (self::SPEC_WARN as $f) {
            $recipe->{"spec_{$f}"} = $this->mergeWarn($werte[$f]);
        }

        $recipe->spec_n_total = $nTotal;
        $recipe->spec_n_mapped = $nMapped;
        $ownRang = match (true) {
            $nTotal === 0, $nMapped === 0 => 1,
            $nMapped === $nTotal => 3,
            $nMapped / $nTotal >= 0.8 => 2,
            default => 1,
        };
        // §7 rekursiv: schwächstes Glied unter den Sub-Rezepten; spec kennt kein 'unknown' → min 'low'.
        $recipe->spec_confidence = self::RANG_KONF[max(1, min($ownRang, $this->subKonfidenzRang($zutaten, 'spec_confidence')))];
        $recipe->spec_aggregated_at = now();
    }

    private function boolOrNull(mixed $v): ?bool
    {
        return $v === null ? null : (bool) $v;
    }

    /** Zusicherung: 0 wenn eine verletzt, NULL wenn eine unbekannt, sonst 1. */
    private function mergeAssure(array $werte): ?bool
    {
        if (in_array(false, $werte, true)) {
            return false;
        }
        if (in_array(null, $werte, true)) {
            return null;
        }

        return true;
    }

    /** Warnung: 1 wenn eine trägt, NULL wenn eine unbekannt, sonst 0. */
    private function mergeWarn(array $werte): ?bool
    {
        if (in_array(true, $werte, true)) {
            return true;
        }
        if (in_array(null, $werte, true)) {
            return null;
        }

        return false;
    }

    /**
     * T3-Kaskade für EINE Zutat: [kosten €, priced?, basis|null].
     *
     * Die dritte Stelle ist V-014 (Spec 22 H2d): sie sagt, aus welchem Zweig der Kaskade
     * die Zahl kommt — gewählter Artikel oder Lieferanten-Durchschnitt. Sie wird DORT
     * ermittelt, wo die Kaskade ohnehin entscheidet, statt hinterher rekonstruiert zu
     * werden (rekonstruierbar ist sie nicht: derselbe Betrag kann aus beiden Zweigen
     * stammen). Die zwei Alt-Aufrufer, die nur `[$kosten, $priced]` auspacken, bleiben
     * gültig — PHP-Destructuring ignoriert überzählige Elemente.
     */
    private function zutatKosten(FoodAlchemistRecipeIngredient $z): array
    {
        $mengeAvg = $this->mengeAvg($z);
        $mengeG = $mengeAvg * $this->grammFaktor($z);

        $gp = $z->gp;
        [$pG, $bG] = $gp !== null ? $this->preisProGrammMitBasis($gp) : [null, null];
        [$pStk, $bStk] = $gp !== null ? $this->preisProStueckMitBasis($gp) : [null, null];
        $pSub = $z->referencedRecipe?->ek_per_kg_eur !== null
            ? ((float) $z->referencedRecipe->ek_per_kg_eur) / 1000 : null;
        // Vererbung statt Neu-Erfinden: das Eltern-Rezept kennt vom Sub nur den €/kg.
        // Trägt das Sub (noch) keine Basis, ist die Herkunft unbekannt — nicht `avg`.
        $bSub = $pSub !== null
            ? ($z->referencedRecipe->ek_price_basis ?? EkPriceBasis::Unknown)
            : null;

        if ($z->unit?->dimension === 'count') {                 // T3 Zeile count
            if ($pStk !== null) {
                return [$mengeAvg * $pStk, true, $bStk];
            }
            if ($mengeG > 0 && $pG !== null) {                     // count→mass-Brücke
                return [$mengeG * $pG, true, $bG];
            }
            if ($mengeG > 0 && $pSub !== null) {                   // Sub-Rezept per Stück: g/Stück (s. grammFaktor) × €/g des Sub
                return [$mengeG * $pSub, true, $bSub];
            }

            return [0.0, false, null];
        }

        // mass/volume/pinch/piece
        $stkDefaultG = $gp?->piece_default_g !== null ? (float) $gp->piece_default_g : null;
        [$source, $basis] = match (true) {
            $pG !== null => [$pG, $bG],
            $pStk !== null && $stkDefaultG > 0 => [$pStk / $stkDefaultG, $bStk],   // Stk→g-Brücke
            $pSub !== null => [$pSub, $bSub],
            default => [null, null],
        };
        if ($source !== null && $mengeG > 0) {                     // T2: qs (Faktor 0) bleibt unpriced
            return [$mengeG * $source, true, $basis];
        }

        return [0.0, false, null];
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** GL-01/02/09-Filter: nicht optional + gemappt (GP per I5-Gate ODER Sub-Ref). ignored ist schon raus. */
    private function aggregationsZutaten(Collection $zutaten): Collection
    {
        return $zutaten->filter(fn ($z) => ! $z->is_optional && $this->istGemappt($z));
    }

    /**
     * F7.1 (verfeinert 2026-08-22, User-Entscheid): löst der Allergen-/Zusatzstoff-
     * „unbekannt"-Guard aus? NUR nicht-optionale ungemappte Zutaten zählen — optionale
     * sind aus der ALL-MAXIMAL-Aggregation (aggregationsZutaten) ohnehin ausgeschlossen,
     * also darf eine ungemappte optionale Garnitur das bekannte Profil der Pflicht-
     * Zutaten nicht auf „unbekannt" verwerfen. n_ingredients_unmapped bleibt der
     * Gesamt-Zähler (Anzeige); dieser Guard ist der Aggregations-Scope-Spiegel.
     */
    private function hatUngemappteRelevante(Collection $zutaten): bool
    {
        return $zutaten->contains(fn ($z) => ! $z->is_optional && ! $this->istGemappt($z));
    }

    /** I5: gemini_proposed zählt nur mit confidence ≥ 0.85 als gemappt. */
    private function istGemappt(FoodAlchemistRecipeIngredient $z): bool
    {
        if ($z->referenced_recipe_id !== null) {
            return true;
        }
        if ($z->gp_id === null) {
            return false;
        }

        return $z->match_method !== MatchMethod::GeminiProposed
            || (float) ($z->match_confidence ?? 0) >= 0.85;
    }

    private const KONF_RANG = ['unknown' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

    private const RANG_KONF = [0 => 'unknown', 1 => 'low', 2 => 'medium', 3 => 'high'];

    /**
     * §7 „kein false-confident", rekursiv: schwächstes Glied unter den aggregierten
     * (nicht-optionalen, gemappten) Sub-Rezepten im gegebenen Konfidenz-Feld
     * ('allergens_confidence' | 'spec_confidence'). 3 (high) wenn kein Sub referenziert wird.
     * Ein unsicheres/unberechnetes Sub deckelt auf 'low' (1) — NICHT auf 'unknown', denn das
     * Eltern-Rezept hat über seine übrigen Zutaten reale (wenn auch schwache) Info; 'unknown'
     * bleibt dem völlig leeren Rezept (nTotal===0) vorbehalten.
     */
    private function subKonfidenzRang(Collection $zutaten, string $feld): int
    {
        $rang = 3;
        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            if ($z->referencedRecipe === null) {
                continue;
            }
            $rang = min($rang, max(1, self::KONF_RANG[$z->referencedRecipe->{$feld}] ?? 0));
        }

        return $rang;
    }

    /**
     * GL-01 §4.4 / §7 F7.4: schwächste Allergen-Konfidenz unter den gemappten GP-Zutaten (nicht
     * Sub-Rezepte). Quelle sind die GP-Allergene selbst, nicht die Match-Methode.
     *
     * LIVE aus dem LA-Profil (GpAggregateService::allergenKonfidenz), NICHT aus der Spalte
     * gps.allergens_confidence: die füllt allein `foodalchemist:gp-allergen-backfill`, der in
     * keinem Scheduler steht — sie war dadurch flächendeckend NULL und hat über `null → low`
     * jedes Rezept gedeckelt. Die WERT-Kaskade LA → GP ist aus demselben Grund on-read; die
     * Konfidenz folgt jetzt demselben Muster und heilt mit, sobald eine LA korrigiert wird.
     *
     * 3 (high), wenn keine GP-Zutat beiträgt (dann führen Mapping-Guard + Sub-Rezepte).
     */
    private function gpKonfidenzRang(Collection $zutaten): int
    {
        $rang = 3;
        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            if ($z->gp_id === null || $z->gp === null) {
                continue;                                          // Sub-Rezepte: siehe subKonfidenzRang
            }
            $konf = $this->gpAggregate->allergenKonfidenz($z->gp)['confidence'];
            $rang = min($rang, GpAggregateService::ALLERGEN_KONF_RANG[$konf] ?? 1);
        }

        return $rang;
    }

    /** I6 / F6.4: Mittelwert bei Mengen-Bereich. */
    private function mengeAvg(FoodAlchemistRecipeIngredient $z): float
    {
        return $z->quantity_max !== null
            ? ((float) $z->quantity + (float) $z->quantity_max) / 2
            : (float) $z->quantity;
    }

    /** T1-Kaskade (A-2-Ziel: für Yield UND Kosten identisch). */
    private function grammFaktor(FoodAlchemistRecipeIngredient $z): float
    {
        $unit = $z->unit;
        // Sub-Rezept per Stück (count): IMMER über den eigenen Stück-Ertrag (Yield ÷ yield_pieces),
        // auch wenn die Einheit einen generischen g/Stück-Default trägt. Bsp Asia-Suppe: 4,579 kg / 100 ⇒ 45,8 g.
        if ($unit?->dimension === 'count' && $z->referenced_recipe_id !== null) {
            $sub = $z->referencedRecipe;
            $ertrag = $sub?->yield_pieces !== null ? (float) $sub->yield_pieces : 0.0;
            if ($ertrag > 0 && $sub?->yield_kg !== null) {
                return (float) $sub->yield_kg * 1000 / $ertrag;
            }
        }
        if ($unit?->default_in_g !== null) {
            return (float) $unit->default_in_g;
        }
        if ($unit?->default_in_ml !== null) {
            return (float) $unit->default_in_ml;                // Dichte 1.0 (Wasser)
        }
        if ($unit?->dimension === 'count' && $z->gp_id !== null) {
            $eintrag = DB::table('foodalchemist_gp_count_unit_defaults')
                ->where('gp_id', $z->gp_id)->where('unit_vocab_id', $z->unit_vocab_id)
                ->whereNull('deleted_at')->value('default_g');
            if ($eintrag !== null) {
                return (float) $eintrag;                           // T1 Zeile 3 (Zehe 5 g / Knolle 40 g)
            }
            if ($z->gp?->piece_default_g !== null) {
                return (float) $z->gp->piece_default_g;              // T1 Zeile 4
            }
        }

        return 0.0;                                                // T1 Zeile 5: kein Beitrag
    }

    /** P-8-Picker (M4-08): €/g fürs Client-Live-Rechnen — dieselbe T3-Quelle. */
    public function preisProGrammPublic(FoodAlchemistGp $gp, ?Team $team = null): ?float
    {
        $this->recomputeTeam = $team;
        $this->laCache = [];
        $this->leadPrefCache = [];

        return $this->preisProGrammFuer($gp);
    }

    /**
     * Preisvarianten für mehrere GPs in konstant vielen Abfragen. Der Zutateneditor braucht
     * dieselbe team-bewusste Lead-Wahrheit wie das Recompute, zusätzlich aber Minimum und Ø
     * für den Vergleich. Vorher setzte `preisProGrammPublic()` seine Caches je Zeile zurück
     * und `GpService::lasForGp()` lud Artikel samt Aktivpreis nochmals je GP (N+1 beim Öffnen).
     *
     * @param  Collection<int, FoodAlchemistGp>  $gps
     * @return array<int, array{lead: ?float, min: ?float, avg: ?float}>
     */
    public function preisVariantenProGrammPublic(Collection $gps, ?Team $team = null): array
    {
        $gps = $gps->filter()->unique('id')->values();
        if ($gps->isEmpty()) {
            return [];
        }

        $this->recomputeTeam = $team;
        $this->laCache = [];
        $this->leadPrefCache = [];
        $gpIds = $gps->pluck('id')->map(fn ($id) => (int) $id)->all();

        $las = FoodAlchemistSupplierItem::query()
            ->when($team, fn ($q) => $q->visibleToTeam($team))
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->whereIn('s.gp_id', $gpIds)->whereNull('s.deleted_at')
            ->select('foodalchemist_supplier_items.*')
            ->addSelect('s.gp_id AS _preis_gp_id')
            ->selectSub($this->preise->activePriceSubquery()->toBase(), 'aktiver_preis')
            ->get()
            ->groupBy(fn ($la) => (int) $la->_preis_gp_id);

        foreach ($gpIds as $gpId) {
            $this->laCache[$gpId] = $las->get($gpId, collect())->values();
        }

        if ($team !== null) {
            $prefs = FoodAlchemistGpLaPreference::query()
                ->where('team_id', $team->id)->whereIn('gp_id', $gpIds)
                ->get(['gp_id', 'supplier_item_id', 'gepinnt', 'locked'])
                ->groupBy('gp_id');
            foreach ($gpIds as $gpId) {
                $this->leadPrefCache[$team->id.':'.$gpId] = $prefs->get($gpId, collect())
                    ->mapWithKeys(fn ($r) => [(int) $r->supplier_item_id => [
                        'gepinnt' => (bool) $r->gepinnt,
                        'locked' => (bool) $r->locked,
                    ]])->all();
            }
        }

        $result = [];
        foreach ($gps as $gp) {
            $werte = $this->laCache[(int) $gp->id]
                ->filter(fn ($la) => in_array($la->unit_code, ['kg', 'l'], true) && $la->aktiver_preis !== null)
                ->map(fn ($la) => $this->preise->preisProGramm($la, (float) $la->aktiver_preis))
                ->filter(fn ($wert) => $wert !== null)->values();
            $result[(int) $gp->id] = [
                'lead' => $this->preisProGrammFuer($gp),
                'min' => $werte->isNotEmpty() ? (float) $werte->min() : null,
                'avg' => $werte->isNotEmpty() ? (float) $werte->avg() : null,
            ];
        }

        return $result;
    }

    /**
     * R7-Planungs-Blätter: Brutto-Eingangsmasse einer Zutat in Gramm für EINE
     * Charge des Rezepts — mengeAvg × grammFaktor, IDENTISCHE T1-Kaskade wie
     * Yield/Kosten (eine Regel-Stelle). Bewusst VOR Verlust: eingekauft/eingewogen
     * wird die Roh-Eingangsmenge; Putz-/Garverlust reduziert nur den Yield, nicht
     * den Einkauf (spiegelt zutatKosten, das ebenfalls ohne Verlust rechnet).
     * 0.0 wenn keine Gramm-Umrechnung möglich (Zähl-Einheit ohne Default) —
     * der Aufrufer flaggt das dann als Bedarfs-Lücke. Relationen (unit/gp/
     * referencedRecipe) müssen geladen sein.
     */
    public function bruttoMasseG(FoodAlchemistRecipeIngredient $z): float
    {
        return $this->mengeAvg($z) * $this->grammFaktor($z);
    }

    /** T3: Lead-€/g, sonst AVG-€/g über aktive kg/l-LAs (GL-11-Normalisierung). */
    private function preisProGrammFuer(FoodAlchemistGp $gp): ?float
    {
        return $this->preisProGrammMitBasis($gp)[0];
    }

    /**
     * Dieselbe T3-Kaskade, aber mit der Herkunft (V-014): [€/g|null, basis|null].
     * Eine Regel-Stelle — `preisProGrammFuer` ist die Ein-Wert-Sicht darauf.
     *
     * @return array{0: ?float, 1: ?EkPriceBasis}
     */
    private function preisProGrammMitBasis(FoodAlchemistGp $gp): array
    {
        foreach ($this->preisKandidaten($gp) as $la) {
            if (in_array($la->unit_code, ['kg', 'l'], true)) {
                $pg = $this->preise->preisProGramm($la, (float) $la->aktiver_preis);
                if ($pg !== null) {
                    return [$pg, EkPriceBasis::Lead];
                }
            }
        }

        $summe = 0.0;
        $n = 0;
        foreach ($this->alleAktivenLas($gp) as $la) {
            if (in_array($la->unit_code, ['kg', 'l'], true) && $la->aktiver_preis !== null) {
                $pg = $this->preise->preisProGramm($la, (float) $la->aktiver_preis);
                if ($pg !== null) {
                    $summe += $pg;
                    $n++;
                }
            }
        }

        return $n > 0 ? [$summe / $n, EkPriceBasis::Avg] : [null, null];
    }

    /** T3: Lead-€/Stk, sonst AVG-€/Stk über aktive Stk-LAs. */
    private function preisProStueckFuer(FoodAlchemistGp $gp): ?float
    {
        return $this->preisProStueckMitBasis($gp)[0];
    }

    /**
     * Stk-Zwilling von `preisProGrammMitBasis` (V-014): [€/Stk|null, basis|null].
     *
     * @return array{0: ?float, 1: ?EkPriceBasis}
     */
    private function preisProStueckMitBasis(FoodAlchemistGp $gp): array
    {
        foreach ($this->preisKandidaten($gp) as $la) {
            if ($la->unit_code === 'Stk' && $la->qty > 0 && $la->aktiver_preis !== null) {
                return [(float) $la->aktiver_preis / (float) $la->qty, EkPriceBasis::Lead];
            }
        }

        $summe = 0.0;
        $n = 0;
        foreach ($this->alleAktivenLas($gp) as $la) {
            if ($la->unit_code === 'Stk' && $la->qty > 0 && $la->aktiver_preis !== null) {
                $summe += (float) $la->aktiver_preis / (float) $la->qty;
                $n++;
            }
        }

        return $n > 0 ? [$summe / $n, EkPriceBasis::Avg] : [null, null];
    }

    /** Lead-LA (team-bewusst) als bevorzugter Preis-Kandidat. */
    private function preisKandidaten(FoodAlchemistGp $gp): array
    {
        $leadId = $this->effektiverLeadId($gp);
        if ($leadId === null) {
            return [];
        }
        $lead = $this->laMitPreis($gp)->firstWhere('id', $leadId);

        return $lead !== null ? [$lead] : [];
    }

    /**
     * Team-bewusster effektiver Lead-LA fürs Recompute-EK: V-27-Overlay
     * (gp_la_preferences) ÜBER der globalen GL-03-Spalte — spalten-BASIERT, NICHT die
     * heuristische {@see LeadLaService::effektiverLead} (die würde manuell gesetzte Leads
     * re-ranken → Backward-Compat-Bruch). Präzedenz:
     *   1. Team-Pin (verknüpft + bepreist + nicht gesperrt)
     *   2. globaler Default-Lead, sofern nicht team-gesperrt            ← byte-identisch heute
     *   3. globaler Lead gesperrt ⇒ erster nicht gesperrter, bepreister Kandidat
     *   4. sonst NULL (AVG-Pfad)
     * Ohne Team ODER ohne Overlay ⇒ sofort die globale Spalte (heutiges Verhalten).
     */
    private function effektiverLeadId(FoodAlchemistGp $gp): ?int
    {
        $global = $gp->lead_la_supplier_item_id !== null ? (int) $gp->lead_la_supplier_item_id : null;
        if ($this->recomputeTeam === null) {
            return $global;
        }
        $prefs = $this->leadPrefs($gp);
        if ($prefs === []) {
            return $global;                                        // kein Overlay ⇒ exakt wie heute
        }
        $las = $this->laMitPreis($gp);
        $bepreist = fn (int $laId): bool => optional($las->firstWhere('id', $laId))->aktiver_preis !== null;

        foreach ($prefs as $laId => $p) {                          // 1. Team-Pin
            if ($p['gepinnt'] && ! $p['locked'] && $bepreist($laId)) {
                return $laId;
            }
        }
        if ($global !== null && ! ($prefs[$global]['locked'] ?? false)) {   // 2. globaler Lead (nicht gesperrt)
            return $global;
        }
        if ($global !== null) {                                    // 3. globaler Lead gesperrt ⇒ Ausweich
            foreach ($las as $la) {
                if (! ($prefs[$la->id]['locked'] ?? false) && $la->aktiver_preis !== null && ! $la->is_discontinued) {
                    return (int) $la->id;
                }
            }
        }

        return null;                                               // 4. AVG
    }

    /**
     * V-27-Team-Overlay (gp_la_preferences) des Recompute-Teams, je Lauf memoisiert.
     *
     * @return array<int, array{gepinnt: bool, locked: bool}>
     */
    private function leadPrefs(FoodAlchemistGp $gp): array
    {
        if ($this->recomputeTeam === null) {
            return [];
        }
        $key = $this->recomputeTeam->id.':'.$gp->id;

        return $this->leadPrefCache[$key] ??= FoodAlchemistGpLaPreference::query()
            ->where('team_id', $this->recomputeTeam->id)
            ->where('gp_id', $gp->id)
            ->get(['supplier_item_id', 'gepinnt', 'locked'])
            ->mapWithKeys(fn ($r) => [(int) $r->supplier_item_id => [
                'gepinnt' => (bool) $r->gepinnt,
                'locked' => (bool) $r->locked,
            ]])
            ->all();
    }

    private function alleAktivenLas(FoodAlchemistGp $gp): Collection
    {
        $prefs = $this->leadPrefs($gp);

        return $this->laMitPreis($gp)->filter(
            fn ($la) => ! $la->is_discontinued && ! ($prefs[$la->id]['locked'] ?? false),
        );
    }

    /** LAs des GP inkl. Aktiv-Preis (memoisiert pro Pipeline-Lauf). */
    private array $laCache = [];

    /** V-27-Lead-Overlay je (Team,GP), memoisiert pro Lauf (Key "teamId:gpId"). */
    private array $leadPrefCache = [];

    private function laMitPreis(FoodAlchemistGp $gp): Collection
    {
        return $this->laCache[$gp->id] ??= FoodAlchemistSupplierItem::query()
            ->when($this->recomputeTeam, fn ($q) => $q->visibleToTeam($this->recomputeTeam))
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->where('s.gp_id', $gp->id)->whereNull('s.deleted_at')
            ->select('foodalchemist_supplier_items.*')
            ->selectSub($this->preise->activePriceSubquery()->toBase(), 'aktiver_preis')
            ->get();
    }

    private function rangZuText(?int $rang): string
    {
        return match ($rang) {
            3 => 'enthalten',
            2 => 'spuren',
            1 => 'nicht_enthalten',
            default => 'unbekannt',                                // NULL/0 ⇒ unbekannt
        };
    }
}
