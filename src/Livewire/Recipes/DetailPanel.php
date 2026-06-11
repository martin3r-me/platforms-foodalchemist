<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * M4-05 / P-1: Rezept-DetailPanel (rechte Page-Sidebar) — KPI-Karte
 * (EK/kg·EK·Yield·Konfidenz), Beschreibung, Zutaten read-only mit GP-Links +
 * EK je Zeile + Lineage kursiv (Nachtrag 13_REFERENZ), Diät-&-Spezifikations-
 * Sektion (spec_*-Flags), Eignungs- + Equipment-Chips.
 * Verwandte-Rezepte/Kohäsion folgen mit M5 (GL-10-Daten).
 */
class DetailPanel extends Component
{
    public ?int $recipeId = null;

    public function mount(?int $recipeId = null): void
    {
        $this->recipeId = $recipeId;
    }

    #[On('recipe-selected')]
    public function zeige(int $id): void
    {
        $this->recipeId = $id;
    }

    public function neuBerechnen(): void
    {
        if ($this->recipeId !== null) {
            app(RecipeRecomputeService::class)->recomputeAndPropagate($this->recipeId);
            $this->dispatch('recipe-gespeichert');
        }
    }

    public function render(RecipeService $recipes)
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null
            ? $recipes->detail($team, $this->recipeId)
            : null;

        return view('foodalchemist::livewire.recipes.detail-panel', [
            'rezept' => $rezept,
            // Nachtrag 13_REFERENZ: EK je Zeile — dieselbe T3-Kaskade wie der Recompute (eine Regel-Stelle)
            'zeilenEk' => $rezept !== null ? app(RecipeRecomputeService::class)->zeilenKosten($rezept) : [],
        ]);
    }
}
