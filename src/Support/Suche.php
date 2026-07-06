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
        foreach (self::tokens($suche) as $token) {
            $query->whereRaw("LOWER({$column}) LIKE ?", ['%' . $token . '%']);
        }

        return $query;
    }
}
