<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * E1 (#507): hält den Rezept-Embedding-Vektor bei interaktiven Edits synchron.
 * Der Embed-Text hängt an Name + Kategorie + Top-Zutaten — die Zutaten ändern
 * sich über {@see \Platform\FoodAlchemist\Services\RecipeService::syncIngredients()},
 * das nach dem Persist recompute + (künftig) queueRecipe anstößt. Der Observer
 * fängt hier Name-/Kategorie-/Status-Änderungen am Rezept selbst ab.
 * No-op ohne Provider (Sandbox).
 */
class RecipeEmbeddingObserver
{
    private const RELEVANT = ['name', 'category_id', 'status', 'is_sales_recipe'];

    public function created(FoodAlchemistRecipe $recipe): void
    {
        app(PoolEmbeddingService::class)->queueRecipe($recipe);
    }

    public function updated(FoodAlchemistRecipe $recipe): void
    {
        if (! $recipe->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queueRecipe($recipe);
    }

    public function deleted(FoodAlchemistRecipe $recipe): void
    {
        app(PoolEmbeddingService::class)->deleteRecipe((int) $recipe->id, $recipe->team_id);
    }
}
