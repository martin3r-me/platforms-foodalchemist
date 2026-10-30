<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 84 D1 · Die Kopfzeile einer Import-Datei reicht nicht (Pflicht- oder Schlüsselspalte fehlt): Der Lauf wartet auf die
 * Spalten-Zuordnung je Lieferant, statt abzubrechen.
 *
 * Eigener Typ statt Textvergleich (`preg_match` auf die Meldung): Aufrufer entscheiden am Typ. Erbt von RuntimeException und
 * behält die Meldung, damit bestehende `catch (\RuntimeException)` und Anzeigen unverändert bleiben.
 */
final class SpaltenZuordnungFehltException extends \RuntimeException
{
}
