<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * KI-Abrechnung je Mandant (Dominique 23.10.2026): der Monatsdeckel eines Mandanten vor der Lizenz (Status „projekt“ oder ohne
 * Mandanten-Zeile) ist erreicht. Erbt vom Budget-/Kill-Switch-Fall, damit alle Stellen, die bei abgeschalteter KI sauber degradieren,
 * das auch hier tun.
 */
class KiDeckelProjektException extends KiBudgetErschoepftException
{
    public function __construct(float $deckelEur)
    {
        \RuntimeException::__construct(sprintf(
            'Das KI-Kontingent der Projektphase (%s € im Monat) ist aufgebraucht. Mit der Lizenz oder ab dem 1. des nächsten Monats geht es weiter.',
            number_format($deckelEur, 2, ',', '.')
        ));
    }
}
