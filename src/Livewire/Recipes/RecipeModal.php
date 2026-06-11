<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * M4-06 / P-2: Rezept-Stammdaten-Modal — Name (§1-Syntax-Hint, „Name putzen"-KI),
 * Herkunft, Hauptgruppe→Kategorie, Geschmack/Fertigung, yield_kg_manual (A-3),
 * VK-Flag. Edit triggert Recompute bei kalkulations-relevanten Feldern.
 */
class RecipeModal extends Component
{
    private const LEER = [
        'name' => '', 'herkunft' => '', 'kategorie_id' => null, 'hauptgruppe_id' => null,
        'geschmacksrichtung' => '', 'fertigungstiefe' => '', 'arbeitszeit_min' => null,
        'yield_kg_manual' => null, 'beschreibung' => '', 'ist_verkaufsrezept' => false,
    ];

    public ?int $recipeId = null;

    public array $form = self::LEER;

    public ?string $fehler = null;

    #[On('recipe-modal.oeffnen')]
    public function oeffnen(?int $id = null): void
    {
        $this->reset('fehler');
        $this->recipeId = $id;
        $this->form = self::LEER;

        if ($id !== null) {
            $team = Auth::user()?->currentTeamRelation;
            $r = FoodAlchemistRecipe::visibleToTeam($team)->with('kategorie:id,main_group_id')->find($id);
            if ($r !== null) {
                $this->form = [
                    'name' => $r->name,
                    'herkunft' => $r->herkunft ?? '',
                    'kategorie_id' => $r->kategorie_id,
                    'hauptgruppe_id' => $r->kategorie?->main_group_id,
                    'geschmacksrichtung' => $r->geschmacksrichtung ?? '',
                    'fertigungstiefe' => $r->fertigungstiefe ?? '',
                    'arbeitszeit_min' => $r->arbeitszeit_min,
                    'yield_kg_manual' => $r->yield_kg_manual,
                    'beschreibung' => $r->beschreibung ?? '',
                    'ist_verkaufsrezept' => (bool) $r->ist_verkaufsrezept,
                ];
            }
        }

        $this->dispatch('modal.open', name: 'recipe-modal');
    }

    public function speichern(RecipeService $recipes): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }

        try {
            $in = [...$this->form,
                'arbeitszeit_min' => $this->form['arbeitszeit_min'] !== null && $this->form['arbeitszeit_min'] !== '' ? (int) $this->form['arbeitszeit_min'] : null,
                'yield_kg_manual' => $this->form['yield_kg_manual'] !== null && $this->form['yield_kg_manual'] !== '' ? (float) str_replace(',', '.', (string) $this->form['yield_kg_manual']) : null,
            ];
            $recipe = $this->recipeId === null
                ? $recipes->create($team, $in)
                : $recipes->update($team, $this->recipeId, $in);

            $this->dispatch('modal.close', name: 'recipe-modal');
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('recipe-selected', id: $recipe->id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** „Name putzen": §1-Syntax via KI-Gateway (GL-07: Vorschlag direkt ins Feld, nichts persistiert). */
    public function namePutzen(AiGatewayService $ki): void
    {
        if (trim($this->form['name']) === '') {
            return;
        }
        $vorschlag = $ki->propose('recipe.name_putzen', ['name' => trim($this->form['name'])]);
        if (! empty($vorschlag->werte['name']) && is_string($vorschlag->werte['name'])) {
            $this->form['name'] = $vorschlag->werte['name'];
        }
    }

    public function updatedFormHauptgruppeId(): void
    {
        $this->form['kategorie_id'] = null;                        // Kategorie hängt an der HG
    }

    public function render(RecipeService $recipes)
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.recipes.recipe-modal', [
            'neu' => $this->recipeId === null,
            'hauptgruppen' => $team !== null ? $recipes->mainGroups($team) : collect(),
            'kategorien' => $this->form['hauptgruppe_id'] !== null
                ? FoodAlchemistRecipeCategory::where('main_group_id', $this->form['hauptgruppe_id'])->orderBy('sort_order')->get()
                : collect(),
            'keyVorschau' => trim($this->form['name']) !== '' ? $recipes->rezeptKey($this->form['name']) : '',
        ]);
    }
}
