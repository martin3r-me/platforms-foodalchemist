<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistOutletSetting;

/**
 * Ebene 2: Schreibzugriff auf die Outlet-Override-Zeile (Spiegel von
 * TeamSettingsService::for/update, aber je Betrieb). Die LESE-Kaskade
 * (Outlet→Team→Default) lebt einzig in TeamSettingsService::skalar — hier nur
 * owned-Writes des besitzenden Teams. Konsumenten: Einstellungen → Betriebe (UI) + MCP.
 */
class OutletSettingsService
{
    public function for(FoodAlchemistOutlet $outlet): FoodAlchemistOutletSetting
    {
        return FoodAlchemistOutletSetting::firstOrNew([
            'outlet_id' => $outlet->id,
            'team_id' => $outlet->team_id,
        ]);
    }

    /** Nur das Besitzer-Team des Betriebs darf Overrides schreiben. */
    public function update(Team $team, FoodAlchemistOutlet $outlet, array $attributes): FoodAlchemistOutletSetting
    {
        if (! $outlet->isOwnedBy($team)) {
            throw new \RuntimeException('Fremder Betrieb — Override-Pflege nur durchs Besitzer-Team.');
        }
        $row = $this->for($outlet);
        $row->fill($attributes)->save();

        return $row;
    }
}
