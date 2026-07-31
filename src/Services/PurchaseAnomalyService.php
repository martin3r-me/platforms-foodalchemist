<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;

/**
 * Einkauf E2 — Preis-Ausreißer im Einkaufsjournal (Fehlbuchungen, die den Vergleich
 * verzerren; z. B. eine Zeile mit 1,00 €/kg statt marktüblich 12,60 €/kg).
 *
 * Methode (aus dem Kollegen-Tool übernommen): NICHT flacher Median pro Artikel — der
 * verpasst Artikel mit echtem Zeittrend (Paprika 2,80 → 5,64 €/kg über 9 Monate).
 * Stattdessen eine robuste THEIL-SEN-Trendlinie pro (Lieferant + Artikel): Steigung =
 * Median aller paarweisen Zeit-Preis-Steigungen, Achsenabschnitt = Median(p − m·t).
 * Ein Punkt gilt als Ausreißer, wenn sein Ist-Preis um Faktor ≥ `$factor` vom
 * Trendwert abweicht (nach oben ODER unten). Fallback bei < `$minPoints` Datenpunkten:
 * flacher globaler Median des Artikels über alle Lieferanten/Zeit.
 *
 * WICHTIG (Knäcke-Brot- vs. Rucola-Lehre): der Dienst FLAGGT nur zur fachlichen Prüfung,
 * er KORRIGIERT nichts automatisch — ein Muster mit vielen Treffern kann ein echter
 * stabiler Preis sein (Trend-Fit-Artefakt) oder ein systematischer Fehler.
 * team-scoped, read-only auf dem Journal.
 */
class PurchaseAnomalyService
{
    /**
     * @return list<array{transaction_id:int,supplier_id:?int,designation:string,purchased_at:?string,actual:float,expected:float,factor:float,method:string,n_points:int}>
     */
    public function detect(Team $team, float $factor = 3.0, int $minPoints = 4): array
    {
        $rows = FoodAlchemistPurchaseTransaction::query()
            ->where('team_id', $team->id)
            ->whereNotNull('unit_price')->where('unit_price', '>', 0)
            ->whereNotNull('purchased_at')
            ->get(['id', 'supplier_id', 'gp_id', 'designation_raw', 'unit_price', 'purchased_at']);

        // Artikel-Schlüssel: bevorzugt GP, sonst roher Name — für den globalen Median (über Lieferanten).
        $artikelKey = fn ($r) => $r->gp_id !== null ? 'gp:' . $r->gp_id : 'name:' . mb_strtolower(trim((string) $r->designation_raw));
        // Serien-Schlüssel: Lieferant + Artikel — für die Theil-Sen-Trendlinie.
        $serieKey = fn ($r) => ($r->supplier_id ?? 0) . '|' . $artikelKey($r);

        // Globaler Median je Artikel (Fallback).
        $globalPrices = [];
        foreach ($rows as $r) {
            $globalPrices[$artikelKey($r)][] = (float) $r->unit_price;
        }
        $globalMedian = [];
        foreach ($globalPrices as $k => $ps) {
            $globalMedian[$k] = $this->median($ps);
        }

        // Serien gruppieren.
        $serien = [];
        foreach ($rows as $r) {
            $serien[$serieKey($r)][] = $r;
        }

        $out = [];
        foreach ($serien as $punkte) {
            $n = count($punkte);
            $ts = [];
            $ps = [];
            $t0 = null;
            // purchased_at ist Carbon (Cast 'date') — (string) → 'Y-m-d H:i:s', strtotime-parsebar.
            foreach ($punkte as $r) {
                $day = (int) floor(strtotime((string) $r->purchased_at) / 86400);
                $t0 = $t0 === null ? $day : min($t0, $day);
            }
            foreach ($punkte as $r) {
                $ts[] = (int) floor(strtotime((string) $r->purchased_at) / 86400) - $t0;
                $ps[] = (float) $r->unit_price;
            }

            $trend = $n >= $minPoints ? $this->theilSen($ts, $ps) : null;

            foreach ($punkte as $i => $r) {
                if ($trend !== null) {
                    $expected = $trend[0] * $ts[$i] + $trend[1];
                    $method = 'theil_sen';
                } else {
                    $expected = $globalMedian[$artikelKey($r)] ?? 0.0;
                    $method = 'global_median';
                }
                if ($expected <= 0.0) {
                    continue;
                }
                $actual = (float) $r->unit_price;
                $verh = $actual / $expected;
                if ($verh >= $factor || $verh <= 1 / $factor) {
                    $out[] = [
                        'transaction_id' => (int) $r->id,
                        'supplier_id' => $r->supplier_id !== null ? (int) $r->supplier_id : null,
                        'designation' => (string) $r->designation_raw,
                        'purchased_at' => $r->purchased_at,
                        'actual' => round($actual, 4),
                        'expected' => round($expected, 4),
                        'factor' => round($verh >= 1 ? $verh : 1 / $verh, 2),
                        'method' => $method,
                        'n_points' => $n,
                    ];
                }
            }
        }

        // Größte Abweichung zuerst — fürs Review.
        usort($out, fn ($a, $b) => $b['factor'] <=> $a['factor']);

        return $out;
    }

    /**
     * Theil-Sen-Schätzer: Steigung = Median aller paarweisen Steigungen,
     * Achsenabschnitt = Median(p − m·t). Null bei < 2 unterschiedlichen t.
     *
     * @param  list<int>  $ts
     * @param  list<float>  $ps
     * @return array{0:float,1:float}|null  [slope, intercept]
     */
    public function theilSen(array $ts, array $ps): ?array
    {
        $slopes = [];
        $n = count($ts);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $dt = $ts[$j] - $ts[$i];
                if ($dt === 0) {
                    continue;
                }
                $slopes[] = ($ps[$j] - $ps[$i]) / $dt;
            }
        }
        if ($slopes === []) {
            return null;
        }
        $slope = $this->median($slopes);

        $inter = [];
        for ($i = 0; $i < $n; $i++) {
            $inter[] = $ps[$i] - $slope * $ts[$i];
        }

        return [$slope, $this->median($inter)];
    }

    /** @param list<float|int> $xs */
    public function median(array $xs): float
    {
        if ($xs === []) {
            return 0.0;
        }
        sort($xs);
        $n = count($xs);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $xs[$mid] : ((float) $xs[$mid - 1] + (float) $xs[$mid]) / 2;
    }
}
