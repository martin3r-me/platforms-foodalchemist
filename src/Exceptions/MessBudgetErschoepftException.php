<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 84 D1 · Ein kostenpflichtiges Mess-Budget (z. B. Google Trends über DataForSEO) ist für diesen Monat aufgebraucht.
 * Schleifen brechen am Typ ab, nicht am Wortlaut. Behält die Meldung (erbt RuntimeException).
 */
final class MessBudgetErschoepftException extends \RuntimeException
{
}
