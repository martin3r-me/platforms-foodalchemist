<?php

namespace Platform\FoodAlchemist\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\ActiveOutletContext;

/**
 * Ebene 2 (D2): „aktiver Betrieb"-Umschalter, oben im FA-Sidebar (linkes Panel).
 * Setzt den ambienten {@see ActiveOutletContext} (Session); die Preis-Flächen lösen dagegen
 * auf und rechnen auf das Event `aktiver-betrieb-geaendert` neu. FA-safe (kein Core-Layout).
 */
class ActiveOutletBar extends Component
{
    public ?int $aktiverBetrieb = null;

    public function mount(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $this->aktiverBetrieb = $team !== null ? app(ActiveOutletContext::class)->current($team)?->id : null;
    }

    public function updatedAktiverBetrieb($value): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $id = ($value === '' || $value === null) ? null : (int) $value;
        $outlet = app(ActiveOutletContext::class)->set($team, $id);
        $this->aktiverBetrieb = $outlet?->id;
        // Alle Preis-Flächen auffordern, neu zu rechnen.
        $this->dispatch('aktiver-betrieb-geaendert');
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $betriebe = $team === null ? collect()
            : FoodAlchemistOutlet::where('team_id', $team->id)->where('is_inactive', false)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);

        return view('foodalchemist::livewire.active-outlet-bar', ['betriebe' => $betriebe]);
    }
}
