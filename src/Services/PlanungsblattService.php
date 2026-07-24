<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * R7.1 — Operative Planungs-Blätter (read-only, rein rechnend; kein Bestand,
 * keine Bestellung). Kaskaden-Ausgabe: Konzept/Gericht + Skalierung (Personen
 * ODER Portionen) → drei Sichten aus EINER Explosion:
 *
 *   - produktionsblatt: Rezept-Übergabe zum Nachbauen/Anlegen. Top-Gericht auf
 *     die Produktionsmenge skaliert, referenzierte BASISREZEPTE in GANZEN
 *     Basis-Ansätzen (wie in FA angelegt — man kocht keinen 20-g-Ansatz), mit
 *     transparentem „benötigt gesamt"-Vermerk.
 *   - bestellvorschlag: GP-Bedarf gruppiert nach Lead-LA-Lieferant + EK-Summe,
 *     inkl. Ausweichquelle (Rang 2 der Lead-Kette).
 *   - einkaufsliste: dieselbe GP-Aggregation über MEHRERE Konzepte / ein Event.
 *
 * Rechen-Prinzip (Dominique 2026-07-13): „so wie das Rezept in FA angelegt ist."
 * Nichts wird künstlich runter-fraktioniert — VK-Gerichte skalieren linear auf
 * die Menge, Basisrezepte werden auf GANZE Ansätze aufgerundet. Mengen/Preise
 * kommen ausschließlich aus der bestehenden Kaskade (RecipeRecomputeService::
 * bruttoMasseG = T1-Roh-Eingangsmasse, preisProGrammPublic = Lead-€/g,
 * LeadLaService = Lieferanten-Rangliste) — keine eigene Rechen-Wahrheit.
 *
 * Read-only: der Service liest + rechnet, schreibt NIE (kein Recompute, kein
 * Persist). Team-Scoping erfolgt beim Laden der Ziele durch den Aufrufer/Tool
 * (visibleToTeam); die Lead-LA-Wahl ist team-abhängig (rangliste).
 */
class PlanungsblattService
{
    private const MAX_TIEFE = 4; // Regelwerk BR §4: Sub-Rezept-Tiefe ≤ 3 + Top-Ebene

    /** @var array<int, FoodAlchemistRecipe> Rezept-Memo (mit geladenen Zutaten) je Lauf. */
    private array $recipeCache = [];

    public function __construct(
        private RecipeRecomputeService $recompute,
        private LeadLaService $leadLa,
        private DarreichungResolver $darreichungen,
        private GebindeRechner $gebinde,
    ) {
    }

    // ── Öffentliche Blätter ──────────────────────────────────────────────

    /**
     * Produktionsblatt für EIN Ziel (Konzept + Personen ODER Gericht + Portionen/Personen).
     * Liefert die zu produzierenden Rezepte in Reihenfolge (Top zuerst, dann Basisrezepte)
     * + eine GP-Bedarfs-Zusammenfassung. Rezept-orientiert = Übergabe zum Anlegen.
     *
     * @param  array{concept_id?:int, recipe_id?:int, persons?:int|float, portions?:int|float}  $ziel
     */
    public function produktionsblatt(Team $team, array $ziel): array
    {
        $this->recipeCache = [];
        $tops = $this->topsAus($team, [$ziel]);
        $ex = $this->explodiere($team, $tops['tops']);

        return [
            'skalierung' => $tops['skalierung'],
            'rezepte' => $ex['production'],
            'gp_bedarf' => array_values($ex['gp']),
            'warnungen' => array_merge($tops['warnungen'], $ex['warnings']),
        ];
    }

    /**
     * Bestellvorschlag für EIN Ziel: GP-Bedarf → Lead-LA je Lieferant, gruppiert
     * nach Lieferant, mit EK-Summe + Ausweichquelle.
     *
     * @param  array{concept_id?:int, recipe_id?:int, persons?:int|float, portions?:int|float}  $ziel
     */
    public function bestellvorschlag(Team $team, array $ziel): array
    {
        $this->recipeCache = [];
        $tops = $this->topsAus($team, [$ziel]);
        $ex = $this->explodiere($team, $tops['tops']);

        return [
            'skalierung' => $tops['skalierung'],
            'lieferanten' => $this->gruppiereNachLieferant($team, $ex['gp']),
            'warnungen' => array_merge($tops['warnungen'], $ex['warnings']),
        ];
    }

    /**
     * Einkaufsliste über MEHRERE Ziele (Event / mehrere Konzepte): GP-Bedarf
     * zusammengeführt, gruppiert nach Lieferant.
     *
     * @param  list<array{concept_id?:int, recipe_id?:int, persons?:int|float, portions?:int|float}>  $ziele
     */
    public function einkaufsliste(Team $team, array $ziele): array
    {
        $this->recipeCache = [];
        $tops = $this->topsAus($team, $ziele);
        $ex = $this->explodiere($team, $tops['tops']);

        return [
            'ziele' => $tops['ziel_labels'],
            'lieferanten' => $this->gruppiereNachLieferant($team, $ex['gp']),
            'positionen_gesamt' => count($ex['gp']),
            'warnungen' => array_merge($tops['warnungen'], $ex['warnings']),
        ];
    }

    /**
     * Spec 18 — wie produktionsblatt(), aber für MEHRERE Ziele EINES Produktionstags:
     * rundet Sub-Rezept-Ansätze über ALLE Ziele hinweg GEMEINSAM (Diamond-/Rundungs-sicher,
     * gleiches Prinzip wie einkaufsliste() für GP-Bedarf). Reuse-only: kein neuer Rechenpfad,
     * nur topsAus()/explodiere() mit N statt 1 Ziel aufgerufen.
     *
     * @param  list<array{concept_id?:int, recipe_id?:int, persons?:int|float, portions?:int|float}>  $ziele
     */
    public function produktionsblattFuerZiele(Team $team, array $ziele): array
    {
        $this->recipeCache = [];
        $tops = $this->topsAus($team, $ziele);
        $ex = $this->explodiere($team, $tops['tops']);

        return [
            'ziele' => $tops['ziel_labels'],
            'rezepte' => $ex['production'],
            'gp_bedarf' => array_values($ex['gp']),
            'warnungen' => array_merge($tops['warnungen'], $ex['warnings']),
        ];
    }

    // ── Ziele → Top-Ebene (Skalierung auf Batches) ────────────────────────

    /**
     * Löst eine Liste von Zielen in Top-Produktionen [recipe, batches] auf.
     * VK-Gericht: batches = Portionen ÷ Portionszahl (linear). Basisrezept:
     * batches = Gesamt-Gramm ÷ Basis-Yield (wird bei der Explosion gerundet).
     *
     * @return array{tops:list<array{recipe:FoodAlchemistRecipe,batches:float,label:string}>, warnungen:list<string>, skalierung:?array, ziel_labels:list<string>}
     */
    private function topsAus(Team $team, array $ziele): array
    {
        $tops = [];
        $warnungen = [];
        $zielLabels = [];
        $skalierung = null;

        foreach ($ziele as $ziel) {
            if (! empty($ziel['chapter_id'])) {
                // P1b: Foodbook-Kapitel + Personen → sichtbare concept_ref/recipe_ref-Blocks des
                // Kapitel-Scopes (Kapitel + Nachfahren), Varianten-Gruppen aufgelöst. Live-Zweig
                // für die ephemeren Blätter/MCP-Reads; STORED Ziele werden über kapitelZiele() in
                // Einzel-Ziele aufgelöst (V2 „kein Live-Bezug").
                $chapter = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->find((int) $ziel['chapter_id']);
                if ($chapter === null) {
                    $warnungen[] = "Foodbook-Kapitel #{$ziel['chapter_id']} nicht sichtbar/vorhanden — übersprungen.";

                    continue;
                }
                $personen = max(1, (int) ($ziel['persons'] ?? 0));
                $skalierung ??= ['modus' => 'personen', 'wert' => $personen];
                $variantChoices = is_array($ziel['variant_choices'] ?? null) ? $ziel['variant_choices'] : [];
                $zielLabels[] = "{$chapter->title} ({$personen} P.)";
                foreach ($this->kapitelTops($team, $chapter, $personen, $variantChoices, $warnungen) as $t) {
                    $tops[] = $t;
                }
            } elseif (! empty($ziel['concept_id'])) {
                $concept = FoodAlchemistConcept::visibleToTeam($team)->find((int) $ziel['concept_id']);
                if ($concept === null) {
                    $warnungen[] = "Konzept #{$ziel['concept_id']} nicht sichtbar/vorhanden — übersprungen.";

                    continue;
                }
                $personen = max(1, (int) ($ziel['persons'] ?? 0));
                $skalierung ??= ['modus' => 'personen', 'wert' => $personen];
                $zielLabels[] = "{$concept->name} ({$personen} P.)";
                foreach ($this->konzeptTops($concept, $personen, $warnungen) as $t) {
                    $tops[] = $t;
                }
            } elseif (! empty($ziel['recipe_id'])) {
                $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find((int) $ziel['recipe_id']);
                if ($recipe === null) {
                    $warnungen[] = "Rezept #{$ziel['recipe_id']} nicht sichtbar/vorhanden — übersprungen.";

                    continue;
                }
                [$batches, $meta] = $this->rezeptTopBatches($recipe, $ziel, $warnungen);
                $skalierung ??= $meta;
                $zielLabels[] = "{$recipe->name} ({$meta['wert']} {$meta['modus']})";
                $tops[] = ['recipe' => $recipe, 'batches' => $batches, 'label' => $recipe->name];
            } else {
                $warnungen[] = 'Ziel ohne concept_id/recipe_id — übersprungen.';
            }
        }

        return ['tops' => $tops, 'warnungen' => $warnungen, 'skalierung' => $skalierung, 'ziel_labels' => $zielLabels];
    }

    /** Konzept-Slots (Pakete + feste Gerichte) → Top-Produktionen für N Personen. */
    private function konzeptTops(FoodAlchemistConcept $concept, int $personen, array &$warnungen): array
    {
        $concept->load([
            'slots' => fn ($q) => $q->orderBy('position'),
            'slots.unit:id,slug,dimension,default_in_g',
            'slots.package.dishes' => fn ($q) => $q->orderBy('position'),
            'slots.package.dishes.unit:id,slug,dimension,default_in_g',
            'slots.package.dishes.dish:id,name,is_sales_recipe,sales_unit_count,sales_quantity_per_unit_g,yield_kg,yield_pieces',
            'slots.dish:id,name,is_sales_recipe,sales_unit_count,sales_quantity_per_unit_g,yield_kg,yield_pieces',
        ]);

        $tops = [];
        foreach ($concept->slots as $slot) {
            $slot->setRelation('concept', $concept);
            if ($slot->package) {
                foreach ($slot->package->dishes as $pg) {
                    if ($pg->dish) {
                        $dar = $this->darreichungen->fuerPaketGericht($pg);
                        $tops[] = $this->positionTop($pg->dish, $pg->quantity, $pg->unit, $dar, $personen, $warnungen);
                    }
                }
            } elseif ($slot->dish) {
                $dar = $this->darreichungen->fuerSlot($slot);
                $tops[] = $this->positionTop($slot->dish, $slot->quantity, $slot->unit, $dar, $personen, $warnungen);
            }
        }

        return array_values(array_filter($tops));
    }

    /** Eine Konzept-Position (Gericht + Menge/Person) → [recipe, batches] für N Personen. */
    private function positionTop($gericht, $quantity, $unit, $dar, int $personen, array &$warnungen): ?array
    {
        $q = $quantity !== null ? (float) $quantity : null;

        // Basisrezept-Position: Menge = GRAMM/Person → Batches = Gesamt-Gramm ÷ Basis-Yield.
        if (! (bool) ($gericht->is_sales_recipe ?? true)
            && ! ConcepterAggregateService::stueckModus($unit, $gericht)) {
            $basisG = (float) ($gericht->yield_kg ?? 0) * 1000;
            if ($q === null || $q <= 0 || $basisG <= 0) {
                $warnungen[] = "Position „{$gericht->name}“: Menge/Basis-Yield fehlt — nicht skalierbar.";

                return null;
            }

            return ['recipe' => $this->ladeRezept((int) $gericht->id), 'batches' => ($q * $personen) / $basisG, 'label' => $gericht->name];
        }

        // VK-Gericht (oder Stück-Modus): Portions-Äquivalent × Personen → Portionen → Batches.
        $darPortionG = $dar?->quantity_per_unit_g !== null ? (float) $dar->quantity_per_unit_g : null;
        $pae = ConcepterAggregateService::portionsAequivalent($q, $unit, $gericht, $darPortionG);
        if ($pae === null) {
            $warnungen[] = "Position „{$gericht->name}“: Gramm-Menge ohne Portionsgewicht — nicht skalierbar.";

            return null;
        }
        $stueck = ConcepterAggregateService::stueckModus($unit, $gericht);
        $anzahl = $stueck ? (float) $gericht->yield_pieces : max(1, (int) ($gericht->sales_unit_count ?? 1));
        if ($anzahl <= 0) {
            $anzahl = 1.0;
        }

        return ['recipe' => $this->ladeRezept((int) $gericht->id), 'batches' => ($pae * $personen) / $anzahl, 'label' => $gericht->name];
    }

    // ── Foodbook-Kapitel als Ziel (P1b) ───────────────────────────────────

    /**
     * Kapitel-Scope (Kapitel + Nachfahren) → Top-Produktionen für N Personen. Nur
     * sichtbare concept_ref/recipe_ref-Blocks tragen bei (header/text/spacer/image
     * werden geskippt). Varianten-Gruppen (variant_group_id) werden auf genau EINEN
     * Block reduziert (gewählt via $variantChoices[gruppe]=block_id, sonst der erste
     * nach Dokument-Reihenfolge). concept_ref → konzeptTops(); recipe_ref → VK-Position
     * (Default 1 Portion/Person, Block-quantity+unit wenn gesetzt).
     *
     * @param  array<int|string,int>  $variantChoices  variant_group_id ⇒ gewählte block_id
     * @return list<array{recipe:FoodAlchemistRecipe,batches:float,label:string}>
     */
    private function kapitelTops(Team $team, FoodAlchemistFoodbookKapitel $chapter, int $personen, array $variantChoices, array &$warnungen): array
    {
        $tops = [];
        foreach ($this->kapitelBloecke($chapter, $variantChoices) as $block) {
            if ($block->type === 'concept_ref') {
                $concept = $block->concept_id !== null
                    ? FoodAlchemistConcept::visibleToTeam($team)->find((int) $block->concept_id)
                    : null;
                if ($concept === null) {
                    $warnungen[] = "Kapitel „{$chapter->title}“: Konzept-Block ohne sichtbares Konzept — übersprungen.";

                    continue;
                }
                foreach ($this->konzeptTops($concept, $personen, $warnungen) as $t) {
                    $tops[] = $t;
                }
            } elseif ($block->type === 'recipe_ref') {
                $dish = $block->sales_recipe_id !== null ? $this->ladeGerichtStamm((int) $block->sales_recipe_id, $team) : null;
                if ($dish === null) {
                    $warnungen[] = "Kapitel „{$chapter->title}“: Gericht-Block ohne sichtbares Rezept — übersprungen.";

                    continue;
                }
                // Default 1 Portion/Person, sonst Block-Menge+Einheit (positionTop rechnet das VK-Skalierungsmuster).
                $top = $this->positionTop($dish, $block->quantity, $block->unit, null, $personen, $warnungen);
                if ($top !== null) {
                    $tops[] = $top;
                }
            }
        }

        return $tops;
    }

    /**
     * Kapitel-Ziel → aufgelöste Einzel-Ziele (V2 „kein Live-Bezug"): expandiert ein
     * Kapitel in konkrete {concept_id, persons} / {recipe_id, portions}-Ziel-Dicts, die
     * der Editor/ADD_TARGET als eingefrorene Auftrags-Ziele speichern. Varianten-Wahl wie
     * in kapitelTops(). recipe_ref: portions = Portions-Äquivalent × Personen (Default
     * 1/Person), rundtripsicher zu rezeptTopBatches().
     *
     * @param  array<int|string,int>  $variantChoices
     * @return array{ziele:list<array{concept_id?:int,recipe_id?:int,persons?:int,portions?:float}>, warnungen:list<string>}
     */
    public function kapitelZiele(Team $team, int $chapterId, int $personen, array $variantChoices = []): array
    {
        $chapter = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->find($chapterId);
        if ($chapter === null) {
            return ['ziele' => [], 'warnungen' => ["Foodbook-Kapitel #{$chapterId} nicht sichtbar/vorhanden."]];
        }
        $personen = max(1, $personen);
        $warnungen = [];
        $ziele = [];
        foreach ($this->kapitelBloecke($chapter, $variantChoices) as $block) {
            if ($block->type === 'concept_ref' && $block->concept_id !== null) {
                $ziele[] = ['concept_id' => (int) $block->concept_id, 'persons' => $personen];
            } elseif ($block->type === 'recipe_ref' && $block->sales_recipe_id !== null) {
                $dish = $this->ladeGerichtStamm((int) $block->sales_recipe_id, $team);
                if ($dish === null) {
                    $warnungen[] = "Kapitel „{$chapter->title}“: Gericht-Block ohne sichtbares Rezept — übersprungen.";

                    continue;
                }
                $ziele[] = ['recipe_id' => (int) $block->sales_recipe_id, 'portions' => $this->recipeRefPortionen($dish, $block, $personen, $warnungen)];
            }
        }

        return ['ziele' => $ziele, 'warnungen' => $warnungen];
    }

    /**
     * Sammelt die für die Produktion relevanten Blocks eines Kapitel-Scopes (Kapitel +
     * alle Nachfahren, Rollup wie Spec 19), sichtbar, Typ concept_ref/recipe_ref, in
     * Dokument-Reihenfolge (Kapitel-Position → Block-Position → id). Varianten-Gruppen
     * werden an ihrer ersten Fundstelle auf genau EINEN Block reduziert.
     *
     * @param  array<int|string,int>  $variantChoices
     * @return list<FoodAlchemistFoodbookBlock>
     */
    private function kapitelBloecke(FoodAlchemistFoodbookKapitel $chapter, array $variantChoices): array
    {
        // Scope = Kapitel + Nachfahren (BFS über parent_id innerhalb des Foodbooks).
        $alle = FoodAlchemistFoodbookKapitel::where('foodbook_id', $chapter->foodbook_id)->get(['id', 'parent_id', 'position']);
        $posMap = [];
        $ord = 0;
        foreach ($alle->sortBy('position') as $c) {
            $posMap[(int) $c->id] = $ord++;
        }
        $scope = [(int) $chapter->id];
        $queue = [(int) $chapter->id];
        while ($queue !== []) {
            $pid = array_shift($queue);
            foreach ($alle->where('parent_id', $pid) as $c) {
                $scope[] = (int) $c->id;
                $queue[] = (int) $c->id;
            }
        }

        $blocks = FoodAlchemistFoodbookBlock::with(['unit:id,slug,dimension,default_in_g'])
            ->whereIn('chapter_id', $scope)
            ->where('visible', true)
            ->whereIn('type', ['concept_ref', 'recipe_ref'])
            ->get()
            ->all();

        usort($blocks, function ($a, $b) use ($posMap) {
            $ca = $posMap[(int) $a->chapter_id] ?? 0;
            $cb = $posMap[(int) $b->chapter_id] ?? 0;

            return [$ca, (int) $a->position, (int) $a->id] <=> [$cb, (int) $b->position, (int) $b->id];
        });

        // Varianten-Gruppen an der ersten Fundstelle auf einen Block reduzieren.
        $result = [];
        $emitted = [];
        foreach ($blocks as $block) {
            $gid = $block->variant_group_id;
            if ($gid === null) {
                $result[] = $block;

                continue;
            }
            if (isset($emitted[$gid])) {
                continue;
            }
            $emitted[$gid] = true;
            $gruppe = array_values(array_filter($blocks, fn ($x) => $x->variant_group_id === $gid));
            $chosenId = $variantChoices[$gid] ?? null;
            $chosen = null;
            if ($chosenId !== null) {
                foreach ($gruppe as $g) {
                    if ((int) $g->id === (int) $chosenId) {
                        $chosen = $g;
                        break;
                    }
                }
            }
            $result[] = $chosen ?? $gruppe[0];
        }

        return $result;
    }

    /**
     * recipe_ref-Block → Portionszahl für N Personen. Ohne Menge: 1 Portion/Person.
     * Mit Menge+Einheit: Portions-Äquivalent × Personen (Gramm-Menge ohne Portionsgewicht
     * ⇒ Warnung + 1/Person). Rundtrip-treu zu rezeptTopBatches() (portions ÷ Portionen/Ansatz).
     */
    private function recipeRefPortionen(FoodAlchemistRecipe $dish, FoodAlchemistFoodbookBlock $block, int $personen, array &$warnungen): float
    {
        $q = $block->quantity !== null ? (float) $block->quantity : null;
        $pae = ConcepterAggregateService::portionsAequivalent($q, $block->unit, $dish);
        if ($pae === null) {
            $warnungen[] = "Kapitel-Gericht „{$dish->name}“: Gramm-Menge ohne Portionsgewicht — 1 Portion/Person angenommen.";
            $pae = 1.0;
        }

        return $pae * $personen;
    }

    /** Gericht-Stammdaten fürs Skalieren (VK-Felder), team-sichtbar. */
    private function ladeGerichtStamm(int $id, Team $team): ?FoodAlchemistRecipe
    {
        return FoodAlchemistRecipe::visibleToTeam($team)
            ->find($id, ['id', 'name', 'is_sales_recipe', 'sales_unit_count', 'sales_quantity_per_unit_g', 'yield_kg', 'yield_pieces']);
    }

    /**
     * Einzel-Rezept-Ziel → Top-Batches. VK: Portionen ÷ Portionszahl; Basisrezept:
     * Ziel = # Ansätze ODER Ziel-Kilogramm (amount_kg, P1). Bei kg werden die Roh-Batches
     * = kg ÷ Basis-Yield zurückgegeben — explodiere() rundet sie auf ganze Ansätze auf
     * (Kern-Entscheid BR: man kocht keinen Teil-Ansatz). yield_kg NULL/0 ⇒ Warnung + 1 Ansatz.
     */
    private function rezeptTopBatches(FoodAlchemistRecipe $recipe, array $ziel, array &$warnungen): array
    {
        $istVk = (bool) $recipe->is_sales_recipe;
        if ($istVk) {
            $portionen = (float) ($ziel['portions'] ?? $ziel['persons'] ?? 0);
            $portionen = $portionen > 0 ? $portionen : 1.0;
            $anzahl = ($recipe->yield_pieces !== null && (float) $recipe->yield_pieces > 0)
                ? (float) $recipe->yield_pieces
                : max(1, (int) ($recipe->sales_unit_count ?? 1));

            return [$portionen / $anzahl, ['modus' => 'portionen', 'wert' => $portionen]];
        }

        // Basisrezept mit kg-Ziel (P1): Roh-Batches = kg ÷ Basis-Yield (explodiere rundet auf).
        if (isset($ziel['amount_kg']) && (float) $ziel['amount_kg'] > 0) {
            $kg = (float) $ziel['amount_kg'];
            $yieldKg = $recipe->yield_kg !== null ? (float) $recipe->yield_kg : 0.0;
            if ($yieldKg <= 0) {
                $warnungen[] = "Basisrezept „{$recipe->name}“: kg-Ziel ohne Basis-Yield — 1 Ansatz angenommen (Yield pflegen).";

                return [1.0, ['modus' => 'kg', 'wert' => $kg]];
            }

            return [$kg / $yieldKg, ['modus' => 'kg', 'wert' => $kg]];
        }

        // Basisrezept solo: Ziel = Anzahl Basis-Ansätze (Default 1).
        $ansaetze = (float) ($ziel['portions'] ?? $ziel['persons'] ?? 1);
        $ansaetze = $ansaetze > 0 ? $ansaetze : 1.0;

        return [$ansaetze, ['modus' => 'ansätze', 'wert' => $ansaetze]];
    }

    // ── Explosion über den Rezeptbaum ─────────────────────────────────────

    /**
     * Kern: Top-Produktionen → Bedarf. VK-Gerichte skalieren linear, Basisrezepte
     * runden auf GANZE Ansätze. Verarbeitung streng von der Top-Ebene nach unten
     * (Longest-Path-Tiefe), damit der Bedarf eines Basisrezepts erst gerundet wird,
     * wenn ALLE Eltern beigetragen haben (Diamond-sicher).
     *
     * @param  list<array{recipe:FoodAlchemistRecipe,batches:float,label:string}>  $tops
     * @return array{production:list<array>, gp:array<int,array>, warnings:list<string>}
     */
    private function explodiere(Team $team, array $tops): array
    {
        if ($tops === []) {
            return ['production' => [], 'gp' => [], 'warnings' => ['Keine skalierbaren Positionen — nichts zu rechnen.']];
        }

        // 1. Baum entdecken: Rezepte + Kanten (Eltern → [Sub, Gramm/Batch]).
        /** @var array<int, list<array{sub:int, gpb:float}>> $kanten */
        $kanten = [];
        /** @var array<int, float> $needBatches Bedarf in Batch-Einheiten (Top-Beitrag + Eltern). */
        $needBatches = [];
        /** @var array<int, FoodAlchemistGp> $gpModelle */
        $gpModelle = [];
        $warnings = [];
        $fehlenderYield = [];

        foreach ($tops as $t) {
            $needBatches[(int) $t['recipe']->id] = ($needBatches[(int) $t['recipe']->id] ?? 0.0) + (float) $t['batches'];
        }

        $entdeckt = [];
        $stack = array_map(fn ($t) => (int) $t['recipe']->id, $tops);
        while ($stack !== []) {
            $rid = array_pop($stack);
            if (isset($entdeckt[$rid])) {
                continue;
            }
            $entdeckt[$rid] = true;
            $recipe = $this->ladeRezept($rid);
            if ($recipe === null) {
                continue;
            }
            foreach ($recipe->ingredients as $z) {
                if ($z->referenced_recipe_id !== null) {
                    $kanten[$rid][] = ['sub' => (int) $z->referenced_recipe_id, 'gpb' => $this->recompute->bruttoMasseG($z)];
                    if (! isset($entdeckt[(int) $z->referenced_recipe_id])) {
                        $stack[] = (int) $z->referenced_recipe_id;
                    }
                }
            }
        }

        // 2. Longest-Path-Tiefe von den Tops.
        $tiefe = [];
        foreach ($tops as $t) {
            $tiefe[(int) $t['recipe']->id] = 0;
        }
        for ($pass = 0; $pass < self::MAX_TIEFE + 1; $pass++) {
            foreach ($kanten as $pid => $subs) {
                if (! isset($tiefe[$pid])) {
                    continue;
                }
                foreach ($subs as $e) {
                    $tiefe[$e['sub']] = max($tiefe[$e['sub']] ?? 0, $tiefe[$pid] + 1);
                }
            }
        }
        asort($tiefe); // Verarbeitung nach Tiefe aufsteigend (Top zuerst)

        // 3. Von oben nach unten: Batches finalisieren, GP-Bedarf sammeln, Sub-Bedarf weiterreichen.
        $produktion = [];
        /** @var array<int, array{grams:float}> $gpGram */
        $gpGram = [];
        foreach (array_keys($tiefe) as $rid) {
            $recipe = $this->ladeRezept($rid);
            if ($recipe === null) {
                continue;
            }
            $roh = $needBatches[$rid] ?? 0.0;
            if ($roh <= 0) {
                continue;
            }
            $istVk = (bool) $recipe->is_sales_recipe;
            // VK-Gericht linear; Basisrezept auf ganze Ansätze aufrunden (Kern-Entscheid).
            $batches = $istVk ? $roh : (float) max(1, (int) ceil($roh - 1e-9));

            $zeilen = [];
            foreach ($recipe->ingredients as $z) {
                $mengeAvg = $z->quantity_max !== null
                    ? ((float) $z->quantity + (float) $z->quantity_max) / 2
                    : (float) $z->quantity;
                $skalMenge = $mengeAvg * $batches;
                $qsOderOpt = $z->is_optional || $z->unit?->slug === 'qs';
                $name = $z->display_name ?: ($z->gp?->name ?? $z->referencedRecipe?->name ?? $z->raw_text);

                if ($z->referenced_recipe_id !== null) {
                    $sub = $z->referencedRecipe;
                    $basisG = (float) ($sub?->yield_kg ?? 0) * 1000;
                    $bruttoProBatch = $this->recompute->bruttoMasseG($z);
                    if ($basisG > 0) {
                        $needBatches[(int) $z->referenced_recipe_id] = ($needBatches[(int) $z->referenced_recipe_id] ?? 0.0)
                            + ($bruttoProBatch * $batches) / $basisG;
                    } else {
                        $fehlenderYield[(int) $z->referenced_recipe_id] = $sub?->name ?? "#{$z->referenced_recipe_id}";
                        $needBatches[(int) $z->referenced_recipe_id] = max($needBatches[(int) $z->referenced_recipe_id] ?? 0.0, 1.0);
                    }
                    $zeilen[] = ['typ' => 'sub', 'name' => $name, 'menge' => round($skalMenge, 3),
                        'einheit' => $z->unit?->slug, 'role' => $z->role, 'note' => $z->note,
                        'ref_recipe_id' => (int) $z->referenced_recipe_id, 'optional' => $qsOderOpt];

                    continue;
                }

                if ($z->gp_id !== null && $z->gp !== null) {
                    if (! $qsOderOpt) {
                        $g = $this->recompute->bruttoMasseG($z) * $batches;
                        if ($g > 0) {
                            $gpGram[$z->gp_id]['grams'] = ($gpGram[$z->gp_id]['grams'] ?? 0.0) + $g;
                            $gpModelle[$z->gp_id] = $z->gp;
                        }
                    }
                    $zeilen[] = ['typ' => 'gp', 'name' => $name, 'menge' => round($skalMenge, 3),
                        'einheit' => $z->unit?->slug, 'role' => $z->role, 'note' => $z->note,
                        'gp_id' => (int) $z->gp_id, 'optional' => $qsOderOpt];

                    continue;
                }

                // ungemappt (weder GP noch Sub) — Bedarfs-Lücke, ehrlich zeigen.
                $warnings[] = "Rezept „{$recipe->name}“: Zutat „{$name}“ ist ungemappt — fehlt im Bedarf.";
                $zeilen[] = ['typ' => 'ungemappt', 'name' => $name, 'menge' => round($skalMenge, 3),
                    'einheit' => $z->unit?->slug, 'role' => $z->role, 'note' => $z->note, 'optional' => $qsOderOpt];
            }

            $basisYieldKg = $recipe->yield_kg !== null ? (float) $recipe->yield_kg : null;
            // VK-Gericht: Portionszahl = Batches × Portionen/Batch (yield_pieces bzw. sales_unit_count).
            // P1 (Spec 17): das Blatt zeigt „N Portionen · gesamt kg" statt des sinnlosen „N× Rezept".
            $anzahlProBatch = ($recipe->yield_pieces !== null && (float) $recipe->yield_pieces > 0)
                ? (float) $recipe->yield_pieces
                : max(1, (int) ($recipe->sales_unit_count ?? 1));
            $produktion[] = [
                'recipe_id' => $rid,
                'name' => $recipe->name,
                'ist_basisrezept' => ! $istVk,
                'tiefe' => $tiefe[$rid],
                'ansaetze' => $istVk ? round($batches, 3) : (int) $batches,
                'portionen' => $istVk ? (int) round($batches * $anzahlProBatch) : null,   // P1: Portionszahl fürs VK-Gericht
                'benoetigt_ansaetze' => round($roh, 3),      // fraktional — Transparenz „ganze Ansätze vs. Bedarf"
                'basis_yield_kg' => $basisYieldKg,
                'produzierte_menge_kg' => $basisYieldKg !== null ? round($basisYieldKg * $batches, 3) : null,
                'arbeitszeit_min' => $recipe->work_time_min !== null ? (int) round((float) $recipe->work_time_min * $batches) : null,
                'zubereitung' => $recipe->preparation ?: null,        // Freitext-Anweisung (kein Step-Datenmodell)
                'darreichung' => $istVk ? $this->darreichungsInfo($recipe) : null, // Regeneration/Behälter/Vehikel der Standard-Form
                'zutaten' => $zeilen,
            ];
        }

        foreach ($fehlenderYield as $name) {
            $warnings[] = "Basisrezept „{$name}“: kein Basis-Yield hinterlegt — 1 Ansatz angenommen (Menge prüfen).";
        }

        // GP-Bedarf mit Namen/EK anreichern.
        $gp = [];
        foreach ($gpGram as $gpId => $d) {
            $model = $gpModelle[$gpId];
            $euroProG = $this->recompute->preisProGrammPublic($model);
            $gp[$gpId] = [
                'gp_id' => $gpId,
                'name' => $model->name,
                'warengruppe' => $model->commodity_group_code,
                'menge_g' => round($d['grams'], 1),
                'menge_kg' => round($d['grams'] / 1000, 3),
                'ek_eur' => $euroProG !== null ? round($d['grams'] * $euroProG, 2) : null,
                'ek_bekannt' => $euroProG !== null,
            ];
        }
        uasort($gp, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return ['production' => $produktion, 'gp' => $gp, 'warnings' => $warnings];
    }

    // ── GP-Bedarf → Lieferanten-Gruppierung (Lead-LA) ─────────────────────

    /**
     * Gruppiert den GP-Bedarf nach Lead-LA-Lieferant. Lead + Ausweich (Rang 2)
     * aus der team-abhängigen Rangliste. EK aus der Lead-€/g-Quelle (identisch
     * zur Rezept-Kalkulation). GPs ohne Lead/Preis → Bucket „ohne Quelle".
     *
     * @param  array<int, array>  $gpBedarf
     */
    private function gruppiereNachLieferant(Team $team, array $gpBedarf): array
    {
        $gruppen = [];
        foreach ($gpBedarf as $b) {
            $gpModel = FoodAlchemistGp::find($b['gp_id']);
            $kette = $gpModel !== null ? $this->leadLa->rangliste($gpModel, $team) : collect();
            $lead = $kette->first(fn ($la) => $la->gepinnt && ! $la->locked) ?? $kette->first(fn ($la) => ! $la->locked);
            $ausweich = $kette->first(fn ($la) => $lead !== null && $la->id !== $lead->id && ! $la->locked);

            // S0 (Spec 17): GP-Bedarf → ganze Bestell-Gebinde des Lead-LA. Aufrundung auf dem
            // bereits je GP aggregierten Bedarf (menge_g), Stück via GP-Stückgewicht (E3).
            $pieceG = $gpModel?->piece_default_g !== null ? (float) $gpModel->piece_default_g : null;
            $geb = $this->gebinde->berechne($lead, (float) $b['menge_g'], $pieceG);
            // Effektive Bestell-Kosten = echte Gebinde-Summe, wenn berechenbar + Preis bekannt;
            // sonst ehrlicher Rückfall auf die Gramm-Theorie (Preisfalle/Stk ohne Gewicht).
            $bestellEk = ($geb['berechenbar'] && $geb['line_total'] !== null) ? $geb['line_total'] : $b['ek_eur'];
            $ekBekannt = $bestellEk !== null;
            // Nicht-berechenbar (Preisfalle/Stk ohne Gewicht) wird pro Zeile über gebinde.grund
            // im Blatt gezeigt — keine globale Warnung nötig.

            $key = $lead?->supplier_id ?? 0;
            $lieferant = $lead?->supplier_name ?? 'ohne Lieferant/Lead-LA';
            $gruppen[$key] ??= ['lieferant' => $lieferant, 'supplier_id' => $lead?->supplier_id, 'positionen' => [], 'ek_summe' => 0.0, 'ek_vollstaendig' => true];

            $gruppen[$key]['positionen'][] = [
                'gp_id' => $b['gp_id'],
                'gp' => $b['name'],
                'lead_la_id' => $lead?->id !== null ? (int) $lead->id : null,  // S2: Andock für Bestellschiene
                'menge_kg' => $b['menge_kg'],
                'menge_g' => $b['menge_g'],
                'ek_eur' => $b['ek_eur'],                 // Gramm-Theorie (Referenz/Rückfall)
                'bestell_ek_eur' => $bestellEk !== null ? round((float) $bestellEk, 2) : null,
                'ek_bekannt' => $ekBekannt,
                'gebinde' => $geb,                        // S0: ganze Gebinde + Artikel-Nr + Preis/Gebinde
                'lead_artikel' => $lead?->designation,
                'lead_artikel_nr' => $lead?->article_number,
                'ausweich' => $ausweich !== null
                    ? ['artikel' => $ausweich->designation, 'lieferant' => $ausweich->supplier_name]
                    : null,
            ];
            if ($ekBekannt) {
                $gruppen[$key]['ek_summe'] += (float) $bestellEk;
            } else {
                $gruppen[$key]['ek_vollstaendig'] = false;
            }
        }

        foreach ($gruppen as &$g) {
            $g['ek_summe'] = round($g['ek_summe'], 2);
            usort($g['positionen'], fn ($a, $b) => strcmp((string) $a['gp'], (string) $b['gp']));
        }
        unset($g);
        // Lieferanten mit Lead zuerst (Bucket „ohne" ans Ende), sonst alphabetisch.
        uasort($gruppen, function ($a, $b) {
            if (($a['supplier_id'] === null) !== ($b['supplier_id'] === null)) {
                return $a['supplier_id'] === null ? 1 : -1;
            }

            return strcmp((string) $a['lieferant'], (string) $b['lieferant']);
        });

        return array_values($gruppen);
    }

    // ── Darreichungs-Info fürs Produktionsblatt (Regeneration/Behälter/Vehikel) ──

    /** @var array<string, ?string> Vokabel-Namen-Memo. */
    private array $vocabCache = [];

    private function vocabName(string $table, $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $key = $table . ':' . $id;

        return $this->vocabCache[$key] ??= (DB::table($table)->where('id', $id)->value('name') ?: null);
    }

    /** Regenerations-/Behälter-/Vehikel-Parameter der Standard-Darreichung (Küchen-Ausgabe). */
    private function darreichungsInfo(FoodAlchemistRecipe $recipe): ?array
    {
        $dar = $this->darreichungen->standardFuer($recipe);
        if ($dar === null) {
            return null;
        }
        $info = array_filter([
            'regeneration_temp_c' => $dar->regeneration_temp_c,
            'regeneration_duration_min' => $dar->regeneration_duration_min,
            'regeneration_core_temp_c' => $dar->regeneration_core_temp_c,
            'geraet' => $this->vocabName('foodalchemist_vocab_regeneration_devices', $dar->regeneration_device_vocab_id),
            'behaelter_warm' => $this->vocabName('foodalchemist_vocab_containers', $dar->container_warm_vocab_id),
            'behaelter_kalt' => $this->vocabName('foodalchemist_vocab_containers', $dar->container_cold_vocab_id),
            'vehikel' => $this->vocabName('foodalchemist_vocab_serving_vehicles', $dar->serving_vehicle_vocab_id),
            'arbeitszeit_zuschlag_min' => $dar->work_time_surcharge_min,
        ], fn ($v) => $v !== null && $v !== '');

        return $info !== [] ? $info : null;
    }

    // ── Rezept-Laden (memoisiert, mit Explosions-Relationen) ──────────────

    private function ladeRezept(int $id): ?FoodAlchemistRecipe
    {
        return $this->recipeCache[$id] ??= FoodAlchemistRecipe::query()
            ->with([
                'ingredients' => fn ($q) => $q->orderBy('position'),
                'ingredients.unit',
                'ingredients.gp',
                'ingredients.referencedRecipe:id,name,is_sales_recipe,yield_kg,yield_pieces,sales_unit_count',
            ])
            ->find($id);
    }
}
