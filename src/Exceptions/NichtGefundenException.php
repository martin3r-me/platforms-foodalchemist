<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 84 D1 · Ein Objekt gibt es nicht oder es ist für das Team nicht sichtbar (MCP-Code NOT_FOUND).
 *
 * Eigener Typ statt Textvergleich (`str_contains($e->getMessage(), …)`): Aufrufer entscheiden am Typ. Erbt von
 * RuntimeException und behält die Meldung, damit bestehende `catch (\RuntimeException)` und Anzeigen unverändert bleiben.
 */
final class NichtGefundenException extends \RuntimeException
{
}
