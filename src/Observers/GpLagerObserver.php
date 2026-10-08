<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistStorageBin;
use Platform\FoodAlchemist\Services\LagerEinrichtungService;

/**
 * Spec 67: Ein Grundprodukt bekommt automatisch einen Stellplatz im Standardlager seines Teams,
 * sobald Zustand und Warengruppe feststehen — beim Anlegen oder wenn die Klassifizierung sie
 * nachträgt. Nur wenn das Team Stellplätze eingerichtet hat und noch keiner gesetzt ist; eine
 * Handzuordnung wird nie überschrieben. Darf das Speichern des Grundprodukts nie blockieren.
 */
class GpLagerObserver
{
    public function saved(FoodAlchemistGp $gp): void
    {
        if ($gp->team_id === null || ! ($gp->wasRecentlyCreated || $gp->wasChanged(['condition', 'commodity_group_code']))) {
            return;
        }
        try {
            $ort = FoodAlchemistInventoryLocation::where('team_id', $gp->team_id)->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->first(['id', 'team_id']);
            if ($ort === null || ! FoodAlchemistStorageBin::where('inventory_location_id', $ort->id)->where('is_active', true)->exists()) {
                return;
            }
            $team = Team::find($gp->team_id);
            $svc = app(LagerEinrichtungService::class);
            if ($team === null || isset($svc->stammplaetze($team, (int) $ort->id)[$gp->id])) {
                return;
            }
            $binId = $svc->vorschlagFuer($team, (int) $ort->id, $gp);
            if ($binId !== null) {
                $svc->zuordnen($team, (int) $ort->id, [(int) $gp->id], $binId, 'vorschlag');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
