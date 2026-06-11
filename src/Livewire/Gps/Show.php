<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpService;

/**
 * GP-Detail (Vertical Slice): Stammdaten, Klassifikation, Allergen-Override-Layer (GL-01),
 * Tags, Kalkulations-Defaults, Lineage. Route-Model-Binding analog Planner
 * (Parameter = Modelname in camelCase: {foodAlchemistGp}).
 */
class Show extends Component
{
    public FoodAlchemistGp $foodAlchemistGp;

    public function mount(FoodAlchemistGp $foodAlchemistGp): void
    {
        $this->foodAlchemistGp = $foodAlchemistGp->load([
            'warengruppe', 'preferredCountUnit', 'derivatVon', 'mergedInto', 'leadLa.supplier',
        ]);
    }

    public function render(GpService $gps)
    {
        return view('foodalchemist::livewire.gps.show', [
            'gp' => $this->foodAlchemistGp,
            'las' => $gps->lasForGp($this->foodAlchemistGp),
        ])->layout('platform::layouts.app');
    }
}
