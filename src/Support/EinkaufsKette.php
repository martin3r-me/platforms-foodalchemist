<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;

/**
 * Spec 85 · Kette der Einkaufsauswahl eines Teams (Leitartikel/Sperren und Favoriten): eigenes Team zuerst, mit Übernahme weiter bis
 * zum Kunden-Hauptteam — nie darüber (Master). Je Auswahl ein eigener Schalter am Kunden-Hauptteam (Dominique 19.10.: zwei Schalter),
 * beide Standard AN (NULL = nie gesetzt). Memo im Container (je Request/Job), nie prozessweit.
 */
final class EinkaufsKette
{
    public const LEITARTIKEL = 'standorte_uebernehmen_leitartikel';

    public const FAVORITEN = 'standorte_uebernehmen_favoriten';

    private const MEMO = 'foodalchemist.einkaufs_ketten';

    /** @return list<int> */
    public static function fuer(Team $team, string $schalter): array
    {
        $memo = app()->bound(self::MEMO) ? app(self::MEMO) : [];
        $k = $schalter . ':' . (int) $team->id;
        if (isset($memo[$k])) {
            return $memo[$k];
        }
        $voll = TeamSicht::gekappt(FoodAlchemistGp::teamAncestryIds($team));
        $haupt = (int) end($voll);
        $an = $haupt === (int) $team->id ? false   // Hauptteam selbst: nichts zu übernehmen
            : (DB::table((new FoodAlchemistTeamSetting)->getTable())->where('team_id', $haupt)->value($schalter) ?? true);

        $memo[$k] = $an ? $voll : [(int) $team->id];
        app()->instance(self::MEMO, $memo);

        return $memo[$k];
    }

    public static function vergessen(): void
    {
        app()->forgetInstance(self::MEMO);
    }
}
