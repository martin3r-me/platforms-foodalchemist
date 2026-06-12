<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * M7-08: Kill-Switch — KI ist für dieses Team deaktiviert (Team-Einstellung
 * ki_aktiv). Typisiert, damit UI-Komponenten sauber degradieren können.
 */
class KiDeaktiviertException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('KI ist für dieses Team deaktiviert (Einstellungen → KI) — Kill-Switch aktiv.');
    }
}
