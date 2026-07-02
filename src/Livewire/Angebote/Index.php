<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\AngebotService;

/**
 * #380 — Angebote-Browser, am Concepter orientiert (3-Panel: Filter links · Tabelle
 * mitte · Detail rechts). Listet kundengebundene Angebote/Anfragen; Auswahl/Filter
 * in der URL (V-17). Der Detail-/Edit-Teil lebt im Angebote\DetailPanel; der
 * Menü-Composer (Concepter-Slots am Angebot) folgt als nächste Stufe.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'sel')]
    public ?int $selectedId = null;

    public function mount(): void
    {
        if ($this->selectedId !== null) {
            $this->dispatch('angebot-selected', id: $this->selectedId);
        }
    }

    public function waehle(int $id): void
    {
        $this->selectedId = $id;
        $this->dispatch('angebot-selected', id: $id);
    }

    public function waehleStatus(string $wert): void
    {
        $this->statusFilter = $this->statusFilter === $wert ? '' : $wert;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Neue Anfrage anlegen → auswählen (Detail-Panel zeigt das Edit-Formular). */
    public function neu(AngebotService $svc): void
    {
        $a = $svc->create($this->team(), ['name' => 'Neue Anfrage']);
        $this->waehle($a->id);
    }

    #[On('angebot-gespeichert')]
    public function aktualisiere(): void
    {
        // Liste neu rendern (Auswahl bleibt).
    }

    #[On('angebot-geloescht')]
    public function entfernt(?int $id = null): void
    {
        if ($id !== null && $id === $this->selectedId) {
            $this->selectedId = null;
        }
    }

    public function render(AngebotService $svc)
    {
        $team = $this->team();

        return view('foodalchemist::livewire.angebote.index', [
            'items' => $svc->paginateBrowser([
                'search' => $this->search,
                'status' => $this->statusFilter,
            ], $team),
            'statusWerte' => $svc->statusWerte(),
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
