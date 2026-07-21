<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;

/**
 * Team-Food-DNA als Einstellungen-Sektion (Ebene 1 der DNA-Kette Team → Kunde → Foodbook).
 * Umzug 2026-07-21: die stabile Betriebs-Identität gehört zu den Team-Einstellungen, nicht
 * als eigener Top-Level-Nav-Punkt. Reuse des geteilten Canvas-Boards (wie Foodbook/Kunde/Concept).
 */
class FoodDna extends Component
{
    use ManagesCanvas;

    public function mount(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null) {
            $this->canvasInit('food_dna', 'team', $team->id);
        }
    }

    public function render()
    {
        return view('foodalchemist::livewire.settings.food-dna');
    }
}
