<?php

namespace Platform\FoodAlchemist\Livewire\Speisekarte;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\SpeisekarteLeitstelleService;

/**
 * Speisekarte-Leitstelle-Rail (Stufe E) — read-only Cockpit-Streifen mit der abgeleiteten
 * Fertigstellungs-Checkliste. Frischt auf, wenn im Editor etwas gespeichert wird.
 */
class LeitstelleRail extends Component
{
    public int $karteId;

    public function mount(int $karteId): void
    {
        $this->karteId = $karteId;
    }

    /** Editor meldet Änderungen → Checkliste neu ableiten. */
    #[On('gespeichert')]
    public function aktualisieren(): void
    {
        // reines Re-Render; checkliste() wird in render() frisch gezogen
    }

    public function render(SpeisekarteLeitstelleService $svc)
    {
        return view('foodalchemist::livewire.speisekarte.leitstelle-rail', [
            'stand' => $svc->checkliste($this->team(), $this->karteId),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
