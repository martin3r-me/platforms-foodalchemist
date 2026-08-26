<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/** Einheitliche Personenzeit für Produktion und Angebotskalkulation. */
class ProductionTimeService
{
    public function __construct(private TeamSettingsService $settings) {}

    /** @return array<string,mixed> */
    public function calculateForBatches(
        Team $team,
        FoodAlchemistRecipe $recipe,
        float $rawBatches,
        ?FoodAlchemistProductionStation $station = null,
    ): array {
        $isPieces = $recipe->istStueckErtrag();
        $yield = $isPieces
            ? (float) ($recipe->yield_pieces ?? 0)
            : (float) ($recipe->yield_kg_manual ?? $recipe->yield_kg ?? 0);
        $quantity = max(0.0, $rawBatches) * $yield;

        return $this->calculate($team, $recipe, $quantity, $isPieces ? 'piece' : 'kg', $station, $rawBatches);
    }

    /** @return array<string,mixed> */
    public function calculate(
        Team $team,
        FoodAlchemistRecipe $recipe,
        float $totalQuantity,
        string $quantityBasis,
        ?FoodAlchemistProductionStation $station = null,
        ?float $rawBatches = null,
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

        if ($totalQuantity > 0 && $effective !== null) {
            $operations = max(1, (int) ceil($totalQuantity / (float) $effective['value'] - 1e-9));
        } else {
            $operations = max(1, (int) ceil((float) ($rawBatches ?? 1) - 1e-9));
        }

        $variableBasis = $recipe->variable_work_time_basis ?: $basis;
        $variableQuantity = $variableBasis === $basis ? max(0.0, $totalQuantity) : 0.0;
        $warnings = [];
        if ((float) ($recipe->variable_work_time_min ?? 0) > 0 && $variableBasis !== $basis) {
            $warnings[] = "Variable Zeit auf {$variableBasis} kann aus {$basis} ohne Umrechnung nicht belastbar berechnet werden.";
        }

        $setup = max(0.0, (float) ($recipe->setup_time_min ?? 0));
        $batch = max(0.0, (float) ($recipe->work_time_min ?? 0)) * $operations;
        $variable = max(0.0, (float) ($recipe->variable_work_time_min ?? 0)) * $variableQuantity;
        $active = $setup + $batch + $variable;
        $passive = max(0.0, (float) ($recipe->standzeit_min ?? 0));

        return [
            'operations' => $operations,
            'batch_limit' => $effective !== null ? (float) $effective['value'] : null,
            'batch_limit_source' => $effective['source'] ?? null,
            'quantity' => max(0.0, $totalQuantity),
            'quantity_basis' => $basis,
            'setup_minutes' => round($setup, 3),
            'batch_minutes' => round($batch, 3),
            'variable_minutes' => round($variable, 3),
            'active_person_minutes' => round($active, 3),
            'passive_minutes' => round($passive, 3),
            'elapsed_minutes' => round($active + $passive, 3),
            'warnings' => $warnings,
        ];
    }
}
