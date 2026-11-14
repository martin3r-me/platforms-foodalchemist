<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Fettwert im GP-Namen (GP-Regelwerk v3.10.0, Regel 826 + 75 / §8.1, Entscheid Dominique 13.11.2026, M2/M4):
 * - exakt wie im Artikel, keine Fettklassen, jeder Wert ein eigenes GP;
 * - Schreibweise `N % Fett` bzw. `N % Fett i. Tr.` (Käse, Quark, Frischkäse): Dezimalkomma, Leerzeichen vor dem Prozentzeichen;
 * - Platz: immer direkt hinter dem Zustand, nie davor.
 *
 * Erkannt wird nur ein Teil, der das Wort „Fett“ (oder „F. i. Tr.“) trägt — ein nacktes „N %“ kann Kakao-, Frucht- oder Alkoholanteil sein und
 * bleibt unangetastet (die Prüfung `gp.8.1.fett` meldet es bei Milchprodukten).
 */
final class FettAngabe
{
    private const ZAHL = '(\d+(?:[.,]\d+)?)';

    private const ITR = '(?:i\.?\s*(?:d\.?\s*)?Tr\.?|in\s+der\s+Trockenmasse)';

    /** Kanonische Schreibweise eines Namensteils, wenn er ein Fettwert ist; sonst null. */
    public static function kanon(string $teil): ?string
    {
        $t = trim($teil);
        $muster = [
            '/^' . self::ZAHL . '\s*%\s*Fett(?:gehalt)?(\s*' . self::ITR . ')?$/iu',        // 3,5 % Fett · 45% Fett i.Tr.
            '/^Fett(?:gehalt)?(\s*' . self::ITR . ')?\s*:?\s*' . self::ZAHL . '\s*%$/iu',     // Fett 3,5 % · Fett i. Tr. 45 %
            '/^' . self::ZAHL . '\s*%\s*(F\.?\s*' . self::ITR . ')$/iu',                     // 45 % F. i. Tr.
            '/^(F\.?\s*' . self::ITR . ')\s*' . self::ZAHL . '\s*%$/iu',                     // F.i.Tr. 45 %
        ];
        foreach ($muster as $i => $m) {
            if (preg_match($m, $t, $x) !== 1) {
                continue;
            }
            [$zahl, $itr] = match ($i) {
                0 => [$x[1], ($x[2] ?? '') !== ''],
                1 => [$x[2], ($x[1] ?? '') !== ''],
                2 => [$x[1], true],
                default => [$x[2], true],
            };

            return str_replace('.', ',', $zahl) . ' % Fett' . ($itr ? ' i. Tr.' : '');
        }

        return null;
    }

    /**
     * Zerlegt einen Feldwert an den Kommas (Dezimalkomma bleibt) in Teile.
     *
     * @return list<string>
     */
    public static function teile(string $wert): array
    {
        $roh = (string) preg_replace('/(?<=\d),(?=\d)/', "\x01", $wert);

        return array_values(array_filter(array_map(fn ($t) => trim(str_replace("\x01", ',', $t)), explode(',', $roh)), fn ($t) => $t !== ''));
    }

    /**
     * Fettwerte aus einem Feldwert ziehen: [kanonische Fettwerte, Rest des Felds (null = unverändert, weil kein Fettwert darin stand)].
     *
     * @return array{0: list<string>, 1: ?string}
     */
    public static function ziehe(string $wert): array
    {
        $fett = [];
        $rest = [];
        foreach (self::teile($wert) as $teil) {
            $k = self::kanon($teil);
            $k !== null ? $fett[] = $k : $rest[] = $teil;
        }

        return $fett === [] ? [[], null] : [$fett, implode(', ', $rest)];
    }
}
