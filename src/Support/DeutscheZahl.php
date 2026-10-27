<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Mengen aus deutschem Freitext (Rezept-Import, Kurator 27.10.): „2.000 ml“ ist zweitausend, nicht zwei — vorher machte
 * str_replace(',', '.') + is_numeric daraus 2.0. Punkt = Tausendertrenner, wenn er Dreiergruppen trennt (1.600, 12.500,
 * 1.234,5) und die Zahl nicht mit 0 beginnt; Komma = Dezimaltrenner. Ein einzelner Punkt vor einer anderen Stellenzahl
 * (2.5, 0.75) bleibt ein Dezimalpunkt. Nicht lesbar (Bereich, Text) ⇒ null — nie raten.
 */
final class DeutscheZahl
{
    public static function alsFloat(mixed $wert): ?float
    {
        if (is_int($wert) || is_float($wert)) {
            return (float) $wert;
        }
        $s = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim((string) $wert));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^[1-9]\d{0,2}(?:\.\d{3})+(?:,\d+)?$/', $s) === 1) {
            return (float) str_replace(['.', ','], ['', '.'], $s);         // 2.000 · 1.600 · 1.234,5
        }
        if (preg_match('/^\d+(?:,\d+)?$/', $s) === 1) {
            return (float) str_replace(',', '.', $s);                        // 12 · 2,5 · 0,5
        }
        if (preg_match('/^\d+\.\d+$/', $s) === 1) {
            return (float) $s;                                               // 2.5 · 0.75 (Dezimalpunkt)
        }

        return null;
    }
}
