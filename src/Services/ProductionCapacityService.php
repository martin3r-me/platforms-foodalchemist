<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;

/**
 * Spec 30 E3 — Auslastung der Posten über Tage und Aufträge hinweg (read-only).
 *
 * BEWUSST EIN EIGENER SERVICE, keine Erweiterung von `ProductionOrderService`: der ist schon
 * ~900 Zeilen, besitzt EIN Aggregat (den Auftrag) und liegt im Schreibpfad mit Owner-Guards
 * und Transaktionen. Kapazität ist ein aggregatÜBERGREIFENDES Read-Model über viele Aufträge.
 * Vor allem darf es nie in `recomputeOrder()` gezogen werden — der Recompute muss billig bleiben.
 *
 * ⚠️ TEAM-STRIKT (`o.team_id = :team`), NICHT `visibleToTeam`. Eine Belegung ist eine Messung
 * des eigenen Betriebs, kein vererbbarer Katalog — sonst blockierte die Produktion des
 * Eltern-Betriebs die Posten des Kind-Betriebs. Geerbte Posten sind Vorlagen, keine geteilte
 * Ressource. (Dieselbe Begründung wie bei den `$messreihen` im PolicyTest.)
 *
 * ⚠️ Die Zahlen sind nur so gut wie `work_time_min` am Rezept — und das Feld ist vielfach leer.
 * Deshalb liefert JEDE Summe ihr `ohne_zeit` mit. Eine Kapazitätsampel, die eine halbe
 * Datenlage als Wahrheit verkauft, ist schlimmer als keine.
 */
class ProductionCapacityService
{
    /** Ab hier wird es eng (amber), ab 100 % Überlast (rot). Darunter: keine Meldung. */
    public const SCHWELLE_ENG = 85;

    /** Nur diese Auftrags-Status belegen Kapazität — Erledigtes und Storniertes nicht. */
    private const AKTIVE_STATUS = ['planned', 'in_progress'];

    /**
     * Auslastung je (Tag, Posten) im Fenster.
     *
     * @return array<string, array<int, array{station_id: ?int, station: string, kapazitaet_min: ?int, geplant_min: int, prozent: ?int, zeilen: int, ohne_zeit: int, stufe: string}>>
     *         Tag (Y-m-d) ⇒ Liste der Posten-Buckets; `station_id = null` = „Nicht zugeteilt".
     */
    public function auslastung(Team $team, string $von, string $bis): array
    {
        $roh = DB::table('foodalchemist_production_order_lines as l')
            ->join('foodalchemist_production_orders as o', 'o.id', '=', 'l.production_order_id')
            ->whereNull('l.deleted_at')->whereNull('o.deleted_at')
            ->where('o.team_id', $team->id)                       // strikt, siehe Klassen-Docblock
            ->whereIn('o.status', self::AKTIVE_STATUS)
            ->where('l.is_struck', false)                          // Gestrichenes belegt nichts
            // whereDate statt whereBetween: der `date`-Cast persistiert mit Zeitanteil
            // ('2026-08-20 00:00:00'), ein Ein-Tages-Fenster fiele sonst durch. Dieselbe
            // Falle steht schon in draftForDate. DATE() kostet hier den Index — bei einigen
            // hundert Zeilen je Fenster ist Korrektheit das bessere Geschäft.
            ->whereDate('l.plan_date', '>=', $von)->whereDate('l.plan_date', '<=', $bis)
            ->groupBy('l.plan_date', 'l.station_id')
            ->select([
                'l.plan_date',
                'l.station_id',
                DB::raw('SUM(COALESCE(l.arbeitszeit_min, 0)) as minuten'),
                DB::raw('COUNT(*) as zeilen'),
                DB::raw('SUM(CASE WHEN l.arbeitszeit_min IS NULL THEN 1 ELSE 0 END) as ohne_zeit'),
            ])->get();

        $posten = $this->postenDesTeams($team);

        $ergebnis = [];
        foreach ($roh as $r) {
            $tag = Carbon::parse($r->plan_date)->toDateString();
            $sid = $r->station_id !== null ? (int) $r->station_id : null;
            $station = $sid !== null ? $posten->get($sid) : null;
            $kapazitaet = $station?->kapazitaetAm(Carbon::parse($tag));
            $minuten = (int) $r->minuten;

            $ergebnis[$tag][] = [
                'station_id' => $sid,
                'station' => $sid === null ? 'Nicht zugeteilt' : ($station?->name ?? '— gelöschter Posten —'),
                'kapazitaet_min' => $kapazitaet,
                'geplant_min' => $minuten,
                'prozent' => $kapazitaet > 0 ? (int) round($minuten / $kapazitaet * 100) : null,
                'zeilen' => (int) $r->zeilen,
                'ohne_zeit' => (int) $r->ohne_zeit,
                'stufe' => $this->stufe($minuten, $kapazitaet),
            ];
        }

        foreach ($ergebnis as $tag => $buckets) {
            usort($buckets, fn ($a, $b) => $a['station_id'] === null ? 1 : ($b['station_id'] === null ? -1 : strcmp($a['station'], $b['station'])));
            $ergebnis[$tag] = $buckets;
        }
        ksort($ergebnis);

        return $ergebnis;
    }

    /**
     * Überlast-Meldungen für die Tage, die GENAU DIESER Auftrag berührt — fürs Detail-Panel.
     * Kein globales Banner, keine Benachrichtigung: nur was im Blickfeld liegt.
     *
     * @return list<string>
     */
    public function warnungenFuer(Team $team, int $orderId): array
    {
        $tage = DB::table('foodalchemist_production_order_lines')
            ->where('production_order_id', $orderId)->whereNull('deleted_at')
            ->where('is_struck', false)->whereNotNull('plan_date')
            ->distinct()->pluck('plan_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->sort()->values();

        if ($tage->isEmpty()) {
            return [];
        }

        $warnungen = [];
        foreach ($this->auslastung($team, $tage->first(), $tage->last()) as $tag => $buckets) {
            foreach ($buckets as $b) {
                if ($b['stufe'] === 'ueberlast') {
                    $warnungen[] = sprintf(
                        '%s am %s über Kapazität: %d von %d min (%d %%).',
                        $b['station'], Carbon::parse($tag)->format('d.m.'),
                        $b['geplant_min'], $b['kapazitaet_min'], $b['prozent'],
                    );
                }
            }
        }

        return $warnungen;
    }

    /**
     * Die Zeilen für den Tagesplan — auftragsübergreifend, mit dem Auftrag als Kontext.
     * Ohne ihn weiß am Posten niemand, wofür der Fond eigentlich ist.
     */
    public function tagesplanZeilen(Team $team, string $von, string $bis): Collection
    {
        return DB::table('foodalchemist_production_order_lines as l')
            ->join('foodalchemist_production_orders as o', 'o.id', '=', 'l.production_order_id')
            ->leftJoin('foodalchemist_recipes as r', 'r.id', '=', 'l.recipe_id')
            ->leftJoin('foodalchemist_production_stations as s', 's.id', '=', 'l.station_id')
            ->whereNull('l.deleted_at')->whereNull('o.deleted_at')
            ->where('o.team_id', $team->id)
            ->whereIn('o.status', self::AKTIVE_STATUS)
            ->where('l.is_struck', false)
            ->whereDate('l.plan_date', '>=', $von)->whereDate('l.plan_date', '<=', $bis)
            ->orderBy('l.plan_date')->orderBy('s.sort_order')->orderBy('l.position')
            ->select([
                'l.id', 'l.plan_date', 'l.station_id', 'l.assignee', 'l.arbeitszeit_min',
                'l.ansaetze', 'l.manual_ansaetze', 'l.is_manual_ansaetze', 'l.titel', 'l.vorlauf_tage',
                's.name as station',
                'r.name as rezept',
                'o.id as order_id', 'o.name as auftrag', 'o.production_date as liefertag',
            ])->get()
            ->map(function ($z) {
                $z->name = $z->rezept ?? $z->titel ?? '—';
                $z->ansaetze_effektiv = $z->is_manual_ansaetze && $z->manual_ansaetze !== null
                    ? (float) $z->manual_ansaetze : (float) $z->ansaetze;

                return $z;
            });
    }

    /** Aktive Posten des Teams, keyed by id (≤ ~30 Zeilen — die Kapazität rechnet PHP). */
    private function postenDesTeams(Team $team): Collection
    {
        return FoodAlchemistProductionStation::where('team_id', $team->id)
            ->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
    }

    /**
     * Drei Stufen, aber nur EINE davon meldet sich. Ein Posten ohne hinterlegte Kapazität
     * warnt NIE — Kapazitätsplanung ist opt-in je Posten. Wer keine Zahl einträgt, merkt vom
     * ganzen Feature nichts außer der reinen Minutensumme.
     */
    private function stufe(int $minuten, ?int $kapazitaet): string
    {
        if ($kapazitaet === null || $kapazitaet <= 0) {
            return 'ohne_kapazitaet';
        }
        $pct = $minuten / $kapazitaet * 100;

        return $pct > 100 ? 'ueberlast' : ($pct >= self::SCHWELLE_ENG ? 'eng' : 'ok');
    }
}
