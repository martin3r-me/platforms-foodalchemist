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

    /** Ebene 2 (D3): auf Betrieb-Wechsel im Sidebar neu rendern (render() zieht den neuen Kontext). */
    #[\Livewire\Attributes\On('aktiver-betrieb-geaendert')]
    public function betriebGewechselt(): void
    {
    }

    public function render(FoodbookService $svc)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $fb = $svc->detail($team, $this->foodbookId) ?? abort(404);

        // Reconcile (Ebene 2): dokument-gebundener Betrieb gewinnt, sonst der aktive Betrieb.
        $outlet = $fb->outlet_id !== null
            ? \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)->find($fb->outlet_id)
            : app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)->current($team);

        // Kundensicht: dieselbe serverseitige Projektion wie das Kundendokument — NIE EK/W%.
        $data = $svc->dokumentDaten($team, $fb, false, [], false, $outlet);

        return view('foodalchemist::livewire.foodbooks.praesentation', $data)
            ->layout('platform::layouts.app');
    }
}
