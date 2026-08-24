<?php

namespace Platform\FoodAlchemist\Livewire\Concepter;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\ConcepterBewertungService;

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
        if ($vorlage === null || ! $vorlage->is_template) {
            return;
        }
        $fork = $concepts->forkVonVorlage($this->team(), $this->selectedId, $vorlage->name . ' – Kopie');
        $this->dispatch('concepter-gespeichert');
        $this->dispatch('concepter-editor.oeffnen', type: 'concepts', id: $fork->id);
    }

    /** C-13/B-10: Duplizieren + Kopie auswählen. Kaskade: Paket = kind=paket-Concept → ConceptService::duplicate erhält kind. */
    public function dupliziere(ConceptService $concepts): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $neu = $concepts->duplicate($this->team(), $this->selectedId);
        $this->selectedId = $neu->id;
        $this->dispatch('concepter-gespeichert');
        $this->dispatch('concepter-selected', type: $this->type, id: $neu->id);
    }

    /** Löschen — Kaskade: beide Reiter sind Concepts (kind concept|paket), also immer ConceptService. */
    public function loeschen(ConceptService $concepts): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $id = $this->selectedId;
        $concepts->delete($this->team(), $id);
        $this->selectedId = null;
        $this->dispatch('concepter-geloescht', id: $id);
    }

    public function render(ConceptService $concepts, ConcepterAggregateService $agg, ConcepterBewertungService $bewertung)
    {
        $team = $this->team();
        $concept = null;
        $cockpit = null;
        $aggregat = null;
        $bewertet = null;
        $istPaket = false;

        $verwendung = collect();

        // Kaskade (2026-08-24): beide Reiter sind Concepts (kind concept|paket) → ein Ladepfad.
        // Das Paket ist ein kind=paket-Concept mit eigenem Preis; nur die Anzeige (VK/Bewertung)
        // unterscheidet sich (→ $istPaket).
        if ($this->selectedId !== null) {
            $concept = $concepts->detail($team, $this->selectedId);
            if ($concept !== null) {
                $istPaket = $concept->kind === 'paket';
                $cockpit = $concepts->preisCockpit($concept);
                $aggregat = $agg->conceptAggregat($concept);
                // Menü-Bewertung (Gang-Dramaturgie etc.) nur für echte Concepts, nicht fürs Bündel.
                $bewertet = $istPaket ? null : $bewertung->bewerten($concept, $cockpit, $aggregat);
                $verwendung = $istPaket
                    ? $concepts->eingebettetInConcepts($team, $concept->id)
                    : $concepts->verwendetInFoodbooks($team, $concept->id);
            } else {
                $this->selectedId = null;
            }
        }

        return view('foodalchemist::livewire.concepter.detail-panel', [
            'concept' => $concept,
            'istPaket' => $istPaket,
            'cockpit' => $cockpit,
            'aggregat' => $aggregat,
            'bewertung' => $bewertet,
            'verwendung' => $verwendung,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
