<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Regression: `recompute --all` (recomputeAll → Kahn-Topo-Sort) crashte mit „Undefined
 * array key" wenn eine LIVE Zutat-Kante auf einen soft-gelöschten Elter zeigt (Prod:
 * Rezept 3416). Fix: verwaiste Parent-Kante wird beim Graph-Aufbau ignoriert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeRecomputeService::class);
    $this->g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ])->id;
    $this->mkRecipe = fn (string $n) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => str_replace(' ', '_', mb_strtolower($n)), 'name' => $n, 'status' => 'draft',
    ]);
});

it('recomputeAll ueberspringt verwaiste Parent-Kante (soft-deleted Elter, Zutat-Kante live) ohne Crash', function () {
    $sub = ($this->mkRecipe)('Basis Sub');
    $parent = ($this->mkRecipe)('Waise Parent');
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $parent->id, 'position' => 1,
        'raw_text' => 'Sub', 'match_method' => 'manual', 'referenced_recipe_id' => $sub->id,
        'unit_vocab_id' => $this->g, 'quantity' => 100,
    ]);
    // Parent soft-deleten OHNE Model-Events → Zutat-Kante bleibt live (repliziert Prod-Zustand Rezept 3416).
    DB::table('foodalchemist_recipes')->where('id', $parent->id)->update(['deleted_at' => now()]);

    $res = $this->svc->recomputeAll();   // darf NICHT „Undefined array key" werfen

    expect($res['reihenfolge_ok'])->toBeTrue()
        ->and($res['berechnet'])->toBe(1);   // nur der noch lebende Sub, nicht der verwaiste Parent
});
