<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * #1002 (P2a): Ein externer Lauf hat die Wiederholungs-Schranke erreicht (ein stabiler Fingerabdruck braucht höchstens so viele
 * Wiederholungen wie der Lauf KI-Glieder hat). Ein instabiler Prompt würde sonst endlos neue Aufträge erzeugen.
 */
class KiExternSchrankeException extends \RuntimeException
{
}
