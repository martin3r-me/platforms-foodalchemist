<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * M7-07: Küchen-Profil (Team-Einstellung) — Soft-Default-Schicht des
 * Generators; explizite Richtungs-Parameter haben Vorrang (D-5 §4.3).
 */
class Kueche extends Component
{
    public string $kuechenTyp = '';

    public ?string $meldung = null;

    public function mount(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $this->kuechenTyp = $team !== null ? (app(TeamSettingsService::class)->kuechenTyp($team) ?? '') : '';
    }

    public function speichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        app(TeamSettingsService::class)->update($team, [
            'kuechen_typ' => isset(TeamSettingsService::KUECHEN_TYPEN[$this->kuechenTyp]) ? $this->kuechenTyp : null,
        ]);
        $this->meldung = 'Gespeichert — der Generator nutzt das Profil ab dem nächsten Lauf.';
    }

    public function render()
    {
        return view('foodalchemist::livewire.settings.kueche', [
            'typen' => TeamSettingsService::KUECHEN_TYPEN,
        ]);
    }
}
