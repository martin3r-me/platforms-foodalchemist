<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * M7-03 §3.3: valides JSON, aber fachlich unbrauchbar (z. B. leeres Pflicht-Array,
 * `structural_retry`-Gate). Eigener Typ statt Message-Substring-Klassifikation
 * (Review-Fund: [[feedback_prompt_wortlaut_ist_keine_schnittstelle]] — ein
 * geänderter Wortlaut hätte jeden Re-Roll stumm als 'provider_fehler' einsortiert).
 */
class KiAntwortStrukturellUnbrauchbarException extends \RuntimeException
{
}
