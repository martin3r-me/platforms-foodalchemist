<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * #499: Kein LLM-Provider gebunden (z. B. demo ohne Core-LLM-Setup / fehlender
 * Key). Typisiert als RuntimeException, damit die UI-Entry-Points sauber
 * degradieren (verständliche Meldung statt 500) — analog KiDeaktiviertException.
 */
class KiNichtVerfuegbarException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'KI ist auf dieser Umgebung nicht verfügbar — es ist kein LLM-Provider eingerichtet. '
            . 'Bitte den Administrator kontaktieren (Provider + API-Key im Core-Setup).',
            0,
            $previous,
        );
    }
}
