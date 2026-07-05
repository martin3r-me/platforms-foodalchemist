<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Diagnose-Guard zu Dominiques Meldung „Garverlust speichert nicht / hat keine
 * Wirkung auf die Menge". Beweist am Server-Pfad (syncIngredients → Recompute),
 * dass die manuelle Garverlust-% PERSISTIERT und den AUTO-Yield reduziert.
 * Bleibt der Yield im UI trotzdem gleich → Ursache ist YIELD-MANUELL (überschreibt
 * die Auto-Summe) oder live-vs-Save (Yield rechnet erst beim Speichern neu).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Garverlust wird gespeichert UND reduziert den Auto-Yield', function () {
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'garv', 'name' => 'Test: Garverlust', 'status' => 'draft',
    ]);
    $g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $svc = app(RecipeService::class);

    // 1000 g, ohne Garverlust
    $svc->syncIngredients($this->rootTeam, $r->id, [[
        'id' => null, 'quantity' => 1000, 'unit_vocab_id' => $g->id, 'raw_text' => 'Mehl', 'cooking_loss_pct' => 0,
    ]]);
    $yield0 = (float) $r->refresh()->yield_kg;
    $zeileId = (int) DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $r->id)->value('id');
    expect($yield0)->toBeGreaterThan(0.0);

    // gleiche Zeile mit 40 % Garverlust
    $svc->syncIngredients($this->rootTeam, $r->id, [[
        'id' => $zeileId, 'quantity' => 1000, 'unit_vocab_id' => $g->id, 'raw_text' => 'Mehl', 'cooking_loss_pct' => 40,
    ]]);
    $r->refresh();

    expect((int) round((float) DB::table('foodalchemist_recipe_ingredients')->where('id', $zeileId)->value('cooking_loss_pct')))->toBe(40)  // PERSISTIERT
        ->and((float) $r->yield_kg)->toBeLessThan($yield0)                                                                                 // WIRKT
        ->and(abs((float) $r->yield_kg - 0.6 * $yield0))->toBeLessThan(0.001);                                                             // exakt 60 % (1−0,4)
});
