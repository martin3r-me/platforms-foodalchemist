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

    /** Tabelle der Lieferanten-Freigabe je Mandant (Haupt-Team): welche Stamm-Lieferanten sieht das Team, samt Artikeln. */
    public const LIEFERANTEN_TABELLE = 'foodalchemist_team_stamm_lieferanten';

    /** @var array<int, bool> je Team-ID, je Prozess/Request */
    private static array $cache = [];

    /** @var array<int, bool> je Team-ID: Master-Kette = sieht alle Stamm-Lieferanten ungefiltert */
    private static array $alleCache = [];

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
        $master = MasterTeam::id();
        $frei = ($master !== null && in_array($master, $kette, true))
            || DB::table((new FoodAlchemistTeamStammOptin)->getTable())->whereIn('team_id', $kette)->exists();

        return self::$cache[$id] = $frei;
    }

    /**
     * Spec 82 (Lieferanten freischalten): Das Master-Team (und seine Unter-Teams) sieht ALLE Stamm-Lieferanten und ihre Artikel. Jedes andere Team
     * mit Paket sieht nur die ausdrücklich freigeschalteten (Standard bei neuer Buchung: keine). Ohne Paket gilt unverändert nur `basis`.
     */
    public static function sieheAlleLieferanten(?Team $team): bool
    {
        if ($team === null) {
            return false;
        }
        $id = (int) $team->id;
        if (isset(self::$alleCache[$id])) {
            return self::$alleCache[$id];
        }
        $master = MasterTeam::id();

        return self::$alleCache[$id] = $master !== null && in_array($master, TeamScope::ancestryIds($team), true);
    }

    /**
     * Subquery auf die freigeschalteten Lieferanten-IDs der Team-Kette (Haupt-Team + Eltern; Unter-Teams erben die Auswahl wie das Paket).
     * Als Closure für `whereIn`: kein Array über alle Lieferanten, kein statischer Cache, der Queue-/Request-Grenzen überleben könnte.
     */
    public static function freigegebeneLieferanten(?Team $team): \Closure
    {
        $kette = TeamScope::ancestryIds($team);

        return static fn ($sub) => $sub->select('supplier_id')->from(self::LIEFERANTEN_TABELLE)->whereIn('team_id', $kette);
    }

    /** Spalte mit der Lieferanten-ID je Stamm-Tabelle (`i.supplier_id` am Artikel, `s.id` am Lieferanten); GPs haben keine. */
    private static function lieferantenSpalte(string $praefix, string $basisTabelle): ?string
    {
        return match ($basisTabelle) {
            'foodalchemist_suppliers' => $praefix . '.id',
            'foodalchemist_supplier_items' => $praefix . '.supplier_id',
            default => null,
        };
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
        // Einzeln über das Modell löschen: Query-Löschungen feuern keine Eloquent-Ereignisse und blieben im activity-log unsichtbar.
        $zeilen = FoodAlchemistTeamStammOptin::where('team_id', $team->id)->get();
        $zeilen->each->delete();
        // Die Lieferanten-Auswahl gehört zur Buchung: wer abbestellt, startet bei einer neuen Buchung wieder bei „keine".
        if (\Illuminate\Support\Facades\Schema::hasTable(self::LIEFERANTEN_TABELLE)) {
            DB::table(self::LIEFERANTEN_TABELLE)->where('team_id', $team->id)->delete();
        }
        self::flush();

        return $zeilen->isNotEmpty();
    }

    /**
     * Zweig „globale Zeilen" für scopeVisibleToTeam: team_id NULL, bei fehlendem Opt-in nur basis.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $q
     */
    public static function globaleZeilen($q, string $teamIdCol, string $klasseCol, ?Team $team, ?string $basisTabelle = null): void
    {
        $q->whereNull($teamIdCol);
        if (! self::istFreigeschaltet($team)) {
            $q->where($klasseCol, self::BASIS);

            return;
        }
        // Mit Paket, aber nicht Master: stamm_paket-Lieferanten und -Artikel nur für freigeschaltete Lieferanten (basis bleibt für alle).
        $spalte = $basisTabelle !== null && ! self::sieheAlleLieferanten($team)
            ? self::lieferantenSpalte(str_contains($klasseCol, '.') ? strstr($klasseCol, '.', true) : $basisTabelle, $basisTabelle)
            : null;
        if ($spalte !== null) {
            $q->where(fn ($w) => $w->where($klasseCol, self::BASIS)->orWhereIn($spalte, self::freigegebeneLieferanten($team)));
        }
    }

    /**
     * Reiner Zusatzfilter für Raw-Queries, die an visibleToTeam vorbei lesen: blendet globale
     * stamm_paket-Zeilen ohne Opt-in aus. Teamgebundene Zeilen bleiben unberührt. Mit Alias: `$basisTabelle` angeben.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     * @param  T  $query
     * @return T
     */
    public static function begrenzen($query, string $tabelle, ?Team $team, ?string $basisTabelle = null)
    {
        // `$tabelle` ist Tabellenname ODER Alias (`i`, `g`); bei Alias nennt `$basisTabelle` die echte Tabelle (sonst greift `betrifft` nie).
        $basis = $basisTabelle ?? $tabelle;
        if (! self::betrifft($basis) || self::sieheAlleLieferanten($team)) {
            return $query;
        }
        if (! self::istFreigeschaltet($team)) {
            return $query->where(fn ($q) => $q->whereNotNull($tabelle . '.team_id')->orWhere($tabelle . '.stamm_class', self::BASIS));
        }
        // Paket gebucht: GPs bleiben sichtbar; Lieferanten und Artikel nur freigeschaltete (Spec 82 Lieferanten freischalten).
        $spalte = self::lieferantenSpalte($tabelle, $basis);
        if ($spalte === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNotNull($tabelle . '.team_id')->orWhere($tabelle . '.stamm_class', self::BASIS)
            ->orWhereIn($spalte, self::freigegebeneLieferanten($team)));
    }

    public static function flush(): void
    {
        self::$cache = [];
        self::$alleCache = [];
        MasterTeam::flush();   // hängt an derselben Kette: Master-Wechsel ändert, wer das Paket immer hat
    }
}
