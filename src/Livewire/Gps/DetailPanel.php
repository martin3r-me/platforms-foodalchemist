<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAggregateService;

/**
 * M3-03/05 / P-1: GP-DetailPanel — eigene Livewire-Komponente in der rechten
 * Page-Sidebar (Platzierungs-Entscheid), hört auf `gp-selected` (kein Full-Reload).
 * Sektionen Allergene/Zusatzstoffe/Nährwerte laden LAZY (erst beim Aufklappen,
 * M3-05-DoD) — der Aufklapp-Zustand übersteht den GP-Wechsel (Kontext-Erhalt).
 */
class DetailPanel extends Component
{
    public ?int $gpId = null;

    /** @var array<string, bool> aufgeklappte Sektionen (allergene|zusatzstoffe|naehrwerte) */
    public array $offen = [];

    public function mount(?int $gpId = null): void
    {
        $this->gpId = $gpId;
    }

    #[On('gp-selected')]
    public function zeige(int $id): void
    {
        $this->gpId = $id; // $offen bleibt — gleiche Sektionen beim nächsten GP offen
    }

    public function toggleSektion(string $sektion): void
    {
        if (in_array($sektion, ['allergene', 'zusatzstoffe', 'naehrwerte'], true)) {
            $this->offen[$sektion] = ! ($this->offen[$sektion] ?? false);
        }
    }

    public function render(GpAggregateService $aggregate)
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = null;
        if ($this->gpId !== null && $team !== null) {
            $gp = FoodAlchemistGp::visibleToTeam($team)
                ->with(['warengruppe', 'preferredCountUnit', 'leadLa', 'derivatVon'])
                ->find($this->gpId);
        }

        return view('foodalchemist::livewire.gps.detail-panel', [
            'gp' => $gp,
            'team' => $team,
            // lazy: nur offene Sektionen rechnen (M3-05-DoD)
            'allergene' => $gp !== null && ($this->offen['allergene'] ?? false) ? $aggregate->allergene($gp) : null,
            'allergenKonfidenz' => $gp !== null && ($this->offen['allergene'] ?? false) ? $aggregate->allergenKonfidenz($gp) : null,
            'zusatzstoffe' => $gp !== null && ($this->offen['zusatzstoffe'] ?? false) ? $aggregate->zusatzstoffe($gp) : null,
            'naehrwerte' => $gp !== null && ($this->offen['naehrwerte'] ?? false) ? $aggregate->naehrwerte($gp) : null,
        ]);
    }
}
