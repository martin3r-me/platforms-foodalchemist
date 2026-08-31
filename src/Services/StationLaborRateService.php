<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;

class StationLaborRateService
{
    public function __construct(private TeamSettingsService $settings) {}

    /**
     * @return array{hourly_rate:float,source:string,warnings:list<string>}
     *
     * Ebene 2: der Betrieb überschreibt Stundensatz UND Lohnquelle (Modus);
     * die Rollen-Sätze selbst (kitchen_roles.stundensatz_eur) bleiben team-eigen.
     */
    public function rate(Team $team, ?FoodAlchemistProductionStation $station, ?FoodAlchemistOutlet $outlet = null): array
    {
        $flat = $this->settings->stundensatz($team, $outlet);
        if ($this->settings->laborCostSource($team, $outlet) !== 'station_roles') {
            return ['hourly_rate' => $flat, 'source' => 'team_flat', 'warnings' => []];
        }
        if ($station === null || $station->koepfe() <= 0) {
            return ['hourly_rate' => $flat, 'source' => 'team_fallback', 'warnings' => ['Posten oder Rollenbesetzung fehlt.']];
        }

        $roles = FoodAlchemistKitchenRole::where('team_id', $team->id)
            ->whereIn('id', array_keys($station->besetzung ?? []))->pluck('stundensatz_eur', 'id');
        // Ebene 2: Betriebs-Rollensatz überschreibt pro Rolle → Team-Rollensatz → flacher Satz.
        $outletRates = $this->settings->outletRoleRates($team, $outlet);
        $sum = 0.0;
        foreach ($station->besetzung ?? [] as $roleId => $count) {
            $satz = $outletRates[(int) $roleId] ?? $outletRates[(string) $roleId] ?? $roles[(int) $roleId] ?? $flat;
            $sum += (int) $count * (float) $satz;
        }

        return [
            'hourly_rate' => $sum / $station->koepfe(),
            'source' => 'station_roles',
            'warnings' => [],
        ];
    }
}
