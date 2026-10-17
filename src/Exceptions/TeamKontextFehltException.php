<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 84 B3 (E2) · Eine Abfrage an einem FA-Eingang (Seite, MCP-Tool) hat kein betrachtendes Team. Eigener Typ statt stiller
 * Leerliste: ein KI-Tool läse „keine Daten“ und legte neu an. Erbt von RuntimeException, damit bestehende Fänger die Meldung zeigen.
 */
final class TeamKontextFehltException extends \RuntimeException
{
    public function __construct(public readonly string $modell = 'Eintrag')
    {
        parent::__construct("Kein Team-Kontext: {$modell} kann ohne betrachtendes Team nicht gelesen werden.");
    }
}
