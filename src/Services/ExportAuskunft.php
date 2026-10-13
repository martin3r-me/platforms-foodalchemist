<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;

/**
 * Spec 82 §13 C3 — Auskunft, ob für einen Mandanten ein Datenexport vorliegt. Der Export selbst kommt in PR 3
 * (ZIP je Mandant, Download nur für Inhaber). Bis dahin gibt es keinen: Der Schritt „Datenexport“ hält den Ablauf an,
 * statt das Team ohne Export zu sperren — der Plattform-Admin lässt ihn nur bewusst weg.
 * Im Test lässt sich die Klasse per Container ersetzen.
 */
class ExportAuskunft
{
    /** Gibt es die Export-Funktion schon? */
    public function verfuegbar(): bool
    {
        return false;
    }

    /** Liegt für den Mandanten ein aktueller Export vor? */
    public function vorhanden(Team $mandant): bool
    {
        return false;
    }
}
