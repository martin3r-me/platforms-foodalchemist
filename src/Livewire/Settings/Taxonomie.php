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

    public string $neueHauptgruppe = '';

    public ?string $fehler = null;

    public function waehleHg(int $id): void
    {
        $this->hauptgruppeId = $id;
        $this->reset('editId', 'form', 'fehler');
    }

    /** Bug-Fix 2026-06-14: oberste Ebene (Hauptgruppe) anlegen — fehlte komplett. */
    public function hgNeu(): void
    {
        if (trim($this->neueHauptgruppe) === '') {
            return;
        }
        try {
            $hg = app(VocabularyService::class)->createMainGroup($this->team(), ['bezeichnung' => $this->neueHauptgruppe]);
            $this->hauptgruppeId = $hg->id;
            $this->reset('neueHauptgruppe', 'editId', 'form', 'fehler');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function edit(int $id): void
    {
        // HG-unabhängig: Kategorie aus dem ganzen Baum laden (Klick im Baum kann jede HG betreffen).
        $kat = app(VocabularyService::class)->listRecipeCategories($this->team())->firstWhere('id', $id);
        if ($kat === null) {
            return;
        }
        $this->editId = $id;
        $this->hauptgruppeId = (int) $kat->main_group_id; // „Neue Kategorie" zielt auf dieselbe HG
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

        // Flache Baum-Knotenliste für <x-foodalchemist::tree> (HG depth 0 → Kategorie depth 1).
        $katByHg = $vocab->listRecipeCategories($team)->groupBy('main_group_id');
        $baum = [];
        $collapsed = [];
        foreach ($hauptgruppen as $hg) {
            $kats = $katByHg[$hg->id] ?? collect();
            $baum[] = [
                'id' => 'hg:'.$hg->id, 'hg_id' => (int) $hg->id, 'kind' => 'hg', 'name' => $hg->bezeichnung,
                'depth' => 0, 'ancestors' => [], 'has_children' => $kats->isNotEmpty(), 'count' => (int) $hg->kategorie_count,
            ];
            $collapsed[] = 'hg:'.$hg->id; // Basisrezepte-Feel: zu Beginn alle HGs eingeklappt
            foreach ($kats as $kat) {
                $baum[] = [
                    'id' => 'kat:'.$kat->id, 'kat_id' => (int) $kat->id, 'kind' => 'kat', 'name' => $kat->bezeichnung,
                    'technik' => $kat->technik, 'depth' => 1, 'ancestors' => ['hg:'.$hg->id],
                    'has_children' => false, 'count' => (int) $kat->recipe_count,
                    'darf_edit' => \Platform\FoodAlchemist\Support\Curate::canCurate(Auth::user(), $kat),
                ];
            }
        }

        return view('foodalchemist::livewire.settings.taxonomie', [
            'team' => $team,
            'hauptgruppen' => $hauptgruppen,
            'baum' => $baum,
            'initialCollapsed' => $collapsed,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
