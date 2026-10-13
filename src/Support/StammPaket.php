<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamStammOptin;

/**
 * Spec 82 §12 A3 — Klasse und Opt-in für globale Zeilen (team_id NULL).
 *
 *   basis       = bekommt jedes Team (Regeln, Vokabular, Wissen sind immer basis)
 *   stamm_paket = nur bei Opt-in des Teams oder eines Vorfahren (Mandant = Oberteam)
 *
 * Gilt für GP, Lieferanten, Artikel. Teamgebundene Zeilen (team_id gesetzt) sind nie betroffen.
 *
 * ANNAHME (im PR benannt): Das konfigurierte Master-Team (`foodalchemist.master_team_id`) sieht
 * immer alles, sonst könnte der FA-Admin den Stamm nicht pflegen. Bewusst NUR die Config, nicht
 * TeamScope::isMaster() — dort heißt „elternlos" Master, und auf demo sind alle Teams elternlos.
 */
final class StammPaket
{
    public const BASIS = 'basis';

    public const PAKET = 'stamm_paket';

    public const KLASSEN = [self::BASIS, self::PAKET];

    /** Tabellen mit Spalte `stamm_class`. Preiszeilen bewusst nicht: Klasse folgt dem Artikel (ob das Paket Preise enthält, ist offen). */
    public const TABELLEN = [
        'foodalchemist_gps',
        'foodalchemist_suppliers',
        'foodalchemist_supplier_items',
    ];

    /** @var array<int, bool> je Team-ID, je Prozess/Request */
    private static array $cache = [];

    public static function betrifft(string $tabelle): bool
    {
        return in_array($tabelle, self::TABELLEN, true);
    }

    public static function istFreigeschaltet(?Team $team): bool
    {
        if ($team === null) {
            return false;
        }
        $id = (int) $team->id;
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }

        $kette = TeamScope::ancestryIds($team);
        $master = config('foodalchemist.master_team_id');
        $frei = (is_numeric($master) && in_array((int) $master, $kette, true))
            || DB::table((new FoodAlchemistTeamStammOptin)->getTable())->whereIn('team_id', $kette)->exists();

        return self::$cache[$id] = $frei;
    }

    /** Opt-in für einen Mandanten (idempotent). */
    public static function freischalten(Team $team, ?int $userId = null): FoodAlchemistTeamStammOptin
    {
        $optin = FoodAlchemistTeamStammOptin::firstOrCreate(
            ['team_id' => $team->id],
            ['opted_in_at' => now(), 'opted_in_by' => $userId],
        );
        self::flush();

        return $optin;
    }

    public static function abbestellen(Team $team): bool
    {
        $n = FoodAlchemistTeamStammOptin::where('team_id', $team->id)->delete();
        self::flush();

        return $n > 0;
    }

    /**
     * Zweig „globale Zeilen" für scopeVisibleToTeam: team_id NULL, bei fehlendem Opt-in nur basis.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $q
     */
    public static function globaleZeilen($q, string $teamIdCol, string $klasseCol, ?Team $team): void
    {
        $q->whereNull($teamIdCol);
        if (! self::istFreigeschaltet($team)) {
            $q->where($klasseCol, self::BASIS);
        }
    }

    /**
     * Reiner Zusatzfilter für Raw-Queries, die an visibleToTeam vorbei lesen: blendet globale
     * stamm_paket-Zeilen ohne Opt-in aus. Teamgebundene Zeilen bleiben unberührt.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     * @param  T  $query
     * @return T
     */
    public static function begrenzen($query, string $tabelle, ?Team $team)
    {
        if (! self::betrifft($tabelle) || self::istFreigeschaltet($team)) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNotNull($tabelle . '.team_id')->orWhere($tabelle . '.stamm_class', self::BASIS));
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
