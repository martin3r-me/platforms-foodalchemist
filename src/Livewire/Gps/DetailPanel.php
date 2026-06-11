<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * M3-03 / P-1: GP-DetailPanel — eigene Livewire-Komponente in der rechten
 * Page-Sidebar (Platzierungs-Entscheid), hört auf `gp-selected` (kein Full-Reload).
 * Sektionen Allergene/Zusatzstoffe/Nährwerte kommen lazy mit M3-05.
 */
class DetailPanel extends Component
{
    public ?int $gpId = null;

    public function mount(?int $gpId = null): void
    {
        $this->gpId = $gpId;
    }

    #[On('gp-selected')]
    public function zeige(int $id): void
    {
        $this->gpId = $id;
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = null;
        if ($this->gpId !== null && $team !== null) {
            $gp = FoodAlchemistGp::visibleToTeam($team)
                ->with(['warengruppe', 'preferredCountUnit', 'leadLa', 'derivatVon'])
                ->find($this->gpId);
        }

        return view('foodalchemist::livewire.gps.detail-panel', ['gp' => $gp, 'team' => $team]);
    }
}
