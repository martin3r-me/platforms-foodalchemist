<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Multi-Wort-Suche für Katalog-Picker (GPs, LAs, Rezepte).
 *
 * Problem: ein einzelnes `LOWER(name) LIKE '%<ganze Eingabe>%'` scheitert, sobald
 * ein zweites Wort getippt wird — «mehrkorn tom» müsste als zusammenhängender
 * Teilstring vorkommen und findet «Tomatenbrot Mehrkorn» nie.
 *
 * Lösung: die Eingabe in Tokens splitten; JEDES Token muss (in beliebiger
 * Reihenfolge) als Teilstring vorkommen. Tokens sind gebundene Parameter (safe);
 * die Spaltennamen kommen ausschließlich aus vertrauenswürdigem Aufrufer-Code.
 */
final class Suche
{
    /** @return array<int, string> lower-case Tokens ohne Leerstrings */
    public static function tokens(string $suche): array
    {
        return array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($suche))) ?: [],
            fn ($t) => $t !== '',
        ));
    }

    /**
     * Hängt pro Token ein AND `LOWER(<column>) LIKE ?` an den Query-Builder.
     * Gibt den Builder zurück (chainbar). Leere Suche = kein Filter.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function like($query, string $column, string $suche)
    {
        return self::likeAny($query, [$column], $suche);
    }

    /**
     * Umlaut-tolerante Varianten eines Tokens (Dominique 19.10.: GP-Namen mit echten Umlauten, Bestand großteils ASCII):
     * „pueree“ findet „Püree“ und umgekehrt, „suess“ findet „süß“. ß→ss nur in eine Richtung (nie „Wasser“ → „Waßer“).
     *
     * @return list<string>
     */
    public static function varianten(string $token): array
    {
        $ascii = strtr($token, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $umlaut = strtr($ascii, ['ae' => 'ä', 'oe' => 'ö', 'ue' => 'ü']);

        return array_values(array_unique([$token, $ascii, $umlaut]));
    }

    /**
     * Wie like(), aber über MEHRERE Spalten: jedes Token muss treffen (AND), pro Token
     * reicht EINE der Spalten (OR). So bleibt Mehr-Wort-Suche korrekt, wenn Treffer über
     * Name + Nebenfeld (Rolle, Anlass, Slug …) verteilt sein dürfen. Spalten kommen aus
     * vertrauenswürdigem Aufrufer-Code (auch Ausdrücke wie "COALESCE(role, '')" erlaubt).
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @param  array<int, string>  $columns
     * @return T
     */
    public static function likeAny($query, array $columns, string $suche)
    {
        foreach (self::tokens($suche) as $token) {
            $query->where(function ($w) use ($columns, $token) {
                $erstes = true;
                foreach (array_values($columns) as $col) {
                    foreach (self::varianten($token) as $v) {
                        $erstes ? $w->whereRaw("LOWER({$col}) LIKE ?", ['%' . $v . '%']) : $w->orWhereRaw("LOWER({$col}) LIKE ?", ['%' . $v . '%']);
                        $erstes = false;
                    }
                }
            });
        }

        return $query;
    }
}
