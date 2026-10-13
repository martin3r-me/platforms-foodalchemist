<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\User;

/**
 * Spec 82 §13 C4 — Protokoll aller Plattform-Admin-Aktionen (Stamm-Paket, Master-Team, später Gebühren, Abos)
 * über das vorhandene activity-log. Ist es nicht installiert (Sandbox-Stub), wird nichts geschrieben und nichts
 * gebrochen; die Zeilen der jeweiligen Tabelle tragen zusätzlich Wer und Wann.
 */
class PlattformProtokoll
{
    /** @param array<string,mixed> $daten */
    public function schreibe(string $aktion, array $daten, ?User $user): void
    {
        if (! function_exists('activity')) {
            return;
        }
        try {
            $eintrag = activity('foodalchemist.plattform');
            if ($user !== null) {
                $eintrag->causedBy($user);
            }
            $eintrag->withProperties($daten)->log($aktion);
        } catch (\Throwable) {
            // Das Protokoll darf die Steueraktion nie verhindern.
        }
    }
}
