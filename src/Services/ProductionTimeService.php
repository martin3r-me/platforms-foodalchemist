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
            // Spec 83 B3 (Entscheid Dominique 14.10.2026): am Gericht ist work_time_min „Pass einrichten“ und zählt EINMAL je
            // Auftragszeile — nicht je Topf-Deckel (20 kg) und nicht je Portion (1 Ansatz = 1 Portion, s. calculateForBatches).
            $operations = 1;
            $effective = ['value' => null, 'source' => 'gericht_einmal'];
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
        $batch = max(0.0, (float) ($recipe->work_time_min ?? 0)) * $operations;
        $variable = $zeitJeMenge['min'] * $variableQuantity;
        $active = $setup + $batch + $variable;
        $passive = max(0.0, (float) ($recipe->standzeit_min ?? 0));

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
            'warnings' => $warnings,
        ];
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
        if (! $recipe->exists) {
            return 0;
        }
        $zeilen = $recipe->relationLoaded('ingredients') ? $recipe->ingredients : $recipe->ingredients()->with('unit')->get();

        return $zeilen->filter(fn ($z) => ! $z->is_optional && $z->unit?->slug !== 'qs')->count();
    }
}
