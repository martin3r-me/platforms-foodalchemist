<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Mengen-Korrektur aus der Bezeichnung (Entscheid Dominique 16.10.2026). Necta Handelshof EDI liefert bei Mehrweg-Getränken
 * `quantity` = Menge der Bestelleinheit / Stückzahl je Gebinde („0,2 APFELSAFT KLAR MW“: 0,2 l / 24 = 0,008 → 131 €/l).
 *
 * Gemessen (00_INBOX/_Necta_EDI_Mengen_Analyse_2026-10-16): Parser am alten Kanal 16555 99,4 % (27.129 / 27.288); am io-Export
 * (225.056 LAs) 161 Kandidaten, alle Handelshof EDI, davon 148 Mehrweg; Stichprobe 50/50 richtig.
 *
 * Regel:
 *  (a) qty ≈ Bezeichnung (±2 % + 0,0005, Necta rundet auf 3 Stellen: 7,5G Majoran = 0,008) → passt, keine Korrektur.
 *  (b) Stück ≥ 2 UND qty ≠ 1 UND qty × Stück ≈ Bezeichnung (±2 % + 0,0005 × Stück) → korrigierte Menge = Menge aus der
 *      Bezeichnung (0,2, nicht 0,192). qty 1 kg/l heißt Bestelleinheit 1 kg/l (Broscheit „2KG KISTE“ qty 1 / 2 Stück ist korrekt).
 *  (c) sonst keine Korrektur (Abtropfgewicht, Bonuspackung … bleiben unangetastet).
 */
final class MengeAusBezeichnung
{
    public const QUELLE = 'bezeichnung_edi';

    private const EINHEIT = ['G' => [0.001, 'kg'], 'KG' => [1.0, 'kg'], 'ML' => [0.001, 'l'], 'CL' => [0.01, 'l'], 'L' => [1.0, 'l'], 'LTR' => [1.0, 'l']];

    /**
     * Menge am Anfang der Bezeichnung: [Menge in Basiseinheit, 'kg'|'l', Menge je Einzelstück bei NxM | null] oder null.
     *
     * @return array{0: float, 1: string, 2: ?float}|null
     */
    public static function parse(?string $bezeichnung): ?array
    {
        $t = mb_strtoupper(trim((string) $bezeichnung));
        if (preg_match('/^(\d+)\s*X\s*(\d+(?:[.,]\d+)?)\s*(KG|G|ML|CL|LTR|L)\b/u', $t, $m)) {
            [$f, $e] = self::EINHEIT[$m[3]];
            $einzel = self::zahl($m[2]) * $f;

            return [(int) $m[1] * $einzel, $e, $einzel];
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(KG|G|ML|CL|LTR|L)\b/u', $t, $m)) {
            [$f, $e] = self::EINHEIT[$m[2]];

            return [self::zahl($m[1]) * $f, $e, null];
        }
        if (preg_match('/^(0[.,]\d{1,3}|[1-9][.,]\d{1,3})\s/u', $t, $m)) {
            return [self::zahl($m[1]), 'l', null];   // „0,2 APFELSAFT“: Getränke ohne Einheit = Liter
        }

        return null;
    }

    /** Korrigierte Menge nach Regel (b) oder null (keine Korrektur). */
    public static function korrektur(?string $bezeichnung, mixed $qty, mixed $stueck, ?string $einheit): ?float
    {
        $p = self::parse($bezeichnung);
        if ($p === null || $qty === null || $qty === '' || ! in_array($einheit, ['kg', 'l'], true) || $einheit !== $p[1]) {
            return null;
        }
        [$menge, , $einzel] = $p;
        $q = (float) $qty;
        $n = $stueck === null || $stueck === '' ? 1.0 : (float) $stueck;
        $direkt = fn (float $soll) => $soll > 0 && abs($soll - $q) <= 0.02 * $soll + 0.0005;
        if ($direkt($menge) || ($einzel !== null && $direkt($einzel))) {
            return null;   // (a) passt
        }
        if ($n < 2 || abs($q - 1.0) < 1e-9) {
            return null;   // keine Teilung möglich bzw. Bestelleinheit 1 kg/l
        }
        $geteilt = fn (float $soll) => $soll > 0 && abs($soll - $q * $n) <= 0.02 * $soll + 0.0005 * $n;
        if ($geteilt($menge)) {
            return round($menge, 4);
        }
        if ($einzel !== null && $geteilt($einzel)) {
            return round($einzel, 4);
        }

        return null;
    }

    private static function zahl(string $s): float
    {
        return (float) str_replace(',', '.', $s);
    }
}
