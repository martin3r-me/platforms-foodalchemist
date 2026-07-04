<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistStammLieferant;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use RuntimeException;

/**
 * M1-06 / GL-03+V-27: Stamm-Lieferanten-Matrix — Pflege + Lese-Vertrag für M3-06.
 *
 * Sichtbar = Team-Kette (D1, Kind erbt Eltern-Matrix), Pflege = nur eigene Zeilen.
 */
class StammLieferantService
{
    /** Komplette Matrix je Team-Kette (mit Lieferant), gruppiert nach WG-Code ('' = global). */
    public function matrixFor(Team $team): Collection
    {
        return FoodAlchemistStammLieferant::visibleToTeam($team)
            ->with('supplier:id,name')
            ->orderBy('commodity_group_code')
            ->get();
    }

    /**
     * Lese-Vertrag für die Lead-Wahl (M3-06 / LeadLaStrategieResolver):
     * Stamm-supplier_ids für eine WG = WG-spezifische + globale Stämme der Team-Kette.
     *
     * @return array<int>
     */
    public function stammSupplierIdsFor(Team $team, ?string $warengruppeCode = null): array
    {
        return FoodAlchemistStammLieferant::visibleToTeam($team)
            ->where(fn ($q) => $q->whereNull('commodity_group_code')
                ->when($warengruppeCode, fn ($q2) => $q2->orWhere('commodity_group_code', $warengruppeCode)))
            ->pluck('supplier_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** Stamm setzen (commodity_group_code NULL = global) — idempotent, je Team eigene Zeile. */
    public function setStamm(Team $team, int $supplierId, ?string $warengruppeCode = null): FoodAlchemistStammLieferant
    {
        if (! FoodAlchemistSupplier::visibleToTeam($team)->whereKey($supplierId)->exists()) {
            throw new RuntimeException('Lieferant nicht in der Team-Kette sichtbar.');
        }

        return FoodAlchemistStammLieferant::firstOrCreate([
            'team_id' => $team->id,
            'supplier_id' => $supplierId,
            'commodity_group_code' => $warengruppeCode,
        ]);
    }

    /** Stamm entfernen — nur eigene Zeilen (geerbte Eltern-Einträge sind unantastbar, D1). */
    public function unsetStamm(Team $team, int $supplierId, ?string $warengruppeCode = null): bool
    {
        $zeile = FoodAlchemistStammLieferant::visibleToTeam($team)
            ->where('supplier_id', $supplierId)
            ->when($warengruppeCode === null, fn ($q) => $q->whereNull('commodity_group_code'), fn ($q) => $q->where('commodity_group_code', $warengruppeCode))
            ->first();

        if ($zeile === null) {
            return false;
        }
        if (! $zeile->isOwnedBy($team)) {
            throw new RuntimeException('Geerbter Stamm-Eintrag des Eltern-Teams — nicht entfernbar (D1).');
        }

        return (bool) $zeile->delete();
    }
}
