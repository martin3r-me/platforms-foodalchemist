<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 84 D1 · Die Freigabe scheitert am Freigabe-Gate (rote Ampel); mit Override-Begründung möglich.
 *
 * Eigener Typ statt Textvergleich (`str_contains($e->getMessage(), …)`): Aufrufer entscheiden am Typ. Erbt von
 * RuntimeException und behält die Meldung, damit bestehende `catch (\RuntimeException)` und Anzeigen unverändert bleiben.
 */
final class FreigabeGateOffenException extends \RuntimeException
{
}
