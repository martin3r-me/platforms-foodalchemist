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

    // F1: geteilte Concept-Dimensionen als Filter (spiegelt Concepter-Browser).
    #[Url(as: 'form')]
    public string $servierformFilter = '';

    #[Url(as: 'event')]
    public string $eventtypFilter = '';

    #[Url(as: 'moment')]
    public string $momentFilter = '';

    #[Url(as: 'season')]
    public string $saisonFilter = '';

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

    /** F1: Facetten-Filter togglen (servierformFilter|eventtypFilter|momentFilter|saisonFilter). */
    public function waehleFacette(string $feld, string $wert): void
    {
        if (! in_array($feld, ['servierformFilter', 'eventtypFilter', 'momentFilter', 'saisonFilter'], true)) {
            return;
        }
        $this->{$feld} = $this->{$feld} === $wert ? '' : $wert;
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
        $team = $this->team();
        $items = $formats->paginateBrowser([
            'search' => $this->search,
            'status' => $this->statusFilter,
            'origin' => $this->originFilter,
            'servierform' => $this->servierformFilter !== '' ? $this->servierformFilter : null,
            'eventtyp' => $this->eventtypFilter !== '' ? $this->eventtypFilter : null,
            'einsatzmoment' => $this->momentFilter !== '' ? $this->momentFilter : null,
            'season' => $this->saisonFilter !== '' ? $this->saisonFilter : null,
        ], $team);

        return view('foodalchemist::livewire.formate.browser', [
            'items' => $items,
            // F1: Facetten-Vokabular für die Filter (geteilt mit den Concepts, aus den Einstellungen).
            'facetteServierformen' => \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where('is_inactive', false)
                ->orderBy('sort_order')->get(['id', 'code', 'label']),
            'facetteEventtypen' => \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'facetteMomente' => \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'facetteSaisons' => \Platform\FoodAlchemist\Models\FoodAlchemistSaison::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
