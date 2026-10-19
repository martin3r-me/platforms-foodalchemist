<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamFavorit;

/**
 * Spec 85 FAV-1 (Dominique 19.10.) · die wirksame Favoritenliste eines Teams — EINE Stelle für Liste, Prompt und Planung.
 * Kette wie beim Leitartikel ({@see EinkaufsKette}, eigener Schalter „Standorte übernehmen unsere Favoriten“): je GP entscheidet die
 * NÄCHSTE Zeile — eigener Favorit oder eigener Ausschluss schlägt die Übernahme vom Hauptteam. Nie vom Master über die Kundengrenze;
 * ein Mandant ohne eigene Liste hat keine Favoriten.
 */
final class FavoritenOverlay
{
    /**
     * @return array<int, array{rang: ?int, eigen: bool}>  gp_id ⇒ wirksamer Favorit (nach Rang sortiert, ohne Rang zuletzt)
     */
    public static function fuer(Team $team): array
    {
        $kette = EinkaufsKette::fuer($team, EinkaufsKette::FAVORITEN);
        $rangInKette = array_flip($kette);
        $zeilen = FoodAlchemistTeamFavorit::query()->whereIn('team_id', $kette)->get(['team_id', 'gp_id', 'rang', 'ausgeschlossen'])
            ->sortBy(fn ($z) => $rangInKette[(int) $z->team_id] ?? PHP_INT_MAX);

        $entschieden = [];
        $out = [];
        foreach ($zeilen as $z) {
            $gp = (int) $z->gp_id;
            if (isset($entschieden[$gp])) {
                continue;   // ein näheres Team hat über diesen GP schon entschieden
            }
            $entschieden[$gp] = true;
            if (! $z->ausgeschlossen) {
                $out[$gp] = ['rang' => $z->rang !== null ? (int) $z->rang : null, 'eigen' => (int) $z->team_id === (int) $team->id];
            }
        }
        // nach Rang (ohne Rang ans Ende); gleicher Rang: der eigene Favorit vor dem übernommenen
        uasort($out, fn ($a, $b) => [$a['rang'] === null ? 1 : 0, $a['rang'] ?? 0, $a['eigen'] ? 0 : 1] <=> [$b['rang'] === null ? 1 : 0, $b['rang'] ?? 0, $b['eigen'] ? 0 : 1]);

        return $out;
    }

    /** @return list<int> GP-ids der wirksamen Favoriten in Rang-Reihenfolge */
    public static function gpIds(Team $team): array
    {
        return array_keys(self::fuer($team));
    }
}
