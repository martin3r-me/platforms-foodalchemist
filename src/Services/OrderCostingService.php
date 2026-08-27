<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/** Pax-abhängige Vollkostenprüfung. Nutzt die Bedarfsexplosion des Produktionsblatts. */
class OrderCostingService
{
    public function __construct(
        private PlanungsblattService $planning,
        private ProductionTimeService $times,
        private StationLaborRateService $laborRates,
        private TeamSettingsService $settings,
        private FixkostenService $fixkosten,
        private ConceptService $concepts,
    ) {
    }

    /** @return array<string,mixed> */
    public function costConcept(Team $team, FoodAlchemistConcept $concept, int $pax): array
    {
        $pax = max(0, $pax);
        $sheet = $this->planning->produktionsblatt($team, ['concept_id' => $concept->id, 'persons' => max(1, $pax)]);
        $recipeIds = collect($sheet['rezepte'])->pluck('recipe_id')->map(fn ($id) => (int) $id)->all();
        $recipes = FoodAlchemistRecipe::whereIn('id', $recipeIds)->get()->keyBy('id');
        $stationIds = $recipes->pluck('default_station_id')->filter()->unique()->all();
        $stations = FoodAlchemistProductionStation::whereIn('id', $stationIds)->get()->keyBy('id');

        $explodedMek = collect($sheet['gp_bedarf'])->sum(fn (array $gp) => (float) ($gp['ek_eur'] ?? 0));
        $mekComplete = collect($sheet['gp_bedarf'])->every(fn (array $gp) => (bool) ($gp['ek_bekannt'] ?? false));
        $activeMinutes = 0.0;
        $fek = 0.0;
        $direct = 0.0;
        $stationLoad = [];
        $warnings = $sheet['warnungen'];

        // Katalog und Auftrag müssen dieselbe Positionsbasis enthalten. Ganze Ansätze
        // können den realen Bedarf erhöhen, aber niemals unter den bereits je
        // Darreichung ausgewiesenen Katalog-MEK drücken. Der Floor verhindert, dass
        // eine unvollständige Explosion eine scheinbar profitable Empfehlung erzeugt.
        $catalogCockpit = $this->concepts->preisCockpit($concept);
        $catalogMekPerPerson = (float) ($catalogCockpit['ek_per_person'] ?? 0);
        $catalogMekTotal = $catalogMekPerPerson * $pax;
        $mek = max($explodedMek, $catalogMekTotal);
        $catalogMekGap = max(0.0, $catalogMekTotal - $explodedMek);
        $catalogMekMismatch = $catalogMekGap > max(0.01, $catalogMekTotal * 0.005);
        if ($catalogMekMismatch) {
            $warnings[] = sprintf(
                'Auftragsexplosion unvollständig: %.2f € Materialbedarf liegen unter %.2f € Katalog-MEK. Für HK2 wurde der Katalog-MEK angesetzt.',
                $explodedMek,
                $catalogMekTotal,
            );
        }

        foreach ($sheet['rezepte'] as $line) {
            $recipe = $recipes->get((int) $line['recipe_id']);
            if ($recipe === null) {
                continue;
            }
            $station = $recipe->default_station_id !== null ? $stations->get((int) $recipe->default_station_id) : null;
            // Kosten folgen dem real produzierten Bedarf. Das Produktionsblatt rundet
            // Basisrezepte auf ganze Ansätze; der fraktionale Bedarf bleibt dort nur
            // als Transparenzwert erhalten.
            $productionBatches = (float) ($line['ansaetze'] ?? $line['benoetigt_ansaetze'] ?? 0);
            $time = $this->times->calculateForBatches($team, $recipe, $productionBatches, $station);
            $rate = $this->laborRates->rate($team, $station);
            $minutes = (float) $time['active_person_minutes'];
            $activeMinutes += $minutes;
            $fek += $minutes / 60 * $rate['hourly_rate'];
            $direct += max(0.0, (float) ($recipe->additional_costs_eur ?? 0)) * max(0.0, $productionBatches);
            $warnings = array_merge($warnings, $time['warnings'], $rate['warnings']);
            $key = $station?->id ?? 0;
            $stationLoad[$key] ??= [
                'station_id' => $station?->id,
                'station' => $station?->name ?? 'Nicht zugeteilt',
                'active_person_minutes' => 0.0,
                'labor_source' => $rate['source'],
            ];
            $stationLoad[$key]['active_person_minutes'] += $minutes;
        }

        // Arbeitgeberanteile genau einmal auf die aufgelösten Rollen-/Teamsätze anwenden.
        $fek *= 1 + $this->settings->lohnnebenkostenPct($team) / 100;
        $schema = array_values(array_filter($this->fixkosten->aufgeloestesSchema($team), fn ($b) => $b['active']));
        foreach ($schema as $block) {
            if ($block['type'] === 'eur_pro_portion') {
                $direct += (float) $block['value'] * $pax;
            }
        }
        $mgk = array_sum(array_map(fn ($b) => $b['type'] === 'pct_mek' ? $mek * (float) $b['value'] / 100 : 0.0, $schema));
        $fgk = array_sum(array_map(fn ($b) => $b['type'] === 'pct_fek' ? $fek * (float) $b['value'] / 100 : 0.0, $schema));
        $hk = $mek + $fek + $direct + $mgk + $fgk;
        $hkSurcharges = array_sum(array_map(fn ($b) => $b['type'] === 'pct_hk' ? $hk * (float) $b['value'] / 100 : 0.0, $schema));
        $hk2 = $hk + $hkSurcharges;
        $target = $hk2 * (1 + $this->settings->margePct($team) / 100);
        $catalogPp = (float) ($catalogCockpit['price_per_person'] ?? 0);
        $catalogTotal = $catalogPp * $pax;

        return [
            'pax' => $pax,
            'catalog_price_per_person' => round($catalogPp, 2),
            'catalog_price_total' => round($catalogTotal, 2),
            'catalog_mek_per_person' => round($catalogMekPerPerson, 4),
            'catalog_mek_total' => round($catalogMekTotal, 4),
            'exploded_mek' => round($explodedMek, 4),
            'mek' => round($mek, 4),
            'fek' => round($fek, 4),
            'direct_costs' => round($direct, 4),
            'mgk' => round($mgk, 4),
            'fgk' => round($fgk, 4),
            'hk' => round($hk, 4),
            'hk2' => round($hk2, 4),
            'minimum_price' => round($hk2, 2),
            'target_price' => round($target, 2),
            'target_price_per_person' => $pax > 0 ? round($target / $pax, 2) : null,
            'contribution_margin' => round($catalogTotal - $hk2, 2),
            'contribution_margin_pct' => $catalogTotal > 0 ? round(($catalogTotal - $hk2) / $catalogTotal * 100, 1) : null,
            'target_gap' => round($target - $catalogTotal, 2),
            'unprofitable' => $catalogTotal + 0.005 < $target,
            'active_person_minutes' => round($activeMinutes, 2),
            'station_load' => array_values(array_map(function (array $row) {
                $row['active_person_minutes'] = round($row['active_person_minutes'], 2);
                return $row;
            }, $stationLoad)),
            'requirements' => $sheet['rezepte'],
            'warnings' => array_values(array_unique($warnings)),
            'complete' => $mekComplete && ! $catalogMekMismatch && $pax > 0,
        ];
    }
}
