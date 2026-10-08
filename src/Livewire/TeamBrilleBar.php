<?php

namespace Platform\FoodAlchemist\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\StandortService;

/**
 * Spec 77c · Team-Brille im Oberteam: welcher Standort gelesen wird — eigenes Team, alle Standorte
 * oder ein Unter-Team. Nur sichtbar, wenn das Team Unter-Teams hat. Wie die Betriebs-Brille: Auswahl
 * wird gespeichert, die Seite lädt neu, damit jede Liste und Auswertung den neuen Lese-Bereich nimmt.
 */
class TeamBrilleBar extends Component
{
    public string $sicht = 'eigen';

    public function mount(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null) {
            $b = app(StandortService::class)->brille($team);
            $this->sicht = $b['modus'] === 'team' ? 'team:'.$b['team_id'] : $b['modus'];
        }
    }

    public function updatedSicht($value): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $v = (string) $value;
        str_starts_with($v, 'team:')
            ? app(StandortService::class)->setzeBrille($team, 'team', (int) substr($v, 5))
            : app(StandortService::class)->setzeBrille($team, in_array($v, ['eigen', 'alle'], true) ? $v : 'eigen');
        $this->js('window.location.reload()');
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.team-brille-bar', [
            'standorte' => $team !== null ? app(StandortService::class)->unterTeams($team) : [],
            'teamName' => $team?->name,
        ]);
    }
}
