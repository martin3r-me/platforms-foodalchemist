<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\ActiveOutletContext;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\StandortService;
use Platform\FoodAlchemist\Support\FaTeam;

/**
 * Spec 85 PR-C · EINE Grundlage für alle Controlling-Teile: wer betrachtet (FaTeam::aktuell statt currentTeamRelation), mit welcher Brille
 * (eigen / alle / ein Standort → leseTeamIds) und welchem Kalkulationsprofil — und die Rollenprüfung an Schreibaktionen.
 *
 * Rollen werden in der Komponente geprüft (nicht in den Diensten, die auch Konsole und MCP bedienen) und liefern die Meldung statt einer Ausnahme,
 * damit jede Fläche sie im eigenen Fehlerfeld zeigt.
 */
trait ControllingTeam
{
    /** Das betrachtende Team (UI-Team, im MCP-/Job-Kontext das gesetzte). */
    protected function team(): ?Team
    {
        return FaTeam::aktuell();
    }

    /** Wie {@see team()}, aber ohne Team ist hier Schluss (Flächen, die ohne Team nichts zeigen können). */
    protected function teamPflicht(): Team
    {
        return $this->team() ?? abort(403, 'Kein Team zugeordnet.');
    }

    /**
     * Teams, deren Daten die Brille zeigt: eigen → [eigenes], alle → [eigenes + Standorte], ein Standort → [dieser].
     *
     * @return list<int>
     */
    protected function leseTeamIds(Team $team): array
    {
        return app(StandortService::class)->leseTeamIds($team);
    }

    /** Kalkulationsprofil (aktiver Betrieb), getrennt von der Brille. */
    protected function profil(Team $team): ?FoodAlchemistOutlet
    {
        return app(ActiveOutletContext::class)->current($team);
    }

    /** Meldung, wenn die Rolle für $wofuer nicht reicht; null = erlaubt. */
    protected function rolleFehlt(Team $team, FaRolle $mindestens, string $wofuer): ?string
    {
        try {
            app(FaRechte::class)->pruefe(Auth::user(), $team, $mindestens, $wofuer);

            return null;
        } catch (FaRechtFehltException $e) {
            return $e->getMessage();
        }
    }

    /** Einkaufs-Recht wie auf der Bestellseite: Bereich „Einkauf“ nutzbar UND mindestens Kuratieren. */
    protected function einkaufFehlt(Team $team, string $wofuer): ?string
    {
        if (! app(FaRechte::class)->darfBereich(Auth::user(), $team, 'einkauf')) {
            return 'Für „'.$wofuer.'“ muss der Bereich Einkauf für dich freigeschaltet sein.';
        }

        return $this->rolleFehlt($team, FaRolle::Kuratieren, $wofuer);
    }
}
