<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AllergenValue;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;

/**
 * M4-03: GL-02-Recompute-Pipeline — EINE Transaktion pro Rezept (V-07):
 *   1. Yield + Zähler + Konfidenz (GL-02 §3.1, GL-01 §4.4)
 *   2. Allergene (GL-01: F7.1-Guard, GP-Auflösung via GpAggregateService = Prio-Kette 4.3)
 *   3. Zusatzstoffe (GL-09: gleicher Guard, MAX über Roh-Domäne)
 *   4. Kosten (GL-02 §3.2, T3-Kaskade; I7: Nenner = GERUNDETES yield)
 *   5. Nährwerte (GL-08: KEIN Guard, Rohmasse-Basis, 0-Substitution GT-02)
 *   6. Spec-Flags (normativ nur spec_is_gluten_free aus GL-01; Rest = Nachtrag, Spec fehlt)
 *
 * Entscheid A-1 (Empfehlung umgesetzt, 08_ENTSCHEIDUNGEN): Verluste MULTIPLIKATIV
 * (1−putz)×(1−gar) aus den Zutat-Feldern — DB-verifiziert über GT-1/GT-2.
 * A-2-Ziel: Yield- und Kosten-Pfad nutzen BEIDE die volle T1-Kaskade.
 * A-3: Kalkulations-Yield = COALESCE(yield_kg_manual, yield_kg).
 * A-5-Ziel: Tiefe > 3 BLOCKT beim Verknüpfen (kein Warn-Flag).
 * I9: vk_* wird hier NIEMALS geschrieben.
 */
class RecipeRecomputeService
{
    private const MAX_TIEFE = 3;        // Regelwerk BR §4 (SUBRECIPE_MAX_DEPTH)

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
    ) {
    }

    /** Pipeline für EIN Rezept — idempotent (I4), eine Transaktion (V-07). */
    public function recomputePipeline(int $recipeId): void
    {
        $this->laCache = [];                                       // Preis-Memo nie über Edits hinweg tragen
        DB::transaction(function () use ($recipeId) {
            $recipe = FoodAlchemistRecipe::with(['ingredients.einheit', 'ingredients.gp', 'ingredients.referencedRecipe'])
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
            $recipe->save();
        });
    }

    /** §3.3: Pipeline + alle transitiven Eltern per BFS (best effort, I8). */
    public function recomputeAndPropagate(int $recipeId): void
    {
        $this->recomputePipeline($recipeId);

        $besucht = [$recipeId => true];
        $ebene = [$recipeId];
        for ($tiefe = 0; $tiefe < self::PROPAGATION_LIMIT && $ebene !== []; $tiefe++) {
            $eltern = FoodAlchemistRecipeIngredient::whereIn('referenced_recipe_id', $ebene)
                ->whereNull('deleted_at')->distinct()->pluck('recipe_id')
                ->reject(fn ($id) => isset($besucht[$id]))->values()->all();
            foreach ($eltern as $parentId) {
                $besucht[$parentId] = true;
                try {
                    $this->recomputePipeline($parentId);
                } catch (\Throwable $e) {
                    Log::warning("Recompute-Propagation: Eltern-Rezept {$parentId} fehlgeschlagen — {$e->getMessage()} (I8: Edit nicht geblockt)");
                }
            }
            $ebene = $eltern;
        }

        // K-07 / Doc 15 §M12: Auto-Pakete, die ein neu berechnetes Gericht enthalten,
        // als preis_stale markieren (GP-Preis-Änderung → Baustein-Preis veraltet).
        // Best-effort, außerhalb der Recompute-Transaktion (I8: Edit nicht blocken).
        try {
            $paketSvc = app(PaketService::class);
            foreach (array_keys($besucht) as $rid) {
                $paketSvc->markStaleForRecipe((int) $rid);
            }
        } catch (\Throwable $e) {
            Log::warning("K-07 markStaleForRecipe fehlgeschlagen: {$e->getMessage()}");
        }
    }

    /**
     * §3.4: Bulk in topologischer Ordnung (Kahn) — Kinder vor Eltern (A-4: verbindlich).
     *
     * @return array{berechnet: int, reihenfolge_ok: bool}
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
            if (isset($inDegree[$k->recipe_id])) {
                $inDegree[$k->recipe_id]++;                        // Eltern: je referenziertem DISTINCT Sub +1
            }
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
            throw new \RuntimeException('Zyklus im Sub-Rezept-Graph — beteiligte recipe_ids: ' . implode(', ', $zyklus));
        }

        foreach ($order as $id) {
            $this->recomputePipeline($id);
        }

        return ['berechnet' => count($order), 'reihenfolge_ok' => true];
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

        // GT-7: Ahnen des Parents zählen mit — Kette A→B→C + Link C→D ⇒ projiziert 4
        $tiefe = max(
            $this->ahnenHoehe($parentId) + $this->subtreeTiefe($subId),
            $this->subtreeTiefe($parentId),
        );
        if ($tiefe > self::MAX_TIEFE) {
            return ['erlaubt' => false, 'grund' => 'Tiefe > ' . self::MAX_TIEFE . ' (Regelwerk §4 — Block, A-5)', 'projizierte_tiefe' => $tiefe];
        }

        return ['erlaubt' => true, 'grund' => null, 'projizierte_tiefe' => $tiefe];
    }

    /** Längster Aufwärts-Pfad bis zur Wurzel, inkl. Start-Rezept (A→B→C: C ⇒ 3). */
    private function ahnenHoehe(int $recipeId): int
    {
        $hoehe = 1;
        $ebene = [$recipeId];
        $besucht = [$recipeId => true];
        while ($ebene !== [] && $hoehe <= self::MAX_TIEFE + 2) {
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
        while ($ebene !== [] && $tiefe <= self::MAX_TIEFE + 2) {
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
        $geminiDabei = false;

        foreach ($zutaten as $z) {
            $nTotal++;
            if (! $this->istGemappt($z)) {
                $nUngemappt++;
            } elseif ($z->match_method === MatchMethod::GeminiProposed) {
                $geminiDabei = true;
            }
            if ($z->is_optional || $z->einheit?->slug === 'qs') {
                continue;                                          // Yield-Beitrag 0 (T2)
            }
            // Ungemappte tragen zum Yield bei (Masse ist mapping-unabhängig, §3.1)
            $yieldG += $this->mengeAvg($z) * $this->grammFaktor($z)
                * (1 - $this->effektiverPutzverlust($z) / 100)       // A-1: multiplikativ (Entscheid)
                * (1 - $this->effektiverGarverlust($z) / 100);
        }

        $recipe->yield_kg = $yieldG > 0 ? round($yieldG / 1000, 3) : null;
        $recipe->n_zutaten_total = $nTotal;
        $recipe->n_zutaten_ungemappt = $nUngemappt;
        $recipe->allergene_konfidenz = match (true) {              // GL-01 §4.4 (erste zutreffende)
            $nTotal === 0 => 'unknown',
            $nUngemappt > 0 => 'low',
            $geminiDabei => 'medium',
            default => 'high',
        };
    }

    /** Verlust-Kaskade (GL-02): Zutat-Wert → GP-Default → Team-WG-Default → 0. */
    private function effektiverGarverlust(FoodAlchemistRecipeIngredient $z): float
    {
        if ($z->garverlust_pct !== null) {
            return (float) $z->garverlust_pct;
        }
        if ($z->gp?->garverlust_default_pct !== null) {
            return (float) $z->gp->garverlust_default_pct;
        }

        return $this->teamVerlustDefault('garverlust', $z->gp?->warengruppe_code);
    }

    private function effektiverPutzverlust(FoodAlchemistRecipeIngredient $z): float
    {
        if ($z->putzverlust_pct !== null) {
            return (float) $z->putzverlust_pct;
        }
        if ($z->gp?->putzverlust_default_pct !== null) {
            return (float) $z->gp->putzverlust_default_pct;
        }

        return $this->teamVerlustDefault('putzverlust', $z->gp?->warengruppe_code);
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

        if ($recipe->n_zutaten_ungemappt > 0) {                    // F7.1-Guard: Totalreset
            foreach ($felder as $f) {
                $recipe->{"allergen_{$f}"} = 'unbekannt';
            }
            $recipe->spec_is_gluten_free = null;
            $recipe->allergene_aggregiert_am = now();              // Invariante 6 (Ziel: mitschreiben)

            return;
        }

        $raenge = array_fill_keys($felder, null);
        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            if ($z->gp_id !== null && $z->gp !== null) {           // GP-Pfad gewinnt
                $werte = $this->gpAggregate->allergene($z->gp);    // Prio-Kette 4.3 (Override>Mutter>LA-MAX)
                foreach ($felder as $f) {
                    if ($werte[$f]['quelle'] === 'keine') {
                        continue;                                  // kein Beitrag (NULL)
                    }
                    $rang = $werte[$f]['wert']->rank();
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
        $recipe->spec_is_gluten_free = match ($recipe->allergen_glutenhaltiges_getreide) {
            'nicht_enthalten' => true,
            'enthalten', 'spuren' => false,
            default => null,
        };
        $recipe->allergene_aggregiert_am = now();
    }

    // ── 3. Zusatzstoffe (GL-09) ─────────────────────────────────────────

    private function zusatzstoffe(FoodAlchemistRecipe $recipe, Collection $zutaten): void
    {
        $stoffe = array_keys(FoodAlchemistItemDeclaration::STOFFE);

        if ($recipe->n_zutaten_ungemappt > 0) {                    // F7.1-Guard: alle 18 NULL
            foreach ($stoffe as $s) {
                $recipe->{"zusatz_{$s}"} = null;
            }
            $recipe->zusatz_aggregiert_am = now();

            return;
        }

        $max = array_fill_keys($stoffe, null);
        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            $beitraege = null;
            if ($z->gp_id !== null && $z->gp !== null) {
                $beitraege = $this->gpAggregate->zusatzstoffe($z->gp);
            } elseif ($z->referencedRecipe !== null) {
                $beitraege = collect($stoffe)->mapWithKeys(fn ($s) => [$s => $z->referencedRecipe->{"zusatz_{$s}"}])->all();
            }
            foreach ($stoffe as $s) {
                $wert = $beitraege[$s] ?? null;
                if ($wert !== null) {
                    $max[$s] = max($max[$s] ?? 0, (int) $wert);    // SQL-MAX-Semantik: NULL ignoriert
                }
            }
        }
        foreach ($stoffe as $s) {
            $recipe->{"zusatz_{$s}"} = $max[$s];
        }
        $recipe->zusatz_aggregiert_am = now();
    }

    // ── 4. Kosten (§3.2, T3) ────────────────────────────────────────────

    /**
     * Zeilen-Kosten je Zutat (Panel M4-05 „EK je Zeile") — exakt dieselbe
     * T3-Kaskade wie der Recompute, eine Regel-Stelle.
     *
     * @return array<int, ?float> [ingredient_id => Kosten € | null (unpriced/gefiltert)]
     */
    public function zeilenKosten(FoodAlchemistRecipe $recipe): array
    {
        $this->laCache = [];
        $zutaten = $recipe->ingredients->filter(fn ($z) => $z->match_method !== MatchMethod::Ignored);

        $out = [];
        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            [$kosten, $priced] = $this->zutatKosten($z);
            $out[$z->id] = $priced ? round($kosten, 2) : null;
        }

        return $out;
    }

    private function kosten(FoodAlchemistRecipe $recipe, Collection $zutaten): void
    {
        $ekTotal = 0.0;
        $nKosten = 0;
        $nPriced = 0;

        foreach ($this->aggregationsZutaten($zutaten) as $z) {
            $nKosten++;
            [$kosten, $priced] = $this->zutatKosten($z);
            if ($priced) {
                $nPriced++;
            }
            $ekTotal += $kosten;
        }

        $recipe->ek_n_ingredients_total = $nKosten;
        $recipe->ek_n_ingredients_priced = $nPriced;
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
            && $z->einheit?->slug !== 'qs'
            && $this->istGemappt($z));

        $totalG = 0.0;
        $summen = ['kcal' => 0.0, 'protein' => 0.0, 'fat' => 0.0, 'carbs' => 0.0, 'salt' => 0.0];
        $nMapped = 0;

        foreach ($relevant as $z) {
            // GL-08 §4.2 verbatim: NUR g/ml-Faktor — bewusst KEIN stk-Fallback (Lücke dokumentiert)
            $mengeG = $this->mengeAvg($z) * (float) ($z->einheit?->default_in_g ?? $z->einheit?->default_in_ml ?? 0);

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
                    ];
                }
            } elseif ($z->gp !== null) {
                $n = $this->gpAggregate->naehrwerte($z->gp);
                if ($n['energy_kcal']['avg'] !== null) {           // kcal = Leit-Indikator
                    $werte = [
                        'kcal' => $n['energy_kcal']['avg'],
                        'protein' => $n['protein']['avg'] ?? 0.0,
                        'fat' => $n['fat']['avg'] ?? 0.0,
                        'carbs' => $n['carbs_absorbable']['avg'] ?? 0.0,
                        'salt' => $n['salt_g']['avg'] ?? 0.0,      // sodium×0.0025 (GL-08 §4.2)
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
        } else {
            $recipe->nutri_kcal_per_100g = null;
            $recipe->nutri_protein_g_per_100g = null;
            $recipe->nutri_fat_g_per_100g = null;
            $recipe->nutri_carbs_g_per_100g = null;
            $recipe->nutri_salt_g_per_100g = null;
        }
        $recipe->nutri_n_ingredients_total = $nTotal;
        $recipe->nutri_n_ingredients_mapped = $nMapped;
        $recipe->nutri_konfidenz = match (true) {                  // GL-08 §4.3 (erste zutreffende)
            $nTotal === 0, $nMapped === 0 => 'unknown',
            $nMapped === $nTotal => 'high',
            $nMapped / $nTotal >= 0.8 => 'medium',
            default => 'low',
        };
        $recipe->nutri_aggregiert_am = now();
    }

    /** T3-Kaskade für EINE Zutat: [kosten €, priced?]. */
    private function zutatKosten(FoodAlchemistRecipeIngredient $z): array
    {
        $mengeAvg = $this->mengeAvg($z);
        $mengeG = $mengeAvg * $this->grammFaktor($z);

        $gp = $z->gp;
        $pG = $gp !== null ? $this->preisProGrammFuer($gp) : null;
        $pStk = $gp !== null ? $this->preisProStueckFuer($gp) : null;
        $pSub = $z->referencedRecipe?->ek_per_kg_eur !== null
            ? ((float) $z->referencedRecipe->ek_per_kg_eur) / 1000 : null;

        if ($z->einheit?->dimension === 'count') {                 // T3 Zeile count
            if ($pStk !== null) {
                return [$mengeAvg * $pStk, true];
            }
            if ($mengeG > 0 && $pG !== null) {                     // count→mass-Brücke
                return [$mengeG * $pG, true];
            }

            return [0.0, false];
        }

        // mass/volume/pinch/piece
        $stkDefaultG = $gp?->stk_default_g !== null ? (float) $gp->stk_default_g : null;
        $quelle = $pG
            ?? ($pStk !== null && $stkDefaultG > 0 ? $pStk / $stkDefaultG : null)  // Stk→g-Brücke
            ?? $pSub;
        if ($quelle !== null && $mengeG > 0) {                     // T2: qs (Faktor 0) bleibt unpriced
            return [$mengeG * $quelle, true];
        }

        return [0.0, false];
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** GL-01/02/09-Filter: nicht optional + gemappt (GP per I5-Gate ODER Sub-Ref). ignored ist schon raus. */
    private function aggregationsZutaten(Collection $zutaten): Collection
    {
        return $zutaten->filter(fn ($z) => ! $z->is_optional && $this->istGemappt($z));
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

    /** I6 / F6.4: Mittelwert bei Mengen-Bereich. */
    private function mengeAvg(FoodAlchemistRecipeIngredient $z): float
    {
        return $z->menge_max !== null
            ? ((float) $z->menge + (float) $z->menge_max) / 2
            : (float) $z->menge;
    }

    /** T1-Kaskade (A-2-Ziel: für Yield UND Kosten identisch). */
    private function grammFaktor(FoodAlchemistRecipeIngredient $z): float
    {
        $einheit = $z->einheit;
        if ($einheit?->default_in_g !== null) {
            return (float) $einheit->default_in_g;
        }
        if ($einheit?->default_in_ml !== null) {
            return (float) $einheit->default_in_ml;                // Dichte 1.0 (Wasser)
        }
        if ($einheit?->dimension === 'count' && $z->gp_id !== null) {
            $eintrag = DB::table('foodalchemist_gp_count_unit_defaults')
                ->where('gp_id', $z->gp_id)->where('einheit_vocab_id', $z->einheit_vocab_id)
                ->whereNull('deleted_at')->value('default_g');
            if ($eintrag !== null) {
                return (float) $eintrag;                           // T1 Zeile 3 (Zehe 5 g / Knolle 40 g)
            }
            if ($z->gp?->stk_default_g !== null) {
                return (float) $z->gp->stk_default_g;              // T1 Zeile 4
            }
        }

        return 0.0;                                                // T1 Zeile 5: kein Beitrag
    }

    /** P-8-Picker (M4-08): €/g fürs Client-Live-Rechnen — dieselbe T3-Quelle. */
    public function preisProGrammPublic(FoodAlchemistGp $gp): ?float
    {
        return $this->preisProGrammFuer($gp);
    }

    /** T3: Lead-€/g, sonst AVG-€/g über aktive kg/l-LAs (GL-11-Normalisierung). */
    private function preisProGrammFuer(FoodAlchemistGp $gp): ?float
    {
        foreach ($this->preisKandidaten($gp) as $la) {
            if (in_array($la->unit_code, ['kg', 'l'], true)) {
                $pg = $this->preise->preisProGramm($la, (float) $la->aktiver_preis);
                if ($pg !== null) {
                    return $pg;
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

        return $n > 0 ? $summe / $n : null;
    }

    /** T3: Lead-€/Stk, sonst AVG-€/Stk über aktive Stk-LAs. */
    private function preisProStueckFuer(FoodAlchemistGp $gp): ?float
    {
        foreach ($this->preisKandidaten($gp) as $la) {
            if ($la->unit_code === 'Stk' && $la->qty > 0 && $la->aktiver_preis !== null) {
                return (float) $la->aktiver_preis / (float) $la->qty;
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

        return $n > 0 ? $summe / $n : null;
    }

    /** Lead-LA (falls gesetzt) als bevorzugter Preis-Kandidat. */
    private function preisKandidaten(FoodAlchemistGp $gp): array
    {
        if ($gp->lead_la_supplier_item_id === null) {
            return [];
        }
        $lead = $this->laMitPreis($gp)->firstWhere('id', $gp->lead_la_supplier_item_id);

        return $lead !== null ? [$lead] : [];
    }

    private function alleAktivenLas(FoodAlchemistGp $gp): Collection
    {
        return $this->laMitPreis($gp)->filter(fn ($la) => ! $la->is_discontinued);
    }

    /** LAs des GP inkl. Aktiv-Preis (memoisiert pro Pipeline-Lauf). */
    private array $laCache = [];

    private function laMitPreis(FoodAlchemistGp $gp): Collection
    {
        return $this->laCache[$gp->id] ??= \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::query()
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
