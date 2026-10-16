<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 84 D1 · Löschen geht nicht, weil das Objekt noch verwendet wird (MCP-Code HAS_REFERENCES).
 *
 * Eigener Typ statt Textvergleich (`str_contains($e->getMessage(), …)`): Aufrufer entscheiden am Typ. Erbt von
 * RuntimeException und behält die Meldung, damit bestehende `catch (\RuntimeException)` und Anzeigen unverändert bleiben.
 */
final class NochVerwendetException extends \RuntimeException
{
}
