<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Speicher-Limit für lange Kommandos defensiv anheben (io 19.10.: `foodalchemist:recompute` brach sofort ab).
 *
 * PHP 8.5 kennt `max_memory_limit`: ein `ini_set('memory_limit', …)` darüber wirft eine Warnung („Failed to set memory_limit … Setting to
 * max_memory_limit instead“), die Laravel zur Exception macht — das Kommando stirbt, bevor es etwas tut. Hier: nur anheben, nie senken; über
 * dem Server-Höchstwert auf diesen kappen und einen Hinweis liefern statt abzubrechen.
 */
final class Speicher
{
    /**
     * @param  string  $ziel  gewünschtes Limit, z. B. '1024M'
     * @param  string|null|false  $hoechst  Server-Höchstwert; Standard = ini `max_memory_limit` (PHP ≥ 8.5, sonst false = keiner)
     * @return string|null Hinweis für die Ausgabe, wenn weniger als gewünscht gesetzt werden konnte; null = wie gewünscht (oder schon höher)
     */
    public static function anheben(string $ziel, string|null|false $hoechst = null): ?string
    {
        $hoechst = $hoechst ?? ini_get('max_memory_limit');
        $jetzt = self::bytes((string) ini_get('memory_limit'));
        $will = self::bytes($ziel);
        if ($jetzt === -1 || ($will !== -1 && $jetzt >= $will)) {
            return null;                                              // unbegrenzt oder schon genug — nie senken
        }
        $max = $hoechst === false || $hoechst === '' ? -1 : self::bytes((string) $hoechst);
        $gekappt = $max !== -1 && ($will === -1 || $will > $max);
        $setzen = $gekappt ? $max : $will;
        if ($setzen !== -1 && $setzen <= $jetzt) {
            return "Speicher bleibt bei " . self::lesbar($jetzt) . " (Server-Höchstwert " . self::lesbar($max) . ", gewünscht {$ziel}).";
        }
        try {
            @ini_set('memory_limit', $setzen === -1 ? '-1' : (string) $setzen);
        } catch (\Throwable) {
            // Warnung, die ein Fehler-Handler trotz @ zur Exception macht — weiterlaufen, das Limit steht dann auf dem Höchstwert
        }

        return $gekappt ? "Speicher auf " . self::lesbar($max) . " gesetzt (Server-Höchstwert), gewünscht {$ziel}." : null;
    }

    /** '512M' → Bytes; '-1' → -1. */
    public static function bytes(string $wert): int
    {
        $wert = trim($wert);
        if ($wert === '' || $wert === '-1') {
            return -1;
        }
        $zahl = (int) $wert;

        return match (strtoupper(substr($wert, -1))) {
            'G' => $zahl * 1024 ** 3,
            'M' => $zahl * 1024 ** 2,
            'K' => $zahl * 1024,
            default => $zahl,
        };
    }

    private static function lesbar(int $bytes): string
    {
        return $bytes === -1 ? 'unbegrenzt' : round($bytes / 1024 ** 2) . ' MB';
    }
}
