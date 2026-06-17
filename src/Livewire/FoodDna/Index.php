<?php

namespace Platform\FoodAlchemist\Livewire\FoodDna;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * #389/Canvas: Food-DNA-Seite — Team-Canvas „Markenkern Küche" über die zentrale
 * Canvas-Mechanik (ManagesCanvas, canvas_type=food_dna, owner=team). Stehende KI-Referenz.
 */
class Index extends Component
{
    use ManagesCanvas;

    public function mount(): void
    {
        $team = Auth::user()->currentTeamRelation;
        $this->canvasInit('food_dna', 'team', $team->id);
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $kuechenTyp = app(TeamSettingsService::class)->kuechenTyp($team);

        return view('foodalchemist::livewire.food-dna.index', [
            'kuechenTypLabel' => $kuechenTyp !== null ? TeamSettingsService::KUECHEN_TYPEN[$kuechenTyp] : null,
        ])->layout('platform::layouts.app');
    }
}
