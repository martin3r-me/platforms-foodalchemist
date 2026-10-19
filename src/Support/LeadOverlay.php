<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;

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
    private const MEMO = 'foodalchemist.lead_overlay_ketten';

    /** @return list<int> eigenes Team zuerst; mit Übernahme bis zum Kunden-Hauptteam */
    public static function kette(Team $team): array
    {
        $memo = app()->bound(self::MEMO) ? app(self::MEMO) : [];   // je Request/Job (Container), nie prozessweit
        if (isset($memo[(int) $team->id])) {
            return $memo[(int) $team->id];
        }
        $voll = TeamSicht::gekappt(\Platform\FoodAlchemist\Models\FoodAlchemistGp::teamAncestryIds($team));
        $haupt = (int) end($voll);
        $an = $haupt === (int) $team->id ? false   // Hauptteam selbst: nichts zu übernehmen
            : (DB::table((new FoodAlchemistTeamSetting)->getTable())->where('team_id', $haupt)->value('standorte_uebernehmen_leitartikel') ?? true);

        $memo[(int) $team->id] = $an ? $voll : [(int) $team->id];
        app()->instance(self::MEMO, $memo);

        return $memo[(int) $team->id];
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
        app()->forgetInstance(self::MEMO);
    }
}
