<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * GP-Paket-Import (Plan §4.1): das GANZE Paket wird abgelehnt — Manifest/Hash stimmt nicht, unbekannte oder verbotene Spalten,
 * doppelte Schlüssel. Es wird dann nichts gelesen, geplant oder geschrieben; die Gründe stehen in `gruende`.
 */
class GpPaketAbgelehntException extends \RuntimeException
{
    /** @param list<string> $gruende */
    public function __construct(public readonly array $gruende)
    {
        parent::__construct('GP-Paket abgelehnt: ' . implode(' | ', array_slice($gruende, 0, 5)) . (count($gruende) > 5 ? ' … (+' . (count($gruende) - 5) . ')' : ''));
    }
}
