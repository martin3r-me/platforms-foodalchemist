<?php

namespace Platform\FoodAlchemist\Livewire\Speiseplan;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * Speiseplan-Browser (Spec 29 / Editor-Rollout) — schlanke Übersichts-Liste. Das Planen selbst
 * (Wochen-Matrix, Monat, Linien, Kennzahlen) lebt im Fullscreen-Dark-Editor (Speiseplan\Editor),
 * geöffnet per `speiseplan-editor.bearbeiten` {id}. Der Browser hört auf `speiseplan-geaendert`
 * und frischt seine Zähler auf. Herausgezogen aus dem früheren Master-Detail-Vollbild.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Refresh der Browser-Zähler nach einer Änderung im Editor. */
    #[On('speiseplan-geaendert')]
    public function aktualisieren(): void
    {
        // reines Re-Render — withCount('entries') zieht die neuen Zahlen.
    }

    public function neu(SpeiseplanService $svc): void
    {
        $sp = $svc->create($this->team(), ['name' => 'Neuer Speiseplan']);
        $this->dispatch('speiseplan-editor.bearbeiten', id: $sp->id);
    }

    public function waehle(int $id): void
    {
        $this->dispatch('speiseplan-editor.bearbeiten', id: $id);
    }

    public function render(SpeiseplanService $svc)
    {
        return view('foodalchemist::livewire.speiseplan.index', [
            'plaene' => $svc->paginateBrowser(['search' => $this->search], $this->team()),
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
