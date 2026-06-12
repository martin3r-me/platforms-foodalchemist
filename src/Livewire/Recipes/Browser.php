<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * M4-04 / P-1 + Screen 4: Basisrezept-Browser — Hauptgruppen-Baum in linker
 * Page-Sidebar (Platzierungs-Entscheid), Auswahl/Filter in der URL
 * (Kontext-Erhalt-Gebot), DetailPanel rechts hört auf `recipe-selected`.
 */
class Browser extends Component
{
    use WithPagination;

    #[Url(as: 'rezept')]
    public ?int $recipeId = null;

    #[Url(as: 'hg')]
    public ?int $hauptgruppe = null;

    #[Url(as: 'kat')]
    public ?int $kategorie = null;

    #[Url]
    public string $status = '';

    #[Url(as: 'geschmack')]
    public string $geschmack = '';

    #[Url(as: 'fertigung')]
    public string $fertigung = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'zeilen')]
    public int $perPage = 100;

    public function waehleHauptgruppe(?int $id): void
    {
        $this->hauptgruppe = $this->hauptgruppe === $id ? null : $id;
        $this->kategorie = null;
        $this->resetPage();
    }

    public function waehleKategorie(int $id): void
    {
        $this->kategorie = $this->kategorie === $id ? null : $id;
        $this->resetPage();
    }

    public function waehleRezept(int $id): void
    {
        $this->recipeId = $id;
        $this->dispatch('recipe-selected', id: $id);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedGeschmack(): void
    {
        $this->resetPage();
    }

    public function updatedFertigung(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array((int) $this->perPage, [25, 50, 100, 250, 500], true) ? (int) $this->perPage : 100;
        $this->resetPage();
    }

    #[On('recipe-gespeichert')]
    public function aktualisiere(): void
    {
        // Edit/Recompute → Tabelle + Baum-Counts neu rendern (Kontext bleibt)
    }

    // ── M4-12: Bulk-Status (Checkbox-Auswahl) ───────────────────────────

    /** @var array<int, bool> */
    public array $auswahl = [];

    // ── M7-06: Bulk-Autopilot (Queue + Fortschritts-Polling) ─────────────

    public ?int $bulkRunId = null;

    public function bulkAnreichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $ids = array_map('intval', array_keys(array_filter($this->auswahl)));
        if ($team === null || $ids === []) {
            return;
        }
        $this->bulkRunId = app(\Platform\FoodAlchemist\Services\BulkEnrichService::class)->starte($team, $ids);
        $this->auswahl = [];
    }

    public function bulkAlleUebernehmen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->bulkRunId !== null) {
            app(\Platform\FoodAlchemist\Services\BulkEnrichService::class)->alleUebernehmen($team, $this->bulkRunId);
            $this->bulkRunId = null;
            $this->dispatch('recipe-gespeichert');
        }
    }

    public function bulkSchliessen(): void
    {
        $this->bulkRunId = null;                                     // Vorschläge bleiben offen (Review später)
    }

    public function bulkStatus(string $status): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $ids = array_map('intval', array_keys(array_filter($this->auswahl)));
        if ($team === null || $ids === []) {
            return;
        }
        app(RecipeService::class)->bulkStatus($team, $ids, $status);
        $this->auswahl = [];
    }

    public function mount(): void
    {
        if ($this->recipeId !== null) {
            $this->dispatch('recipe-selected', id: $this->recipeId); // Kontext-Erhalt nach Reload
        }
    }

    public function render(RecipeService $recipes)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $filters = [
            'search' => $this->search,
            'hauptgruppe' => $this->hauptgruppe,
            'kategorie' => $this->kategorie,
            'status' => $this->status,
            'geschmack' => $this->geschmack,
            'fertigung' => $this->fertigung,
        ];

        return view('foodalchemist::livewire.recipes.browser', [
            'rezepte' => $recipes->paginateBrowser($filters, $team, in_array($this->perPage, [25, 50, 100, 250, 500], true) ? $this->perPage : 100),
            'hauptgruppen' => $recipes->mainGroups($team),
            'hgCounts' => $recipes->hauptgruppenCounts($team, $filters),
            'katCounts' => $this->hauptgruppe !== null ? $recipes->kategorieCounts($team, $this->hauptgruppe) : [],
            'kategorien' => $this->hauptgruppe !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::where('main_group_id', $this->hauptgruppe)->orderBy('sort_order')->get()
                : collect(),
            'statusFaelle' => RecipeStatus::cases(),
            'statusCounts' => $recipes->statusCounts($team),
        ])->layout('platform::layouts.app');
    }
}
