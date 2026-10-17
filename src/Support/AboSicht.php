<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §12 A1/A2 · Sichtbarkeits-Zweig „sichtbar, wenn in der Hülle eines Abos des Mandanten".
 *
 * Eigene Regel neben 77d (Master → Mandant: keine Abo-Zeile = sieht NICHTS; Oberteam → Unter-Team: keine
 * Freigabe-Zeile = sieht ALLES, §8/§12 A4 — bewusst getrennt). Eingehängt in {@see InhaltsFreigabe::anwenden},
 * damit Modell-Scope und Raw-Scope (TeamScope::applyVisible) dieselbe Regel lesen.
 *
 * - Die Hülle gehört immer dem Kunden-Hauptteam (Abo hat das Oberteam, §12 A2).
 * - Ein Unter-Team sieht Abo-Inhalte wie Inhalte des Oberteams: „übernimmt alles" = ganze Abo-Hülle; eingeschränkt
 *   (77d) = nur, was zusätzlich in SEINER Freigabe-Hülle steht.
 * - Der Master selbst braucht keinen Zweig (seine Zeilen liegen in seiner Kette).
 * - Keine Ausnahme für Plattform-Admins (§13 G): die Regel kennt keinen Benutzer.
 * - Ohne ein einziges Abo im System wird der Zweig gar nicht erst angehängt (keine Last auf demo).
 */
final class AboSicht
{
    public const CACHE_VORHANDEN = 'foodalchemist.abos.vorhanden';

    /** Rezepte, Pakete (PR 2), Konzepte (PR 4, auch kind=paket) und Formate (PR 7; origin=kunde nie, D8). */
    public const TYPEN = ['recipe', 'paket', 'concept', 'format'];

    public static function aktiv(): bool
    {
        return Cache::remember(self::CACHE_VORHANDEN, 60, fn () => Schema::hasTable('foodalchemist_abo_objekte')
            && DB::table('foodalchemist_abo_objekte')->exists());
    }

    public static function vergessen(): void
    {
        Cache::forget(self::CACHE_VORHANDEN);
    }

    /**
     * Kunden-Hauptteam aus der Kette (eigenes Team zuerst … Root): das Team direkt unter dem Master, sonst die Wurzel.
     * Dieselbe Regel wie FaRechte::kundenHauptTeam, ohne zusätzliche Query. `null` = Betrachter ist das Master-Team.
     *
     * @param  list<int>  $kette
     */
    public static function kundenHauptTeamId(array $kette): ?int
    {
        if ($kette === []) {
            return null;
        }
        $master = MasterTeam::id();
        $pos = $master !== null ? array_search($master, $kette, true) : false;

        return match (true) {
            $pos === false => (int) end($kette),
            $pos === 0 => null,
            default => (int) $kette[$pos - 1],
        };
    }

    /**
     * Den Abo-Zweig als OR an die (bereits geklammerte) Sichtbarkeits-Gruppe hängen.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $q
     * @param  array{frei: list<int>, eingeschraenkt: list<int>, huelle: ?int}  $freigabe  Ergebnis von InhaltsFreigabe::fuer
     * @param  list<int>  $kette
     */
    public static function anhaengen($q, string $idSpalte, string $typ, array $kette, array $freigabe): void
    {
        if (($ids = self::objektIds($typ, $kette, $freigabe)) === null) {
            return;
        }
        $q->orWhereIn($idSpalte, $ids);
    }

    /**
     * Abfrage der Objekt-IDs, die das Team über Abos sieht (eine Spalte `objekt_id`), oder `null`, wenn der Zweig entfällt
     * (Typ ohne Abo, kein Abo im System, Betrachter ist der Master). Für das OR ({@see anhaengen}) und für die Menge
     * (UNION ALL, Spec 84 B3) dieselbe Regel.
     *
     * @param  array{frei: list<int>, eingeschraenkt: list<int>, huelle: ?int}  $freigabe
     * @param  list<int>  $kette
     */
    public static function objektIds(string $typ, array $kette, array $freigabe): ?\Illuminate\Database\Query\Builder
    {
        if (! in_array($typ, self::TYPEN, true) || ! self::aktiv()) {
            return null;
        }
        $kh = self::kundenHauptTeamId($kette);
        if ($kh === null) {
            return null;
        }
        $ids = DB::table('foodalchemist_abo_objekte')->select('objekt_id')->where('mandant_team_id', $kh)->where('typ', $typ);
        // Eingeschränktes Unter-Team (77d): nur, was zusätzlich in SEINER Freigabe-Hülle steht
        if (in_array($kh, $freigabe['eingeschraenkt'], true) && $freigabe['huelle'] !== null) {
            $ids->whereIn('objekt_id', fn ($s) => $s->select('objekt_id')->from('foodalchemist_freigabe_objekte')
                ->where('team_id', $freigabe['huelle'])->where('typ', $typ));
        }

        return $ids;
    }
}
