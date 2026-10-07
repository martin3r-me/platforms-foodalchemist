<?php

namespace Platform\FoodAlchemist\Livewire\Settings\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;

/**
 * Spec 65 · Bearbeitungssperre für Einstellungs-Sektionen.
 *
 * Einstellungen haben keinen Datensatz-Wechsel: gesperrt wird der BEREICH je Team —
 * Ziel ['settings.<bereich>', <team_id>]. Wer „Bearbeiten" klickt, hält den Bereich für alle
 * anderen Personen des Teams; ein anderes Team ist davon nicht berührt.
 *
 * Sektionen mit EINEM Speichern-Knopf: Bearbeiten → Abbrechen/Speichern, Speichern gibt frei.
 * Sektionen mit mehreren Abschnitts-Speichern bzw. Sofort-Aktionen (Listen): Leiste `sofort`
 * (Bearbeiten → Fertig), die einzelnen Schreibaktionen geben NICHT frei.
 *
 * updated*-Hooks laufen nicht über den globalen call-Haken (Eigenschafts-Update, kein Methoden-
 * Aufruf). Schreibt ein Hook direkt, prüft er am Anfang `schreibenAbgewiesen()`.
 */
trait MitEinstellungsSperre
{
    use MitBearbeitungssperre;

    /** Bereichs-Schlüssel, z.B. 'kalkulation' → Ziel 'settings.kalkulation'. */
    abstract protected function sperrBereich(): string;

    protected function sperrZiel(): ?array
    {
        $teamId = Auth::user()?->currentTeamRelation?->id ?? Auth::user()?->current_team_id;

        return $teamId !== null ? ['settings.'.$this->sperrBereich(), (int) $teamId] : null;
    }

    /** Abbrechen: ungespeicherten Formularstand verwerfen, Bereich frisch laden. */
    protected function nachAbbrechen(): void
    {
        if (method_exists($this, 'mount')) {
            $this->mount();
        }
    }

    /**
     * Für schreibende updated*-Hooks: true = abgewiesen (Hook bricht ab). Schalter aus → nie abgewiesen
     * (darfSchreiben() allein kennt den Schalter nicht). Erlaubte Schreibaktion verlängert die Sperre.
     */
    protected function schreibenAbgewiesen(string $was = 'updated'): bool
    {
        if ($this->sperreErlaubt($was)) {
            return false;
        }
        $this->sperreAbgewiesen($was);

        return true;
    }
}
