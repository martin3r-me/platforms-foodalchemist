<?php

namespace Platform\FoodAlchemist\Livewire\Concepter;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\ConcepterBewertungService;
use Platform\FoodAlchemist\Services\PaketService;

/**
 * M10R-2/3 / Doc 15 §10.4: kontext-adaptives Detail-Panel im VK-Stil. Zeigt für
 * Concept ODER Paket Stammdaten · Live-Preis/Kosten-Cockpit · Voll-Aggregat
 * (Nährwerte/Allergene/Diät/Arbeitszeit, ConcepterAggregateService) · Aktionen.
 * In M10R-3 kommen das Voll-Editor-Modal + die deterministische Bewertung dazu.
 */
class DetailPanel extends Component
{
    public ?int $selectedId = null;

    public string $type = 'concepts';   // concepts | pakete

    #[On('concepter-selected')]
    public function zeige(string $type, ?int $id): void
    {
        $this->type = in_array($type, ['concepts', 'pakete'], true) ? $type : 'concepts';
        $this->selectedId = $id;
    }

    #[On('concepter-gespeichert')]
    public function aktualisiere(): void
    {
        // Re-render mit frischen Daten (Kontext bleibt).
    }

    public function alsVorlage(ConceptService $concepts): void
    {
        if ($this->type === 'concepts' && $this->selectedId !== null) {
            $concepts->alsVorlageSpeichern($this->team(), $this->selectedId);
            $this->dispatch('concepter-gespeichert');
        }
    }

    /** M10R-4 (D-CON-7): „Aus Vorlage starten" — forkt das Slot-Gerüst und öffnet den Fork. */
    public function ausVorlage(ConceptService $concepts): void
    {
        if ($this->type !== 'concepts' || $this->selectedId === null) {
            return;
        }
        $vorlage = $concepts->detail($this->team(), $this->selectedId);
        if ($vorlage === null || ! $vorlage->is_vorlage) {
            return;
        }
        $fork = $concepts->forkVonVorlage($this->team(), $this->selectedId, $vorlage->name . ' – Kopie');
        $this->dispatch('concepter-gespeichert');
        $this->dispatch('concepter-editor.oeffnen', type: 'concepts', id: $fork->id);
    }

    public function loeschen(ConceptService $concepts, PaketService $pakete): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $id = $this->selectedId;
        if ($this->type === 'pakete') {
            $pakete->delete($this->team(), $id);
        } else {
            $concepts->delete($this->team(), $id);
        }
        $this->selectedId = null;
        $this->dispatch('concepter-geloescht', id: $id);
    }

    public function render(ConceptService $concepts, PaketService $pakete, ConcepterAggregateService $agg, ConcepterBewertungService $bewertung)
    {
        $team = $this->team();
        $concept = null;
        $paket = null;
        $cockpit = null;
        $aggregat = null;
        $bewertet = null;

        if ($this->selectedId !== null && $this->type === 'concepts') {
            $concept = $concepts->detail($team, $this->selectedId);
            if ($concept !== null) {
                $cockpit = $concepts->preisCockpit($concept);
                $aggregat = $agg->conceptAggregat($concept);
                $bewertet = $bewertung->bewerten($concept, $cockpit, $aggregat);
            } else {
                $this->selectedId = null;
            }
        } elseif ($this->selectedId !== null && $this->type === 'pakete') {
            $paket = $pakete->detail($team, $this->selectedId);
            if ($paket !== null) {
                $aggregat = $agg->paketAggregat($paket);
            } else {
                $this->selectedId = null;
            }
        }

        return view('foodalchemist::livewire.concepter.detail-panel', [
            'concept' => $concept,
            'paket' => $paket,
            'cockpit' => $cockpit,
            'aggregat' => $aggregat,
            'bewertung' => $bewertet,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
