<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use RuntimeException;

/** Eine Regel verletzt ihr Schema oder ihre eigenen Beispiele — Speichern wird abgelehnt. */
final class RegelUngueltig extends RuntimeException
{
    /**
     * @param  list<string>  $fehler
     * @param  string  $typ  Fehlertyp für Aufrufer (Spec 84 §6 Nr. 4, nie aus dem Text ableiten): VALIDATION_ERROR | NICHT_UEBERSTEUERBAR | UNTERGRENZE
     */
    public function __construct(public readonly array $fehler, public readonly string $typ = 'VALIDATION_ERROR')
    {
        parent::__construct('Regel ungültig: ' . implode(' · ', $fehler));
    }
}
