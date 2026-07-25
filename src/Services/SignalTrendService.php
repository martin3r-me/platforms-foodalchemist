<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSignalSnapshot;

/**
 * Spec 21 · E1 — Zeitreihe der Qualitäts-Zähler.
 *
 * Bis hierher war jede Aussage über Datenqualität eine Momentaufnahme. Diese Klasse
 * schreibt je Lauf einen dichten Snapshot (alle Lücken-Metriken + offene Signale je
 * Typ) und liest daraus Serie und Delta. Rein messend — sie fasst keine Fach-Daten an.
 *
 * **Dicht, nicht sparsam:** ein Lauf schreibt auch die Nullen. Sonst ist „Befund
 * behoben" (0) nicht von „damals nicht gemessen" (Check gab es noch nicht) zu
 * unterscheiden — und genau diese Unterscheidung braucht das Drift-Signal (E3),
 * damit ein neu gebauter Check nicht als Verschlechterung erscheint.
 *
 * **Ein Lauf = ein `measured_at`** für alle Zeilen. Darauf beruht das Paaren von
 * „letzter Lauf" gegen „Vorlauf"; ein Doppellauf in derselben Sekunde überschreibt
 * (Unique-Index), statt die Reihe zu doppeln.
 */
class SignalTrendService
{
    public function __construct(private DataQualityService $dq)
    {
    }

    /**
     * Schreibt einen Lauf für ein Team.
     *
     * @param  \DateTimeInterface|null  $measuredAt  Lauf-Zeitstempel (Default: jetzt, auf die Sekunde)
     * @return int Anzahl geschriebener Zeilen
     */
    public function schreibeSnapshot(Team $team, ?\DateTimeInterface $measuredAt = null): int
    {
        $at = ($measuredAt ? Carbon::parse($measuredAt) : Carbon::now())->startOfSecond();
        $n = 0;

        // 1) Lücken-Metriken der Ampel (kennen die Kaskade, aber keine Detektor-Typen).
        foreach ($this->dq->messeAlleEbenen($team) as $ebene) {
            foreach ($ebene['metriken'] as $m) {
                if (($m['kind'] ?? null) !== DataQualityService::KIND_GAP) {
                    continue; // Bestands-Totale („GPs approved") sind kein Befund.
                }
                $typ = $m['signal']['typ'] ?? null;
                $this->schreibeZeile($team, FoodAlchemistSignalSnapshot::SOURCE_DQ, (string) $m['key'], $at, (int) $m['wert'], [
                    'signal_type' => $typ instanceof SignalTyp ? $typ->value : null,
                ]);
                $n++;
            }
        }

        // 2) Offene Signale je Typ — deckt die 10 Detektor-Typen ab, für die es keine
        //    Ampel-Metrik gibt (Preis-Anomalie, Vertragsfrist, Widerspruch Wissen↔Graph …).
        $offen = $this->offeneNachTypUndSeverity($team);
        foreach (SignalTyp::cases() as $typ) {
            $counts = $offen[$typ->value] ?? [];
            $this->schreibeZeile($team, FoodAlchemistSignalSnapshot::SOURCE_SIGNALS, $typ->value, $at, (int) array_sum($counts), [
                'signal_type' => $typ->value,
                'severity_counts' => $counts,
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * Serie einer Metrik, **älteste zuerst** (Sparkline-Leserichtung).
     *
     * @return list<array{measured_at:string,count:int,source:string}>
     */
    public function serie(Team $team, string $metricKey, int $limit = 30, ?string $source = null): array
    {
        $rows = FoodAlchemistSignalSnapshot::where('team_id', $team->id)
            ->where('metric_key', $metricKey)
            ->when($source !== null, fn ($q) => $q->where('source', $source))
            ->orderByDesc('measured_at')
            ->limit(max(1, $limit))
            ->get(['source', 'count', 'measured_at']);

        return $rows->reverse()->values()->map(fn ($r) => [
            'measured_at' => (string) $r->measured_at,
            'count' => (int) $r->count,
            'source' => (string) $r->source,
        ])->all();
    }

    /**
     * Letzter Wert einer Metrik gegen den Vorlauf.
     *
     * @return array{count:int,previous:int|null,delta:int|null,pct:float|null,measured_at:string,previous_at:string|null}|null
     *                                                                                                                       null = für diese Metrik gibt es keinen Snapshot
     */
    public function delta(Team $team, string $metricKey, ?string $source = null): ?array
    {
        $rows = FoodAlchemistSignalSnapshot::where('team_id', $team->id)
            ->where('metric_key', $metricKey)
            ->when($source !== null, fn ($q) => $q->where('source', $source))
            ->orderByDesc('measured_at')
            ->limit(2)
            ->get(['count', 'measured_at']);

        if ($rows->isEmpty()) {
            return null;
        }
        $jetzt = (int) $rows[0]->count;
        $vorher = $rows->count() > 1 ? (int) $rows[1]->count : null;

        return [
            'count' => $jetzt,
            'previous' => $vorher,
            'delta' => $vorher === null ? null : $jetzt - $vorher,
            // Prozent nur mit Bezugsgröße: von 0 auf 5 ist keine „+500 %", sondern ein
            // Neuauftreten — das entscheidet E3 anhand von delta, nicht anhand von pct.
            'pct' => ($vorher === null || $vorher === 0) ? null : round((($jetzt - $vorher) / $vorher) * 100, 1),
            'measured_at' => (string) $rows[0]->measured_at,
            'previous_at' => $rows->count() > 1 ? (string) $rows[1]->measured_at : null,
        ];
    }

    /**
     * Alle Metriken des letzten Laufs mit Delta zum Vorlauf — die Cockpit-/MCP-Sicht.
     * Fußt auf „ein Lauf = ein measured_at": die zwei jüngsten Lauf-Zeitstempel des
     * Teams werden gepaart. Metriken, die es im Vorlauf noch nicht gab (neuer Check),
     * kommen mit `previous = null` zurück und gelten nicht als Verschlechterung.
     *
     * @return array{measured_at:string|null,previous_at:string|null,metriken:list<array<string,mixed>>}
     */
    public function uebersicht(Team $team, ?string $source = null): array
    {
        $laeufe = $this->laufZeitpunkte($team, 2);
        if ($laeufe === []) {
            return ['measured_at' => null, 'previous_at' => null, 'metriken' => []];
        }
        $jetztAt = $laeufe[0];
        $vorAt = $laeufe[1] ?? null;

        $lade = fn (string $at) => FoodAlchemistSignalSnapshot::where('team_id', $team->id)
            ->where('measured_at', $at)
            ->when($source !== null, fn ($q) => $q->where('source', $source))
            ->get(['source', 'metric_key', 'signal_type', 'count', 'severity_counts'])
            ->keyBy(fn ($r) => $r->source . '|' . $r->metric_key);

        $jetzt = $lade($jetztAt);
        $vorher = $vorAt !== null ? $lade($vorAt) : collect();

        $metriken = $jetzt->map(function ($r) use ($vorher) {
            $prev = $vorher->get($r->source . '|' . $r->metric_key);
            $prevCount = $prev !== null ? (int) $prev->count : null;
            $count = (int) $r->count;

            return [
                'source' => (string) $r->source,
                'metric_key' => (string) $r->metric_key,
                'label' => $this->label((string) $r->metric_key, $r->signal_type),
                'signal_type' => $r->signal_type,
                'count' => $count,
                'previous' => $prevCount,
                'delta' => $prevCount === null ? null : $count - $prevCount,
                'pct' => ($prevCount === null || $prevCount === 0) ? null : round((($count - $prevCount) / $prevCount) * 100, 1),
                'severity_counts' => $r->severity_counts,
            ];
        })->sortBy('metric_key')->values()->all();

        return ['measured_at' => $jetztAt, 'previous_at' => $vorAt, 'metriken' => $metriken];
    }

    /**
     * Die jüngsten Lauf-Zeitstempel (neuester zuerst).
     *
     * @return list<string>
     */
    public function laufZeitpunkte(Team $team, int $limit = 2): array
    {
        return FoodAlchemistSignalSnapshot::where('team_id', $team->id)
            ->orderByDesc('measured_at')
            ->distinct()
            ->limit(max(1, $limit))
            ->pluck('measured_at')
            ->map(fn ($v) => (string) $v)
            ->values()
            ->all();
    }

    // ---- intern -----------------------------------------------------------

    /**
     * Label für die Anzeige. Signal-Typen bringen ihr Label mit; DQ-Metrik-Keys
     * bleiben bewusst der rohe Key — ihn hier zu spiegeln würde das Label an zwei
     * Stellen pflegen (die Ampel liefert es live und darf es jederzeit ändern).
     */
    private function label(string $metricKey, ?string $signalType): string
    {
        return SignalTyp::tryFrom($signalType ?? $metricKey)?->label() ?? $metricKey;
    }

    private function schreibeZeile(Team $team, string $source, string $key, Carbon $at, int $count, array $extra): void
    {
        FoodAlchemistSignalSnapshot::updateOrCreate(
            ['team_id' => $team->id, 'source' => $source, 'metric_key' => $key, 'measured_at' => $at],
            array_merge(['count' => $count], $extra)
        );
    }

    /**
     * @return array<string,array<string,int>> [typ => [severity => anzahl]]
     *
     * Zählt bewusst mit `visibleToTeam` (nicht strikt team_id): genau so zählt das
     * Cockpit (`SignalService::offeneCount`/`paginate`). Eine Trendzahl, die von der
     * Zahl auf dem Bildschirm abweicht, wäre schlimmer als die Vererbungs-Unschärfe.
     * Die Snapshot-*Zeile* bleibt trotzdem beim messenden Team (s. Migration).
     */
    private function offeneNachTypUndSeverity(Team $team): array
    {
        $rows = FoodAlchemistSignal::visibleToTeam($team)
            ->where('status', SignalStatus::Offen->value)
            ->selectRaw('type, severity, COUNT(*) as c')
            ->groupBy('type', 'severity')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $typ = $r->type instanceof \BackedEnum ? $r->type->value : (string) $r->type;
            $sev = $r->severity instanceof \BackedEnum ? $r->severity->value : (string) $r->severity;
            $out[$typ][$sev] = (int) $r->c;
        }

        return $out;
    }
}
