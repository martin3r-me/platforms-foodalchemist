<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Stück-Artikel: ein als „stk" referenziertes Sub-Rezept rechnet Menge UND Kosten
 * über den Stück-Ertrag (ertrag_stueck) der Basis — 1 Stück = Yield ÷ ertrag_stueck (g),
 * EK/Stück = EK-Basis ÷ ertrag_stueck. Vorher: count-Einheit eines Sub-Rezepts trug 0 bei.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Sub-Rezept per Stück: Yield + EK über ertrag_stueck', function () {
    $stk = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count',
    ]);
    $svc = app(RecipeService::class);

    // Basis: 1,0 kg Yield · 4,00 €/kg EK · 10 Stück Ertrag ⇒ 100 g/Stück, 0,40 €/Stück
    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sub-suppe', 'name' => 'Sub: Suppe', 'status' => 'draft',
    ]);
    DB::table('foodalchemist_recipes')->where('id', $basis->id)->update([
        'yield_kg' => 1.0, 'ek_per_kg_eur' => 4.0, 'ek_total_eur' => 4.0, 'yield_pieces' => 10,
    ]);

    // Gericht referenziert die Basis als „2 stk"
    $gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'hg-test', 'name' => 'HG: Test', 'status' => 'draft', 'is_sales_recipe' => true,
    ]);
    $svc->syncIngredients($this->rootTeam, $gericht->id, [[
        'referenced_recipe_id' => $basis->id, 'quantity' => 2, 'unit_vocab_id' => $stk->id, 'raw_text' => 'Sub: Suppe',
    ]]);
    $gericht->refresh();

    // 2 × 100 g = 200 g = 0,2 kg · 2 × 0,40 € = 0,80 €
    expect(abs((float) $gericht->yield_kg - 0.2))->toBeLessThan(0.001)
        ->and(abs((float) $gericht->ek_total_eur - 0.8))->toBeLessThan(0.001);
});
