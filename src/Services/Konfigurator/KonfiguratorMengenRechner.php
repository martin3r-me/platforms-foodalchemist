<?php

namespace Platform\FoodAlchemist\Services\Konfigurator;

/**
 * Spec 94 · Mengenregeln (fest | schluessel | stufen) für einen Satz Leistungen bei einer Gästezahl. Reine Rechnung ohne
 * Datenbank — Teil des {@see \Platform\FoodAlchemist\Services\KonfiguratorPricingService}, das allein ihn aufruft.
 *
 *   aktiv(l, r)        = (ab_pax leer oder r ≥ ab_pax) und (bis_pax leer oder r ≤ bis_pax)
 *   Bezug(l)           = gaeste: r | leistung: anzahl(bezug_leistung)   (ersetzte Bezugs-Leistung → ihr Ersatz)
 *   regel_anzahl(l, r) = 0 wenn nicht aktiv | fest: anzahl | schluessel: clamp(ceil(Bezug × (1 + reserve) / n), min, max)
 *                        | stufen: anzahl der ersten Stufe mit r ≤ bis_pax (bis_pax leer = offen)
 *   anzahl(l)          = Handwert, wenn gesetzt; sonst regel_anzahl — immer auf ganze Stück aufgerundet.
 */
final class KonfiguratorMengenRechner
{
    /**
     * @param  array<int, object>  $leistungen  alle Leistungen des Konfigurators, Schlüssel = id
     * @param  list<int>  $satz  aktive Leistungen in diesem Kontext
     * @param  array<int, int>  $ersetzt  ersetzte Leistung → Ersatz (nur für Bezüge)
     * @param  (callable(int): ?float)|null  $handwert  Handwert je Leistung oder null
     * @return array<int, array{regel: float, anzahl: float, bezug_fehlt: bool}>
     */
    public function rechne(array $leistungen, array $satz, array $ersetzt, int $pax, ?callable $handwert = null): array
    {
        $imSatz = array_fill_keys($satz, true);
        $ergebnis = [];
        $inArbeit = [];

        $anzahl = function (int $id) use (&$anzahl, &$ergebnis, &$inArbeit, $leistungen, $imSatz, $ersetzt, $pax, $handwert): float {
            if (isset($ergebnis[$id])) {
                return $ergebnis[$id]['anzahl'];
            }
            if (isset($inArbeit[$id]) || ! isset($leistungen[$id], $imSatz[$id])) {
                return 0.0;   // Kreis (wird beim Speichern abgelehnt) oder Bezug außerhalb des Satzes
            }
            $inArbeit[$id] = true;
            $l = $leistungen[$id];
            $bezugFehlt = false;
            $bezugsWert = null;
            if ($l->mengen_regel === 'schluessel' && $l->bezug === 'leistung') {
                $bezugId = (int) $l->bezug_leistung_id;
                $bezugId = $ersetzt[$bezugId] ?? $bezugId;
                $bezugFehlt = ! isset($imSatz[$bezugId]);
                $bezugsWert = $bezugFehlt ? 0.0 : $anzahl($bezugId);
            }
            $regel = $this->regelAnzahl($l, $pax, $bezugsWert);
            $hand = $handwert !== null ? $handwert($id) : null;
            $ergebnis[$id] = ['regel' => $regel, 'anzahl' => $hand !== null ? (float) ceil($hand) : $regel, 'bezug_fehlt' => $bezugFehlt];
            unset($inArbeit[$id]);

            return $ergebnis[$id]['anzahl'];
        };

        foreach ($satz as $id) {
            $anzahl($id);
        }

        return $ergebnis;
    }

    /** Regel-Anzahl einer Leistung bei `$pax` Gästen; `$bezugsWert` = Anzahl der Bezugs-Leistung (bei bezug = leistung). */
    public function regelAnzahl(object $l, int $pax, ?float $bezugsWert = null): float
    {
        if (($l->ab_pax !== null && $pax < (int) $l->ab_pax) || ($l->bis_pax !== null && $pax > (int) $l->bis_pax)) {
            return 0.0;
        }

        $wert = match ($l->mengen_regel) {
            'fest' => (float) ($l->anzahl ?? 0),
            'schluessel' => $this->schluessel($l, $l->bezug === 'leistung' ? (float) ($bezugsWert ?? 0) : (float) $pax),
            'stufen' => $this->stufe($l->stufen_json ?? [], $pax),
            default => 0.0,
        };

        return (float) ceil(round($wert, 6));
    }

    private function schluessel(object $l, float $bezug): float
    {
        $n = (float) ($l->schluessel_n ?? 0);
        $wert = $n > 0 ? ceil(round($bezug * (1 + (float) ($l->reserve_pct ?? 0) / 100) / $n, 6)) : 0.0;
        if ($l->min !== null) {
            $wert = max($wert, (float) $l->min);
        }
        if ($l->max !== null) {
            $wert = min($wert, (float) $l->max);
        }

        return (float) $wert;
    }

    /** @param  list<array{bis_pax:?int, anzahl:float|int|string}>  $stufen */
    private function stufe(array $stufen, int $pax): float
    {
        usort($stufen, fn ($a, $b) => ($a['bis_pax'] ?? PHP_INT_MAX) <=> ($b['bis_pax'] ?? PHP_INT_MAX));
        foreach ($stufen as $s) {
            if (($s['bis_pax'] ?? null) === null || $pax <= (int) $s['bis_pax']) {
                return (float) ($s['anzahl'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * Kreis in den Bezügen (A hängt an B, B an A)? Liefert die Ids des ersten Kreises oder null.
     *
     * @param  array<int, int|null>  $bezuege  Leistung → Bezugs-Leistung
     * @return list<int>|null
     */
    public function findeKreis(array $bezuege): ?array
    {
        foreach (array_keys($bezuege) as $start) {
            $weg = [];
            $id = $start;
            while ($id !== null && isset($bezuege[$id])) {
                if (in_array($id, $weg, true)) {
                    return array_slice($weg, array_search($id, $weg, true));
                }
                $weg[] = $id;
                $id = $bezuege[$id];
            }
        }

        return null;
    }
}
