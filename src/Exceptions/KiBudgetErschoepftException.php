<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 77b · KI-Budget (€ je Monat, Kontingent des Haupt-Teams) ist aufgebraucht. Erbt vom Kill-Switch,
 * damit alle Stellen, die bei abgeschalteter KI sauber degradieren, das auch hier tun.
 */
class KiBudgetErschoepftException extends KiDeaktiviertException
{
    public function __construct()
    {
        \RuntimeException::__construct('Das KI-Budget dieses Monats ist aufgebraucht (Kontingent des Teams). Ab dem 1. des nächsten Monats geht es weiter — oder der Plattform-Admin erhöht das Budget.');
    }
}
