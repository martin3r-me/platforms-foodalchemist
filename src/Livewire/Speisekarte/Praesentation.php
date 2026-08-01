<?php

namespace Platform\FoodAlchemist\Livewire\Speisekarte;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * Speisekarten-Präsentation (Web-Ansicht, auth-gated) — Kundensicht ohne EK.
 * Stufe A: schlanke Lese-Ansicht der Karte; Branding-Politur folgt in Stufe C.
 */
class Praesentation extends Component
{
    public int $id;

    public function mount(int $id): void
    {
        $this->id = $id;
    }

    public function render(SpeisekarteService $svc)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $karte = $svc->detail($team, $this->id) ?? abort(404);

        $preise = [];
        foreach ($karte->sections as $rubrik) {
            foreach ($rubrik->items as $pos) {
                $preise[$pos->id] = $svc->positionPreis($pos);
            }
        }

        return view('foodalchemist::livewire.speisekarte.praesentation', [
            'karte' => $karte,
            'preise' => $preise,
        ])->layout('platform::layouts.app');
    }
}
