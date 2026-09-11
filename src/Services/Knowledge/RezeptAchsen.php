<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

/**
 * Spec 52 Runde C — die Geltungs-Achsen eines BESTEHENDEN Rezepts.
 *
 * Beim Erzeugen entscheidet die KI erst, was entsteht; Gang und Warengruppe gibt es da noch
 * nicht. Bei der Anreicherung, Überarbeitung und Prüfung liegen sie vor — und genau dort
 * bleibt das Nachschlagen sonst blind, weil niemand die Achsen mitgibt.
 *
 * ★ Abgeleitet wird nur, was wirklich ableitbar ist:
 *
 * · `gang` — aus der Speisen-Hauptgruppe. Die Klassifikations-REGEL ist zwar „wie gebaut",
 *   nicht „wo eingesetzt", aber die Gruppen selbst heissen HG, VOR, ZWG, DES, AMU. Das ist
 *   der Gang.
 * · `warengruppe` — aus den Grundprodukten der Zutaten. Mehrere Werte sind erlaubt und
 *   richtig: ein Gericht kann Fleisch UND Gemüse führen, und `passt()` prüft je Achse mit
 *   ODER.
 *
 * ⚠ **Nicht abgeleitet — bewusst:**
 *
 * · `saison` hat keine Quelle. Weder Planung noch Rezept tragen ein Zieldatum (geprüft
 *   2026-09-11). Der heutige Monat wäre eine Annahme über die ABSICHT: ein Rezept, das im
 *   September für ein Weihnachtsmenü angereichert wird, bekäme September-Wissen. Die Achse
 *   bleibt explizit setzbar.
 * · `komponentenrolle` beschreibt die Rolle einer Komponente IM Gericht. Ein ganzes Rezept
 *   hat keine; die Rolle steht an der Zutat, nicht am Rezept.
 */
final class RezeptAchsen
{
    /**
     * @param  object  $rezept  Rezept-Modell mit `dishMainGroup` und `ingredients`
     * @return array<string, list<string>|string>  Achsenwerte, leer wenn nichts ableitbar
     */
    public static function fuer(object $rezept): array
    {
        $achsen = [];

        $gang = self::sauber($rezept->dishMainGroup?->code ?? null);
        if ($gang !== null && WissensAchsenVokabular::gueltig('gang', $gang)) {
            $achsen['gang'] = $gang;
        }

        $warengruppen = self::warengruppen($rezept);
        if ($warengruppen !== []) {
            $achsen['warengruppe'] = $warengruppen;
        }

        return $achsen;
    }

    /**
     * Warengruppen der geerdeten Zutaten.
     *
     * ⚠ Die Codes an den Grundprodukten sind nicht sauber: neben `01` stehen auf demo auch
     * `01 Gemuese&Blattsalat` und `01 Gemüse & Blattsalat`. Ohne das erste Token wäre die
     * Hälfte der Zutaten stumm. Was danach nicht im Vokabular steht, fliegt raus statt
     * einen Treffer vorzutäuschen.
     *
     * @return list<string>
     */
    private static function warengruppen(object $rezept): array
    {
        $codes = [];
        foreach ($rezept->ingredients ?? [] as $zutat) {
            $roh = $zutat->gp?->commodity_group_code ?? null;
            if (! is_string($roh) || trim($roh) === '') {
                continue;
            }
            // erstes Token: "01 Gemuese&Blattsalat" → "01"
            $code = self::sauber(preg_split('/\s+/', trim($roh))[0] ?? null);
            if ($code !== null && WissensAchsenVokabular::gueltig('warengruppe', $code)) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    private static function sauber(mixed $wert): ?string
    {
        if (! is_string($wert)) {
            return null;
        }
        $wert = mb_strtolower(trim($wert));

        return $wert === '' ? null : $wert;
    }
}
