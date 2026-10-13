<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;

/**
 * Spec 82 §13 C3 — Auskunft, ob für einen Mandanten ein Datenexport vorliegt. Der Ablauf „Mandant beenden“ fragt sie vor dem Sperren:
 * Ohne vorliegenden Export hält er an. Die Antwort kommt vom {@see MandantExportService}; im Test lässt sich die Klasse per Container ersetzen.
 */
class ExportAuskunft
{
    /** Gibt es die Export-Funktion? */
    public function verfuegbar(): bool
    {
        return true;
    }

    /** Liegt für den Mandanten ein fertiger Export vor, dessen Datei noch da ist? */
    public function vorhanden(Team $mandant): bool
    {
        return app(MandantExportService::class)->vorhanden($mandant);
    }
}
