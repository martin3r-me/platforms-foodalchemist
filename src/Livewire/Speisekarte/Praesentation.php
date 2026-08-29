<?php

namespace Platform\FoodAlchemist\Livewire\Speisekarte;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\ActiveOutletContext;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * Speisekarten-Präsentation (Web-Ansicht, auth-gated) — Kundensicht ohne EK.
 * Stufe A: schlanke Lese-Ansicht der Karte; Branding-Politur folgt in Stufe C.
 *
 * Ebene 2 (D3): die Positionspreise folgen dem Betrieb — dokument-gebundenes outlet_id
 * gewinnt, sonst der aktive Betrieb ({@see ActiveOutletContext}); Wechsel im Sidebar rechnet neu.
 */
class Praesentation extends Component
{
    public int $id;

    public function mount(int $id): void
    {
        $this->id = $id;
    }

    /** Ebene 2 (D3): auf Betrieb-Wechsel im Sidebar neu rendern (render() zieht den neuen Kontext). */
    #[On('aktiver-betrieb-geaendert')]
    public function betriebGewechselt(): void
    {
    }

    public function render(SpeisekarteService $svc)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $karte = $svc->detail($team, $this->id) ?? abort(404);

        // Reconcile (Ebene 2): dokument-gebundener Betrieb gewinnt, sonst der aktive Betrieb.
        $outlet = $karte->outlet_id !== null
            ? FoodAlchemistOutlet::where('team_id', $team->id)->find($karte->outlet_id)
            : app(ActiveOutletContext::class)->current($team);

        $preise = [];
        foreach ($karte->sections as $rubrik) {
            foreach ($rubrik->items as $pos) {
                $preise[$pos->id] = $svc->positionPreis($pos, $outlet);
            }
        }

        return view('foodalchemist::livewire.speisekarte.praesentation', [
            'karte' => $karte,
            'preise' => $preise,
            'branding' => $svc->brandingDaten($karte),
        ])->layout('platform::layouts.app');
    }
}
