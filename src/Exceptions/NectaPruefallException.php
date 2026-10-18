<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Necta-Prüffälle (PR 3): Ablehnung einer Entscheidung mit festem Code — Aufrufer (Livewire, MCP) unterscheiden am Code, nicht am Wortlaut.
 * NUR_MASTER · LISTE_GEAENDERT (Vorschau-Schlüssel passt nicht mehr) · AKTION_UNGUELTIG · LEER.
 */
final class NectaPruefallException extends \RuntimeException
{
    public function __construct(public readonly string $fehlercode, string $meldung)
    {
        parent::__construct($meldung);
    }
}
