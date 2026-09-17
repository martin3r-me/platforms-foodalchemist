<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * M7-03 §3.3: KI-Antwort ist nach Fence-Stripping kein valides JSON (Degeneration/
 * Truncation). Eigener Typ statt Message-Substring-Klassifikation (Review-Fund:
 * [[feedback_prompt_wortlaut_ist_keine_schnittstelle]] — ein geänderter Wortlaut
 * hätte jeden Re-Roll stumm als 'provider_fehler' einsortiert).
 */
class KiAntwortKeinJsonException extends \RuntimeException
{
}
