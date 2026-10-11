<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Services\RecipeKomponentenPlanService;
use Platform\FoodAlchemist\Services\Regeln\Arten\Vokabular;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelText;

/**
 * Funktion einer Zutatenzeile (Dominique 11.10.): Vokabular aus der Regel `rezeptzeile.funktion`, die Konstante
 * {@see RecipeKomponentenPlanService::FUNKTIONEN} ist nur Rückfall, wenn die Regel fehlt oder aus ist.
 */
final class ZeilenFunktion
{
    public const SCHLUESSEL = 'rezeptzeile.funktion';

    /** @return list<string> erlaubte (kanonische) Werte */
    public static function werte(): array
    {
        $regel = RegelBuch::falls(self::SCHLUESSEL);
        $werte = $regel !== null ? array_values(array_filter(array_map(static fn ($w) => is_array($w) ? (string) ($w['wert'] ?? '') : '',
            (array) ($regel->params['werte'] ?? [])), 'strlen')) : [];

        return $werte !== [] ? $werte : RecipeKomponentenPlanService::FUNKTIONEN;
    }

    /** Kanonischer Wert (auch über Alias „säure“ → saeure) oder null, wenn er nicht im Vokabular steht. */
    public static function kanonisch(mixed $wert): ?string
    {
        if (! is_string($wert) || trim($wert) === '') {
            return null;
        }
        $regel = RegelBuch::falls(self::SCHLUESSEL);
        if ($regel !== null) {
            return app(Vokabular::class)->kanonisch($regel, $wert);
        }
        $n = RegelText::norm($wert);

        return in_array($n, RecipeKomponentenPlanService::FUNKTIONEN, true) ? $n : null;
    }
}
