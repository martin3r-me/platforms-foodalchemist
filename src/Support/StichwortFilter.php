<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Spec 93 A · Stichwort-Filter vor dem Trend-Eingang, deterministisch, ohne KI.
 *
 * Durch kommt ein Artikel, wenn Titel + Teaser mindestens EIN Ein-Stichwort enthalten (leere Liste = alles) UND KEIN
 * Aus-Stichwort. Vergleich ohne Groß-/Kleinschreibung, Umlaute normalisiert (ä = ae, ß = ss), Wortanfang genügt
 * („vegan“ trifft „veganer“), Phrasen in Anführungszeichen gelten exakt als ganze Wortfolge.
 */
final class StichwortFilter
{
    /**
     * @param  list<string>|null  $ein
     * @param  list<string>|null  $aus
     * @return array{durch: bool, grund: ?string}
     */
    public static function pruefen(string $text, ?array $ein, ?array $aus): array
    {
        $norm = self::normalisieren($text);
        foreach (self::liste($aus) as $wort) {
            if (self::trifft($norm, $wort)) {
                return ['durch' => false, 'grund' => 'Aus-Stichwort „'.$wort.'“'];
            }
        }
        $ein = self::liste($ein);
        if ($ein === []) {
            return ['durch' => true, 'grund' => null];
        }
        foreach ($ein as $wort) {
            if (self::trifft($norm, $wort)) {
                return ['durch' => true, 'grund' => 'Ein-Stichwort „'.$wort.'“'];
            }
        }

        return ['durch' => false, 'grund' => 'kein Ein-Stichwort'];
    }

    /** Liste aus Eingabe (Array oder Text mit Komma/Zeilenumbruch) säubern; Phrasen behalten ihre Anführungszeichen. */
    public static function liste(mixed $wert): array
    {
        if (is_string($wert)) {
            $wert = preg_split('/[\n,;]+/u', $wert) ?: [];
        }
        $out = [];
        foreach ((array) $wert as $w) {
            $w = trim(preg_replace('/\s+/u', ' ', (string) $w) ?? '');
            $w = str_replace(['„', '“', '”', '»', '«'], '"', $w);
            if ($w !== '' && $w !== '""' && ! in_array($w, $out, true)) {
                $out[] = mb_substr($w, 0, 80);
            }
        }

        return array_slice($out, 0, 200);
    }

    public static function normalisieren(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'ç' => 'c', 'ñ' => 'n']);

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    private static function trifft(string $text, string $wort): bool
    {
        $phrase = strlen($wort) > 2 && str_starts_with($wort, '"') && str_ends_with($wort, '"');
        $w = self::normalisieren(trim($wort, '"'));
        if ($w === '') {
            return false;
        }
        $muster = '/(?<![\p{L}\p{N}])'.preg_quote($w, '/').($phrase ? '(?![\p{L}\p{N}])' : '').'/u';

        return preg_match($muster, $text) === 1;
    }
}
