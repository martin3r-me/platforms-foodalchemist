<?php

namespace Platform\FoodAlchemist\Livewire\Concepter;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Support\Curate;

/**
 * M10R-2 / Doc 15 §10.2+§10.4: vereinheitlichter Concepter-Browser — EIN Screen
 * mit Umschalter Concepts | Pakete (statt zwei getrennten Sidebar-Einträgen).
 * 3-Panel im VK-Stil: links Klasse/Kategorie- bzw. Rollen-Filter + Suche, Mitte
 * dichte Tabelle (Jarvis-Dichte), rechts Detail-Panel. Kontext/Auswahl in der URL
 * (V-17). „Bearbeiten" navigiert in M10R-2 noch in die bestehenden Editoren —
 * das Voll-Editor-Modal kommt in M10R-3.
 */
class Browser extends Component
{
    use WithPagination;

    /** Aktiver Reiter: 'concepts' (Menüs/Buffets) | 'pakete' (bepreiste Bündel). */
    #[Url]
    public string $tab = 'concepts';

    #[Url(as: 'q')]
    public string $search = '';

    /** Geteilte Klasse-Dimension (§10.3) — frei/wählbar, gilt für beide Reiter. */
    #[Url(as: 'klasse')]
    public string $klasse = '';

    /** Concepts: Kategorie-Baum-Filter ('' alle · 'none' ohne · ID). */
    #[Url(as: 'kat')]
    public string $categoryFilter = '';

    /** Concepts: echte vs. Vorlagen. */
    #[Url(as: 'vorlagen')]
    public bool $showVorlagen = false;

    /** Pakete: Rollen-Filter. */
    #[Url(as: 'rolle')]
    public string $rolleFilter = '';

    /** Concepts: Facetten-Filter (Umbau-Spec Phase 4b) — '' alle · ID. */
    #[Url(as: 'form')]
    public string $servierformFilter = '';

    #[Url(as: 'event')]
    public string $eventtypFilter = '';

    #[Url(as: 'moment')]
    public string $momentFilter = '';

    #[Url(as: 'saison')]
    public string $saisonFilter = '';

    #[Url(as: 'sel')]
    public ?int $selectedId = null;

    public function mount(): void
    {
        $this->normalisiereTab();
        if ($this->selectedId !== null) {
            $this->dispatch('concepter-selected', type: $this->tab, id: $this->selectedId);
        }
    }

    private function normalisiereTab(): void
    {
        if (! in_array($this->tab, ['concepts', 'pakete'], true)) {
            $this->tab = 'concepts';
        }
    }

    public function wechselTab(string $tab): void
    {
        if (! in_array($tab, ['concepts', 'pakete'], true) || $tab === $this->tab) {
            return;
        }
        $this->tab = $tab;
        $this->selectedId = null;
        $this->klasse = '';
        $this->categoryFilter = '';
        $this->rolleFilter = '';
        $this->servierformFilter = '';
        $this->eventtypFilter = '';
        $this->momentFilter = '';
        $this->saisonFilter = '';
        $this->resetPage();
        $this->dispatch('concepter-selected', type: $this->tab, id: null);
    }

    public function waehle(int $id): void
    {
        $this->selectedId = $id;
        $this->dispatch('concepter-selected', type: $this->tab, id: $id);
    }

    /** Inline-Status-Pflege im Concepts-Tab (canCurate-Gate, D1). */
    public function statusSetzen(int $id, string $status, ConceptService $concepts): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $concept = $team !== null ? FoodAlchemistConcept::visibleToTeam($team)->find($id) : null;
        if ($concept === null || ! Curate::canCurate(Auth::user(), $concept)) {
            return;
        }
        try {
            $concepts->setStatus($team, $id, $status);
        } catch (\RuntimeException) {
        }
    }

    public function waehleKlasse(string $wert): void
    {
        $this->klasse = $this->klasse === $wert ? '' : $wert;
        $this->resetPage();
    }

    public function waehleKategorie(string $wert): void
    {
        $this->categoryFilter = $this->categoryFilter === $wert ? '' : $wert;
        $this->resetPage();
    }

    public function waehleRolle(string $wert): void
    {
        $this->rolleFilter = $this->rolleFilter === $wert ? '' : $wert;
        $this->resetPage();
    }

    /** Facetten-Pill togglen (Klick auf aktive = deselect). */
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

    public function updatedShowVorlagen(): void
    {
        $this->selectedId = null;
        $this->resetPage();
    }

    /** Neues Concept/Paket anlegen → auswählen + Editor öffnen. */
    public function neu(ConceptService $concepts, PaketService $pakete): void
    {
        $team = $this->team();
        if ($this->tab === 'pakete') {
            $p = $pakete->create($team, ['name' => 'Neues Paket']);
            $this->waehle($p->id);
            $this->dispatch('concepter-editor.oeffnen', type: 'pakete', id: $p->id);

            return;
        }
        $c = $concepts->create($team, ['name' => 'Neues Concept', 'is_vorlage' => $this->showVorlagen]);
        $this->waehle($c->id);
        $this->dispatch('concepter-editor.oeffnen', type: 'concepts', id: $c->id);
    }

    /** Doppel-/Namensklick in der Tabelle → Editor öffnen. */
    public function bearbeite(int $id): void
    {
        $this->waehle($id);
        $this->dispatch('concepter-editor.oeffnen', type: $this->tab, id: $id);
    }

    #[On('concepter-gespeichert')]
    public function aktualisiere(): void
    {
        // Edit im Editor/Detail → Liste + Counts neu rendern (Auswahl bleibt).
    }

    #[On('concepter-geloescht')]
    public function entfernt(?int $id = null): void
    {
        if ($id !== null && $id === $this->selectedId) {
            $this->selectedId = null;
        }
    }

    public function render(ConceptService $concepts, PaketService $pakete)
    {
        $this->normalisiereTab();
        $team = $this->team();

        if ($this->tab === 'pakete') {
            $items = $pakete->paginateBrowser([
                'search' => $this->search,
                'klasse' => $this->klasse,
                'rolle' => $this->rolleFilter,
            ], $team);
            $klassen = $pakete->klassen($team);
            $rollen = $pakete->rollen($team);

        } else {
            $items = $concepts->paginateBrowser([
                'search' => $this->search,
                'klasse' => $this->klasse,
                'vorlagen' => $this->showVorlagen,
                'category' => $this->categoryFilter !== '' ? $this->categoryFilter : null,
                'servierform' => $this->servierformFilter !== '' ? $this->servierformFilter : null,
                'eventtyp' => $this->eventtypFilter !== '' ? $this->eventtypFilter : null,
                'einsatzmoment' => $this->momentFilter !== '' ? $this->momentFilter : null,
                'saison' => $this->saisonFilter !== '' ? $this->saisonFilter : null,
            ], $team);
            $klassen = $concepts->klassen($team);
            $rollen = [];
            // 4c: Kategorien-Baum abgelöst (Facetten) — categoryFilter bleibt als URL-Back-Compat wirksam
        }

        return view('foodalchemist::livewire.concepter.browser', [
            'items' => $items,
            'klassen' => $klassen,
            'rollen' => $rollen,
            // Facetten-Vokabulare (nur Concepts-Tab relevant)
            'facetteServierformen' => \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where('is_inactive', false)
                ->orderBy('sort_order')->get(['id', 'code', 'bezeichnung']),
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
