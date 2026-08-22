<?php

namespace Platform\FoodAlchemist\Livewire\Formate;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FormatService;

/**
 * Format-Modul (Phase B): Detail-Panel — Identität + Editionen + Preis-Range +
 * Marketing-Hero des gewählten Formats. Aktionen (Bearbeiten/Löschen) dispatchen
 * an Browser/Editor.
 */
class DetailPanel extends Component
{
    public ?int $selectedId = null;

    #[On('formate-selected')]
    public function zeige(?int $id): void
    {
        $this->selectedId = $id;
    }

    #[On('formate-gespeichert')]
    public function aktualisiere(): void
    {
        // Re-render mit frischen Daten (Auswahl bleibt).
    }

    public function bearbeiten(): void
    {
        if ($this->selectedId !== null) {
            $this->dispatch('formate-editor.oeffnen', id: $this->selectedId);
        }
    }

    public function loeschen(FormatService $formats): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $id = $this->selectedId;
        try {
            $formats->delete($this->team(), $id);
        } catch (\RuntimeException) {
            return;
        }
        $this->selectedId = null;
        $this->dispatch('formate-geloescht', id: $id);
    }

    public function render(FormatService $formats)
    {
        $format = $this->selectedId !== null ? $formats->detail($this->team(), $this->selectedId) : null;
        if ($this->selectedId !== null && $format === null) {
            $this->selectedId = null;
        }

        return view('foodalchemist::livewire.formate.detail-panel', [
            'format' => $format,
            'range' => $format?->priceRange() ?? ['min' => null, 'max' => null],
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
