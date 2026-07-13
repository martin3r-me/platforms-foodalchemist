<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * R3.2 (Block C, layout-first) — Externe Kunden-Präsentation eines Foodbooks als
 * schöne Web-Seite: Kunden-Projektion (dokumentDaten intern=false, serverseitig
 * EK-frei) + Wording-Kette, Preise PRO PERSON (kein Pax). Aktuell auth-gated
 * (eingeloggte Vorschau); öffentlicher Share-Link = separater Core-Auth-Entscheid
 * (Martin). Per-Kunde-CI + echte Gericht-Bilder = spätere Iteration (#461).
 */
class Praesentation extends Component
{
    public int $foodbookId;

    public function mount(int $id): void
    {
        $this->foodbookId = $id;
    }

    public function render(FoodbookService $svc)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $fb = $svc->detail($team, $this->foodbookId) ?? abort(404);

        // Kundensicht: dieselbe serverseitige Projektion wie das Kundendokument — NIE EK/W%.
        $data = $svc->dokumentDaten($team, $fb, false);

        return view('foodalchemist::livewire.foodbooks.praesentation', $data)
            ->layout('platform::layouts.app');
    }
}
