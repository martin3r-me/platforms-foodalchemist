<?php

namespace Platform\FoodAlchemist\Support;

/**
 * MVP-023/024 (Audit 23): EINE Stelle für die deutschen Anzeige-Labels der internen
 * Enum-Rohwerte. Vorher standen `from_scratch`, `suess` und `high` roh in der UI, und beide
 * Rezept-Browser trugen ihre eigene (englisch verrutschte) Übersetzung — der Fertigungs-Filter
 * zeigte „from scratch".
 *
 * Bewusst KEINE Erfindung von Werten: die Maps decken exakt das ab, was in den Daten vorkommt.
 * Ein unbekannter Wert wird nicht verschluckt, sondern durchgereicht — so bleibt er in der UI
 * sichtbar und fällt für die Pflege auf, statt still zu „—" zu werden. Leer/null ist der
 * neutrale Strich.
 *
 * Der Rezept-/Gericht-STATUS hat seine Labels bereits am Enum (RecipeStatus::label()); dieser
 * Helfer ergänzt nur die Felder ohne eigenen Enum-Typ.
 */
final class Labels
{
    private const LEER = '—';

    /** Fertigungstiefe (`recipes.production_depth`). Skala frisch → teilfertig → convenience. */
    private const FERTIGUNG = [
        'from_scratch' => 'Frisch',
        'teilfertig' => 'Teilfertig',
        'convenience' => 'Convenience',
    ];

    /** Geschmacksrichtung (`recipes.taste_direction`). */
    private const GESCHMACK = [
        'herzhaft' => 'Herzhaft',
        'suess' => 'Süß',
        'neutral' => 'Neutral',
    ];

    /** Aggregations-Konfidenz (`allergens_confidence`, `nutri_confidence`). */
    private const KONFIDENZ = [
        'high' => 'Hoch',
        'medium' => 'Mittel',
        'low' => 'Niedrig',
        'unknown' => 'Unbekannt',
    ];

    public static function fertigung(?string $wert): string
    {
        return self::map(self::FERTIGUNG, $wert);
    }

    public static function geschmack(?string $wert): string
    {
        return self::map(self::GESCHMACK, $wert);
    }

    public static function konfidenz(?string $wert): string
    {
        return self::map(self::KONFIDENZ, $wert);
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function map(array $map, ?string $wert): string
    {
        if ($wert === null || $wert === '') {
            return self::LEER;
        }

        return $map[$wert] ?? $wert;   // unbekannt → durchreichen, nicht verstecken
    }
}
