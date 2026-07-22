<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * M5-07 / D-7: Pairing-Netz-Modal — Empfehler (2026-07-22 Redesign): »was passt
 * zum Gericht, nach Typ«. Zentrum = Gericht, Innenring = Kern-Anker, aussen die
 * Pairing-Kandidaten in Typ-Sektoren (erprobt/aroma/kontrast), unten komplementäre
 * Basisrezepte. Layout serverseitig (PairingService::pairingNetz, deterministisch)
 * — D3 (resources/js/pairing-netz) zeichnet nur + filtert clientseitig nach Typ.
 * Kein Roundtrip ausser zeigeRezept (Klick auf Basisrezept).
 */
class PairingNetzModal extends Component
{
    public ?int $recipeId = null;

    #[On('pairing-netz.oeffnen')]
    public function oeffnen(int $recipeId): void
    {
        $this->recipeId = $recipeId;
        $this->dispatch('modal.open', name: 'pairing-netz');
    }

    public function zeigeRezept(int $id): void
    {
        $this->dispatch('recipe-selected', id: $id);
        $this->dispatch('modal.close', name: 'pairing-netz');
    }

    public function render(PairingService $pairings)
    {
        $team = Auth::user()?->currentTeamRelation;
        $netz = $team !== null && $this->recipeId !== null
            ? $pairings->pairingNetz($team, $this->recipeId)
            : ['nodes' => [], 'edges' => [], 'meta' => []];

        return view('foodalchemist::livewire.recipes.pairing-netz-modal', ['netz' => $netz]);
    }
}
