<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\VocabularyService;
use RuntimeException;

/**
 * M1-04 / D-1: Rezept-Taxonomie — Hauptgruppen + Kategorien, CRUD + Sortierung.
 * Quelle: Skript-204-Import; die M4-Browser-Bäume lesen aus denselben Service-Methoden.
 */
class Taxonomie extends Component
{
    public ?int $hauptgruppeId = null;

    public ?int $editId = null;

    public array $form = [];

    public array $neu = ['bezeichnung' => '', 'technik' => '', 'sort_order' => 999];

    public ?string $fehler = null;

    public function waehleHg(int $id): void
    {
        $this->hauptgruppeId = $id;
        $this->reset('editId', 'form', 'fehler');
    }

    public function edit(int $id): void
    {
        $kat = app(VocabularyService::class)->listRecipeCategories($this->team(), $this->hauptgruppeId)->firstWhere('id', $id);
        if ($kat === null) {
            return;
        }
        $this->editId = $id;
        $this->form = $kat->only(['bezeichnung', 'technik', 'sort_order']);
        $this->fehler = null;
    }

    public function save(): void
    {
        try {
            app(VocabularyService::class)->updateRecipeCategory($this->team(), (int) $this->editId, $this->form);
            $this->reset('editId', 'form');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function create(): void
    {
        try {
            app(VocabularyService::class)->createRecipeCategory($this->team(), (int) $this->hauptgruppeId, $this->neu);
            $this->reset('neu', 'fehler');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function delete(int $id): void
    {
        try {
            app(VocabularyService::class)->deleteRecipeCategory($this->team(), $id);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function hgSort(int $id, int $sortOrder): void
    {
        try {
            app(VocabularyService::class)->updateMainGroupSort($this->team(), $id, $sortOrder);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(VocabularyService $vocab)
    {
        $team = $this->team();
        $hauptgruppen = $vocab->listMainGroups($team);
        $this->hauptgruppeId ??= $hauptgruppen->first()?->id;

        return view('foodalchemist::livewire.settings.taxonomie', [
            'team' => $team,
            'hauptgruppen' => $hauptgruppen,
            'kategorien' => $this->hauptgruppeId ? $vocab->listRecipeCategories($team, $this->hauptgruppeId) : collect(),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
