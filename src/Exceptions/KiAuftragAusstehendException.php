<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * #1002: Im externen KI-Modus wurde statt eines Provider-Calls ein Auftrag angelegt (oder liegt noch
 * unbeantwortet). Kein Fehler im Sinn von „kaputt" — der MCP-Client beantwortet ihn, dann wird der Aufruf wiederholt.
 */
class KiAuftragAusstehendException extends \RuntimeException
{
    public function __construct(public readonly string $auftragUuid, string $feature)
    {
        parent::__construct("KI-Auftrag {$auftragUuid} [{$feature}] wartet auf externe Antwort (ki_auftraege.ANTWORT), danach denselben Aufruf wiederholen.");
    }
}
