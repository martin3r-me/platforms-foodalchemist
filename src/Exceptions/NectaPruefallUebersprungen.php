<?php

namespace Platform\FoodAlchemist\Exceptions;

/** Necta-Prüffälle (PR 3): ein einzelner Fall eines Stapels wird übersprungen (Grund im Ergebnis), der Rest läuft weiter. */
final class NectaPruefallUebersprungen extends \RuntimeException
{
}
