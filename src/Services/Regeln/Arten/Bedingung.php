<?php

namespace Platform\FoodAlchemist\Services\Regeln\Arten;

use Platform\FoodAlchemist\Services\Regeln\RegelText;

/**
 * Bedingung einer Regel: `{feld: [werte]}` — alle Felder müssen passen, je Feld genügt einer der Werte.
 * Fehlt ein Feld im Kontext, gilt die Bedingung als nicht erfüllt (die Regel schweigt statt zu raten).
 */
final class Bedingung
{
    /** @param  array<string, mixed>  $kontext */
    public static function erfuellt(array $bedingung, array $kontext): bool
    {
        foreach ($bedingung as $feld => $werte) {
            $ist = $kontext[$feld] ?? null;
            if ($ist === null || $ist === '') {
                return false;
            }
            $ist = RegelText::norm((string) $ist);
            $treffer = false;
            foreach ((array) $werte as $w) {
                if (RegelText::norm((string) $w) === $ist) {
                    $treffer = true;
                    break;
                }
            }
            if (! $treffer) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function validiere(mixed $bedingung): array
    {
        if ($bedingung === null) {
            return [];
        }
        if (! is_array($bedingung) || array_is_list($bedingung)) {
            return ['bedingung muss {feld: [werte]} sein'];
        }

        return [];
    }
}
