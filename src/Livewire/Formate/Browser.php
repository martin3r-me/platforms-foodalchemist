<?php

namespace Platform\FoodAlchemist\Livewire\Formate;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\FormatService;

/**
 * Format-Modul (Phase B): Top-Level-Browser „Formate" — Marken-/Themen-Container
 * über den Concepts (z. B. CHEFS.CORNER). 3-Panel im Concepter-Stil: links Filter
 * (Status/Herkunft) + Suche, Mitte dichte Tabelle, rechts Detail-Panel. Editor als
 * Fullscreen-Dark-Modal auf Seitenebene.
 */
class Browser extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'origin')]
    public string $originFilter = '';

    #[Url(as: 'sel')]
    public ?int $selectedId = null;

    public function mount(): void
    {
        if ($this->selectedId !== null) {
            $this->dispatch('formate-selected', id: $this->selectedId);
        }
    }

    public function waehle(int $id): void
    {
        $this->selectedId = $id;
        $this->dispatch('formate-selected', id: $id);
    }

    public function waehleStatus(string $wert): void
    {
        $this->statusFilter = $this->statusFilter === $wert ? '' : $wert;
        $this->resetPage();
    }

    public function waehleOrigin(string $wert): void
    {
        $this->originFilter = $this->originFilter === $wert ? '' : $wert;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Neues Format anlegen → auswählen + Editor öffnen. */
    public function neu(FormatService $formats): void
    {
        $f = $formats->create($this->team(), ['name' => 'Neues Format']);
        $this->waehle($f->id);
        $this->dispatch('formate-editor.oeffnen', id: $f->id);
    }

    /** Namensklick in der Tabelle → Editor öffnen. */
    public function bearbeite(int $id): void
    {
        $this->waehle($id);
        $this->dispatch('formate-editor.oeffnen', id: $id);
    }

    public function statusSetzen(int $id, string $status, FormatService $formats): void
    {
        try {
            $formats->setStatus($this->team(), $id, $status);
        } catch (\RuntimeException) {
        }
    }

    #[On('formate-gespeichert')]
    public function aktualisiere(): void
    {
        // Edit im Editor/Detail → Liste neu rendern (Auswahl bleibt).
    }

    #[On('formate-geloescht')]
    public function entfernt(?int $id = null): void
    {
        if ($id !== null && $id === $this->selectedId) {
            $this->selectedId = null;
        }
    }

    public function render(FormatService $formats)
    {
        $items = $formats->paginateBrowser([
            'search' => $this->search,
            'status' => $this->statusFilter,
            'origin' => $this->originFilter,
        ], $this->team());

        return view('foodalchemist::livewire.formate.browser', [
            'items' => $items,
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
