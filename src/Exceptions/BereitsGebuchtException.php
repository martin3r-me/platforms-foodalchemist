<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 84 D1 · Der Beleg (z. B. eine Inventur) ist schon gebucht und damit eingefroren.
 *
 * Eigener Typ statt Textvergleich (`str_contains($e->getMessage(), …)`): Aufrufer entscheiden am Typ. Erbt von
 * RuntimeException und behält die Meldung, damit bestehende `catch (\RuntimeException)` und Anzeigen unverändert bleiben.
 */
final class BereitsGebuchtException extends \RuntimeException
{
}
