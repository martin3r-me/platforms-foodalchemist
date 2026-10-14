<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/** Einheitliche Personenzeit für Produktion und Angebotskalkulation. */
class ProductionTimeService
{
    /**
     * Spec 83 B3/B5 (Entscheid Dominique 14.10.2026): Anrichten am Gericht = Zahl der Teller-Komponenten × 5–10 s je Portion.
     * Startwert 5 s = unteres Ende der Spannweite: 4–7 Komponenten ergeben 0,33–0,58 min je Portion, das trifft den B3-Richtwert
     * 0,3–0,6 min. Quelle: Spec 83 B5, ungeprüft, Abgleich mit dem Küchenchef offen.
     */
    public const ANRICHTEN_SEKUNDEN_JE_KOMPONENTE = 5.0;

    /**
     * Spec 83 B3d (Entscheid Dominique 14.10.2026): Regenerieren = Personenzeit je Regenerier-Behälter (Bestücken und
     * Entnehmen), Startwert 30 s je GN-Behälter. Quelle: Spec 83, ungeprüft, Abgleich mit dem Küchenchef offen.
     */
    public const REGENERIEREN_SEKUNDEN_JE_BEHAELTER = 30.0;

    public function __construct(private TeamSettingsService $settings) {}

    /** @return array<string,mixed> */
    public function calculateForBatches(
        Team $team,
        FoodAlchemistRecipe $recipe,
        float $rawBatches,
        ?FoodAlchemistProductionStation $station = null,
    ): array {
        $isPieces = $recipe->istStueckErtrag();
        $batches = max(0.0, $rawBatches);
        $yieldKg = (float) ($recipe->yield_kg_manual ?? $recipe->yield_kg ?? 0);
        $yieldPieces = (float) ($recipe->yield_pieces ?? 0);
        // Spec 83 B3: am Gericht ohne sales_unit_count gilt 1 Ansatz = 1 Portion — wie in Planungsblatt/Darreichung,
        // die Gerichts-Ansätze ohnehin als Portionen ÷ max(1, sales_unit_count) rechnen.
        $portionsPerBatch = (float) ($recipe->sales_unit_count ?? 0);
        if ($portionsPerBatch <= 0 && $recipe->is_sales_recipe) {
            $portionsPerBatch = 1.0;
        }
        $quantities = [
            'kg' => $yieldKg > 0 ? $batches * $yieldKg : null,
            'piece' => $yieldPieces > 0 ? $batches * $yieldPieces : null,
            'portion' => $portionsPerBatch > 0 ? $batches * $portionsPerBatch : null,
        ];
        $basis = $isPieces ? 'piece' : 'kg';
        $quantity = (float) ($quantities[$basis] ?? 0);
        $variableBasis = $this->zeitJeMenge($recipe, $basis)['basis'];

        return $this->calculate(
            $team,
            $recipe,
            $quantity,
            $basis,
            $station,
            $rawBatches,
            $quantities[$variableBasis] ?? null,
        );
    }

    /** @return array<string,mixed> */
    public function calculate(
        Team $team,
        FoodAlchemistRecipe $recipe,
        float $totalQuantity,
        string $quantityBasis,
        ?FoodAlchemistProductionStation $station = null,
        ?float $rawBatches = null,
        ?float $variableQuantityOverride = null,
    ): array {
        $basis = in_array($quantityBasis, ['kg', 'piece', 'portion'], true) ? $quantityBasis : 'kg';
        $isPieces = $basis === 'piece';
        $recipeCap = $isPieces ? $recipe->batch_max_pieces : $recipe->batch_max_kg;
        $stationCap = $isPieces ? $station?->batch_max_pieces : $station?->batch_max_kg;
        $teamCap = $isPieces ? $this->settings->defaultTopfDeckelStueck($team) : $this->settings->defaultTopfDeckelKg($team);
        $caps = array_values(array_filter([
            ['value' => $recipeCap, 'source' => 'recipe'],
            ['value' => $stationCap, 'source' => 'station'],
            ['value' => $teamCap, 'source' => 'team_default'],
        ], fn (array $cap) => $cap['value'] !== null && (float) $cap['value'] > 0));
        usort($caps, fn (array $a, array $b) => (float) $a['value'] <=> (float) $b['value']);
        $effective = $caps[0] ?? null;

        if ($recipe->is_sales_recipe) {
            // Spec 83 B3j (Entscheid Dominique 14.10.2026, ersetzt B3b): am Gericht gibt es kein „Pass einrichten“ mehr —
            // work_time_min zählt dort NICHT (Daten bleiben stehen). Ein Vorgang, keine Topf-Deckel-Rechnung.
            $operations = 1;
            $effective = ['value' => null, 'source' => 'gericht'];
        } elseif ($totalQuantity > 0 && $effective !== null) {
            $operations = max(1, (int) ceil($totalQuantity / (float) $effective['value'] - 1e-9));
        } else {
            $operations = max(1, (int) ceil((float) ($rawBatches ?? 1) - 1e-9));
        }

        $zeitJeMenge = $this->zeitJeMenge($recipe, $basis);
        $variableBasis = $zeitJeMenge['basis'];
        $variableQuantity = $variableBasis === $basis
            ? max(0.0, $totalQuantity)
            : max(0.0, (float) ($variableQuantityOverride ?? 0));
        $warnings = [];
        if ($zeitJeMenge['min'] > 0
            && $variableBasis !== $basis
            && $variableQuantityOverride === null) {
            $warnings[] = "Variable Zeit auf {$variableBasis} kann aus {$basis} ohne Umrechnung nicht belastbar berechnet werden.";
        }

        // Entscheid 2026-09-04: Ruestzeit ist eine Herstellungs-Groesse und gilt nur am
        // Basisrezept. Am Gericht wird sie ignoriert — sonst rechneten Alt-Werte, die die
        // UI nicht mehr zeigt, still weiter in die Personenminuten.
        $setup = $recipe->is_sales_recipe ? 0.0 : max(0.0, (float) ($recipe->setup_time_min ?? 0));
        $batch = $recipe->is_sales_recipe ? 0.0 : max(0.0, (float) ($recipe->work_time_min ?? 0)) * $operations;
        $variable = $zeitJeMenge['min'] * $variableQuantity;
        $passive = max(0.0, (float) ($recipe->standzeit_min ?? 0));

        // Spec 83 B3c/B3j: am Gericht zwei getrennte Blöcke — Regenerieren und Anrichten (Pass einrichten entfällt).
        $bloecke = null;
        $regen = 0.0;
        if ($recipe->is_sales_recipe) {
            $r = $this->regenerieren($team, $recipe, $rawBatches);
            $regen = $r['minuten'];
            $passive += $r['geraete_min'];                   // Gerätezeit: passiv, kein Lohn, nur Durchlaufzeit
            $warnings = array_merge($warnings, $r['warnungen']);
            unset($r['warnungen']);
            // B3h: beide Blöcke anteilig nach Komponenten auf die beteiligten Posten (Sammel-Pass trägt nichts)
            $anteile = $this->postenAnteile($recipe);
            $bloecke = [
                'regenerieren' => ['minuten' => round($regen, 3)] + $r + ['aufteilung' => $this->verteile($anteile, $regen)],
                'anrichten' => ['minuten' => round($variable, 3), 'quelle' => $zeitJeMenge['quelle'],
                    'komponenten' => $zeitJeMenge['komponenten'], 'je_einheit_min' => round($zeitJeMenge['min'], 4),
                    'je_teller_s' => $variableBasis === 'portion' ? round($zeitJeMenge['min'] * 60, 1) : null,
                    'basis' => $variableBasis, 'aufteilung' => $this->verteile($anteile, $variable)],
            ];
        }
        $active = $setup + $batch + $regen + $variable;

        return [
            'operations' => $operations,
            'batch_limit' => $effective !== null && $effective['value'] !== null ? (float) $effective['value'] : null,
            'batch_limit_source' => $effective['source'] ?? null,
            'quantity' => max(0.0, $totalQuantity),
            'quantity_basis' => $basis,
            'setup_minutes' => round($setup, 3),
            'batch_minutes' => round($batch, 3),
            'variable_minutes' => round($variable, 3),
            'variable_quantity' => round($variableQuantity, 3),
            'variable_quantity_basis' => $variableBasis,
            'variable_time_per_unit' => round($zeitJeMenge['min'], 4),
            'variable_time_source' => $zeitJeMenge['quelle'],          // gepflegt | komponenten | null
            'anrichten_komponenten' => $zeitJeMenge['komponenten'],    // nur bei abgeleiteter Anrichte-Zeit
            'active_person_minutes' => round($active, 3),
            'passive_minutes' => round($passive, 3),
            'elapsed_minutes' => round($active + $passive, 3),
            'regenerieren_minutes' => round($regen, 3),
            'bloecke' => $bloecke,                                      // nur am Gericht, sonst null
            'warnings' => $warnings,
        ];
    }

    /**
     * Regenerier-Block eines Gerichts für eine Zahl Ansätze (= Portionen, s. calculateForBatches):
     * Personenzeit = Regenerier-Behälter ({@see BehaelterBedarfService::regenerierBehaelter}) × 30 s;
     * Gerätezeit = längstes Programm aus der Regenerations-Kaskade (Spec 51), getrennt und passiv.
     *
     * @return array{minuten: float, behaelter: ?int, sekunden_je_behaelter: float, geraete_min: float, quelle: ?string, warnungen: list<string>}
     */
    public function regenerieren(Team $team, FoodAlchemistRecipe $gericht, ?float $ansaetze): array
    {
        $kaskade = app(RegenerationCascadeService::class)->fuerRezept($gericht);
        $geraete = collect([...$kaskade['gesamt'], ...$kaskade['komponenten']])
            ->pluck('duration_min')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->max() ?? 0.0;
        $leer = ['minuten' => 0.0, 'behaelter' => null, 'sekunden_je_behaelter' => self::REGENERIEREN_SEKUNDEN_JE_BEHAELTER,
            'geraete_min' => (float) $geraete, 'quelle' => null, 'warnungen' => []];
        if ($ansaetze === null || ! $gericht->exists) {
            return $leer;
        }
        $b = app(BehaelterBedarfService::class)->regenerierBehaelter($team, $gericht, $ansaetze);
        $warnungen = $b['nicht_berechenbar'] === [] ? [] : [sprintf('Regenerier-Behälter nicht berechenbar für %s.', implode(', ', $b['nicht_berechenbar']))];
        if ($b['anzahl'] <= 0) {
            return ['warnungen' => $warnungen] + $leer;
        }

        return ['minuten' => $b['anzahl'] * self::REGENERIEREN_SEKUNDEN_JE_BEHAELTER / 60.0, 'behaelter' => $b['anzahl'],
            'sekunden_je_behaelter' => self::REGENERIEREN_SEKUNDEN_JE_BEHAELTER, 'geraete_min' => (float) $geraete,
            'quelle' => 'behaelter', 'warnungen' => $warnungen];
    }

    /**
     * Zeit pro Menge eines Rezepts. Gepflegt (`variable_work_time_min` > 0, egal welche Basis) gewinnt immer. Sonst am Gericht
     * die abgeleitete Anrichte-Zeit je Portion: Teller-Komponenten × {@see ANRICHTEN_SEKUNDEN_JE_KOMPONENTE} (Spec 83 B3).
     *
     * @return array{min: float, basis: string, quelle: ?string, komponenten: ?int}
     */
    public function zeitJeMenge(FoodAlchemistRecipe $recipe, string $fallbackBasis = 'kg'): array
    {
        $gepflegt = max(0.0, (float) ($recipe->variable_work_time_min ?? 0));
        if ($gepflegt > 0 || ! $recipe->is_sales_recipe) {
            return ['min' => $gepflegt, 'basis' => $recipe->variable_work_time_basis ?: $fallbackBasis,
                'quelle' => $gepflegt > 0 ? 'gepflegt' : null, 'komponenten' => null];
        }
        $n = $this->tellerKomponenten($recipe);

        return ['min' => $n * self::ANRICHTEN_SEKUNDEN_JE_KOMPONENTE / 60.0, 'basis' => 'portion',
            'quelle' => $n > 0 ? 'komponenten' : null, 'komponenten' => $n];
    }

    /**
     * Teller-Komponenten eines Gerichts = seine Zutatenzeilen (je Zeile ein Bestandteil, der auf den Teller kommt: Sub-Rezept
     * wie Jus/Püree oder GP wie Garnitur). Nicht gezählt: optionale Zeilen und „qs“ (Salz, Pfeffer nach Geschmack) — die werden
     * nicht einzeln angerichtet.
     */
    public function tellerKomponenten(FoodAlchemistRecipe $recipe): int
    {
        return count($this->tellerKomponentenZeilen($recipe));
    }

    /** @return list<\Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient> */
    private function tellerKomponentenZeilen(FoodAlchemistRecipe $recipe): array
    {
        if (! $recipe->exists) {
            return [];
        }
        $zeilen = $recipe->relationLoaded('ingredients') ? $recipe->ingredients : $recipe->ingredients()->with('unit')->get();

        return $zeilen->filter(fn ($z) => ! $z->is_optional && $z->unit?->slug !== 'qs')->values()->all();
    }

    /**
     * Spec 83 B3g/B3h (Entscheide Dominique 14.10.2026): Der Pass ist ein Sammelposten ohne eigenes Personal. Regenerieren und
     * Anrichten eines Gerichts belasten die BETEILIGTEN Posten anteilig nach ihren Komponenten. Posten einer
     * Komponente = Posten ihres Sub-Rezepts (`default_station_id`, dieselbe Quelle wie das Routing der Auftragszeilen).
     * Komponenten ohne Posten (z. B. GP-Garnitur) gehen an den Posten des Gerichts, wenn der kein Sammelposten ist; sonst als
     * eigene Position „nicht zugeteilt" (station_id null). Ohne zählbare Komponente trägt der Gerichts-Posten alles (bzw.
     * „nicht zugeteilt", wenn er Sammelposten ist). Geraten wird nichts.
     *
     * @return list<array{station_id:?int, komponenten:int, anteil:float}>
     */
    public function postenAnteile(FoodAlchemistRecipe $gericht): array
    {
        $gerichtPosten = $gericht->default_station_id !== null && ! $this->istSammelposten((int) $gericht->default_station_id)
            ? (int) $gericht->default_station_id : null;
        $zeilen = $this->tellerKomponentenZeilen($gericht);
        if ($zeilen === []) {
            return [['station_id' => $gerichtPosten, 'komponenten' => 0, 'anteil' => 1.0]];
        }
        $subPosten = FoodAlchemistRecipe::whereIn('id', array_filter(array_map(fn ($z) => $z->referenced_recipe_id, $zeilen)))
            ->pluck('default_station_id', 'id');
        $je = [];
        foreach ($zeilen as $z) {
            $posten = $z->referenced_recipe_id !== null ? ($subPosten[$z->referenced_recipe_id] ?? null) : null;
            $posten = $posten !== null && ! $this->istSammelposten((int) $posten) ? (int) $posten : $gerichtPosten;
            $je[$posten ?? 0] = ($je[$posten ?? 0] ?? 0) + 1;
        }
        $n = count($zeilen);
        $raus = [];
        foreach ($je as $key => $anzahl) {
            $raus[] = ['station_id' => $key === 0 ? null : (int) $key, 'komponenten' => $anzahl, 'anteil' => round($anzahl / $n, 4)];
        }

        return $raus;
    }

    /**
     * @param  list<array{station_id:?int, komponenten:int, anteil:float}>  $anteile
     * @return list<array{station_id:?int, komponenten:int, anteil:float, minuten:float}>
     */
    private function verteile(array $anteile, float $minuten): array
    {
        if ($minuten <= 0) {
            return [];
        }
        $summe = array_sum(array_column($anteile, 'komponenten')) ?: 1;

        return array_map(fn ($a) => $a + ['minuten' => round($minuten * ($a['komponenten'] ?: $summe) / $summe, 3)], $anteile);
    }

    /** @var array<int,bool> */
    private array $sammelposten = [];

    private ?bool $hatSammelSpalte = null;

    /** Peters Merkmal `production_stations.ist_sammelposten` (Spec 83 B2); ohne Spalte (vor seiner Migration) = kein Sammelposten. */
    private function istSammelposten(int $stationId): bool
    {
        if (! array_key_exists($stationId, $this->sammelposten)) {
            $this->hatSammelSpalte ??= \Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_production_stations', 'ist_sammelposten');
            $this->sammelposten[$stationId] = $this->hatSammelSpalte
                && (bool) \Illuminate\Support\Facades\DB::table('foodalchemist_production_stations')->where('id', $stationId)->value('ist_sammelposten');
        }

        return $this->sammelposten[$stationId];
    }
}
