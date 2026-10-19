<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference;

/**
 * Spec 85 Controlling PR-B · wirksames Lead-Overlay (Pin/Sperre aus `gp_la_preferences`) eines Teams — EINE Stelle für Rangliste
 * (LeadLaService) und Kosten (RecipeRecomputeService).
 *
 * Dominique 19.10.: Einstellung „Standorte übernehmen unsere Leitartikel“ am Kunden-Hauptteam, Standard AN. An: Pin des Standorts >
 * Pin des nächsten Oberteams bis zum Kunden-Hauptteam > Plattform-Lead; Sperren gelten entlang derselben Kette. Aus: nur das eigene
 * Team. Die Kette endet an der Kundengrenze — eine Zeile des Masters wirkt nie beim Mandanten.
 */
final class LeadOverlay
{
    /** @return list<int> eigenes Team zuerst; mit Übernahme („Standorte übernehmen unsere Leitartikel“) bis zum Kunden-Hauptteam */
    public static function kette(Team $team): array
    {
        return EinkaufsKette::fuer($team, EinkaufsKette::LEITARTIKEL);
    }

    /**
     * @param  list<int>  $gpIds
     * @return array<int, array<int, array{gepinnt: bool, locked: bool}>>  gp_id ⇒ supplier_item_id ⇒ Overlay
     */
    public static function fuer(Team $team, array $gpIds): array
    {
        if ($gpIds === []) {
            return [];
        }
        $kette = self::kette($team);
        $rang = array_flip($kette);
        $zeilen = FoodAlchemistGpLaPreference::query()->whereIn('team_id', $kette)->whereIn('gp_id', $gpIds)
            ->get(['team_id', 'gp_id', 'supplier_item_id', 'gepinnt', 'locked'])
            ->sortBy(fn ($r) => $rang[(int) $r->team_id] ?? PHP_INT_MAX);

        $out = [];
        $pinVon = [];   // gp_id ⇒ Team-Rang des gewählten Pins (eigener Pin schlägt den des Oberteams)
        foreach ($zeilen as $r) {
            $gp = (int) $r->gp_id;
            $la = (int) $r->supplier_item_id;
            $out[$gp][$la] ??= ['gepinnt' => false, 'locked' => false];
            $out[$gp][$la]['locked'] = $out[$gp][$la]['locked'] || (bool) $r->locked;
            if ($r->gepinnt && ! isset($pinVon[$gp])) {
                $pinVon[$gp] = true;
                $out[$gp][$la]['gepinnt'] = true;
            }
        }

        return $out;
    }

    /** Nach einer Einstellungs-Änderung (Team-Einstellungen speichern). */
    public static function vergessen(): void
    {
        EinkaufsKette::vergessen();
    }
}
