<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use Platform\FoodAlchemist\Support\RezeptTypVokabular;

/**
 * Textvergleich des Regel-Motors (Spec 81 Teil A): tolerant gegen Groß-/Kleinschreibung, Umlaut-Umschreibung
 * (ä/ae, ö/oe, ü/ue, ß/ss) und Akzente. Der Bestand schreibt überwiegend umschrieben („geschaelt"), die Regeln
 * mit Umlaut — beides muss dasselbe treffen.
 */
final class RegelText
{
    /** Vergleichsform: klein, Umlaute umschrieben, Bindestrich = Leerzeichen, Leerraum zusammengezogen. */
    public static function norm(string $s): string
    {
        return RezeptTypVokabular::norm($s);
    }

    /** Steht `$wort` als ganzes Wort (oder Wortfolge) in `$text`? */
    public static function hatWort(string $text, string $wort): bool
    {
        $w = self::norm($wort);
        if ($w === '') {
            return false;
        }

        return preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($w, '/') . '($|[^\p{L}\p{N}])/u', self::norm($text)) === 1;
    }

    /** Steht `$teil` irgendwo in `$text` (auch innerhalb eines Wortes)? */
    public static function hatTeil(string $text, string $teil): bool
    {
        $t = self::norm($teil);

        return $t !== '' && str_contains(self::norm($text), $t);
    }

    /**
     * Regex-Fragment, das `$wort` im ORIGINAL-Text findet, egal ob mit Umlaut oder umschrieben geschrieben
     * („Würfel" trifft „Wuerfel" und umgekehrt). Für Ersetzungen, die den Originaltext umschreiben.
     */
    public static function tolerantesMuster(string $wort): string
    {
        $paare = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
        $w = mb_strtolower(trim($wort));
        foreach ($paare as $um => $aus) {
            $w = str_replace($aus, $um, $w);
        }
        $out = '';
        foreach (mb_str_split($w) as $z) {
            $out .= match ($z) {
                'ä' => '(?:ä|ae)', 'ö' => '(?:ö|oe)', 'ü' => '(?:ü|ue)', 'ß' => '(?:ß|ss)',
                ' ', '-' => '[\s\-]+',
                default => preg_quote($z, '/'),
            };
        }

        return $out;
    }

    /** Ganz-Wort-Ersetzung im Originaltext, tolerant. Großschreibung des ersten Buchstabens bleibt erhalten. */
    public static function ersetzeWort(string $text, string $von, string $nach): string
    {
        $muster = '/(?<![\p{L}\p{N}])' . self::tolerantesMuster($von) . '(?![\p{L}\p{N}])/iu';

        return (string) preg_replace_callback($muster, static function (array $m) use ($nach): string {
            $erstes = mb_substr($m[0], 0, 1);

            return $erstes !== mb_strtolower($erstes) ? mb_strtoupper(mb_substr($nach, 0, 1)) . mb_substr($nach, 1) : $nach;
        }, $text);
    }

    /** Prüft ein Regex-Muster vorab. Liefert die Fehlermeldung oder null. */
    public static function musterFehler(string $muster): ?string
    {
        set_error_handler(static fn () => true);
        try {
            $ok = @preg_match($muster, '') !== false;
        } finally {
            restore_error_handler();
        }

        return $ok ? null : 'Ungültiges Muster: ' . $muster;
    }

    /** Regex sicher ausführen: ein kaputtes oder zu teures Muster zählt als kein Treffer. */
    public static function passt(string $muster, string $text): bool
    {
        set_error_handler(static fn () => true);
        try {
            return @preg_match($muster, $text) === 1;
        } finally {
            restore_error_handler();
        }
    }
}
