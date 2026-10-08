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
    use MitBearbeitungssperre {
        sperreErlaubt as protected sperreErlaubtOhneRolle;
        sperreAbgewiesen as protected sperreAbgewiesenOhneRolle;
        sperrZustand as protected sperrZustandOhneRolle;
    }

    /**
     * Spec 77a (F3): Einstellungen ändern nur Inhaber/Admin (FA-Admin). Mitglieder und Betrachter sehen
     * die Sektion lesend — auch wenn die Bearbeitungssperre abgeschaltet ist. Gilt für jede Schreibaktion,
     * die über den globalen call-Haken oder `schreibenAbgewiesen()` läuft.
     */
    protected function darfEinstellungen(): bool
    {
        $user = Auth::user();
        $team = $user?->currentTeamRelation;

        return $user === null || $team === null
            || app(\Platform\FoodAlchemist\Services\FaRechte::class)->darf($user, $team, \Platform\FoodAlchemist\Enums\FaRolle::Admin);
    }

    /** Lesende Methoden, die auch ohne Admin-Recht laufen (Anzeigen, Reiter, Herzschlag). */
    protected function nurLesendErlaubt(): array
    {
        return array_merge(['render', 'mount', '$refresh', '__dispatch', 'tabLaden', 'oeffnen', 'sperreHerzschlag',
            'sperreBeiSchliessen', 'bearbeitenAbbrechen'], $this->sperrFreiExtra());
    }

    public function sperreErlaubt(string $methode): bool
    {
        if (! $this->darfEinstellungen() && ! in_array($methode, $this->nurLesendErlaubt(), true)) {
            return false;
        }

        return $this->sperreErlaubtOhneRolle($methode);
    }

    public function sperreAbgewiesen(string $methode): void
    {
        if (! $this->darfEinstellungen()) {
            $this->dispatch('fa-saved', message: 'Einstellungen ändern dürfen nur Inhaber und Admins des Teams.', type: 'error');

            return;
        }
        $this->sperreAbgewiesenOhneRolle($methode);
    }

    public function sperrZustand(): array
    {
        if (! $this->darfEinstellungen()) {
            return ['modus' => 'lesen', 'fremd' => null, 'admin' => false, 'rolle_fehlt' => true];
        }

        return $this->sperrZustandOhneRolle();
    }

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
