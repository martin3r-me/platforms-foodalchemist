<?php

namespace Platform\FoodAlchemist\Exceptions;

use Platform\FoodAlchemist\Enums\FaRolle;

/**
 * Spec 61 §5 · Die Rolle reicht nicht. Erbt von RuntimeException, damit die bestehenden
 * Fehlerwege (Livewire-Meldung, MCP VALIDATION_ERROR) die Meldung ohne Sonderfall anzeigen;
 * MCP-Tools fangen sie vorher gezielt als FORBIDDEN.
 */
class FaRechtFehltException extends \RuntimeException
{
    public function __construct(public readonly FaRolle $benoetigt, public readonly ?FaRolle $vorhanden, string $wofuer)
    {
        parent::__construct('Für „'.$wofuer.'“ brauchst du mindestens die Rolle „'.$benoetigt->label().'“.'
            .($vorhanden !== null ? ' Deine Rolle: „'.$vorhanden->label().'“.' : '')
            .($benoetigt === FaRolle::Freigeben
                ? ' Freigeben dürfen Inhaber, Admins und Mitglieder mit Häkchen unter Einstellungen → Zugriffsrechte.'
                : ' Die Rolle pflegt ein Team-Admin in den Team-Einstellungen der Plattform.'));
    }
}
