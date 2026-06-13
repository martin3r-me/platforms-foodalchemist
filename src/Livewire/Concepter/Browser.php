<?php

namespace Platform\FoodAlchemist\Livewire\Concepter;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketService;

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
        $this->resetPage();
        $this->dispatch('concepter-selected', type: $this->tab, id: null);
    }

    public function waehle(int $id): void
    {
        $this->selectedId = $id;
        $this->dispatch('concepter-selected', type: $this->tab, id: $id);
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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShowVorlagen(): void
    {
        $this->selectedId = null;
        $this->resetPage();
    }

    /** Neues Concept/Paket anlegen → auswählen (Bearbeiten via Editor M10R-3). */
    public function neu(ConceptService $concepts, PaketService $pakete): void
    {
        $team = $this->team();
        if ($this->tab === 'pakete') {
            $p = $pakete->create($team, ['name' => 'Neues Paket']);
            $this->waehle($p->id);

            return;
        }
        $c = $concepts->create($team, ['name' => 'Neues Concept', 'is_vorlage' => $this->showVorlagen]);
        $this->waehle($c->id);
    }

    #[On('concepter-gespeichert')]
    #[On('concepter-geloescht')]
    public function aktualisiere(?int $id = null): void
    {
        // Edit/Delete im Detail-Panel/Editor → Liste + Counts neu rendern.
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
            $kategorienFlat = [];
        } else {
            $items = $concepts->paginateBrowser([
                'search' => $this->search,
                'klasse' => $this->klasse,
                'vorlagen' => $this->showVorlagen,
                'category' => $this->categoryFilter !== '' ? $this->categoryFilter : null,
            ], $team);
            $klassen = $concepts->klassen($team);
            $rollen = [];
            $kategorienFlat = $concepts->categoriesFlat($team);
        }

        return view('foodalchemist::livewire.concepter.browser', [
            'items' => $items,
            'klassen' => $klassen,
            'rollen' => $rollen,
            'kategorienFlat' => $kategorienFlat,
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
