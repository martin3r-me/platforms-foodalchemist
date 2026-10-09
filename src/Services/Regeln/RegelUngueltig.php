<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use RuntimeException;

/** Eine Regel verletzt ihr Schema oder ihre eigenen Beispiele — Speichern wird abgelehnt. */
final class RegelUngueltig extends RuntimeException
{
    /** @param  list<string>  $fehler */
    public function __construct(public readonly array $fehler)
    {
        parent::__construct('Regel ungültig: ' . implode(' · ', $fehler));
    }
}
