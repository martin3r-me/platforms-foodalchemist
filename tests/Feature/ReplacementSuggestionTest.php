<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\ReplacementSuggestionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
});

it('rankt ausschließlich DB-Kandidaten aus GP, Basisrezept und ungemappten Lieferantenartikeln', function () {
    $source = $this->makeGp($this->rootTeam, 'Rote Bete Creme');
    $source->update(['commodity_group_code' => '01']);
    $gp = $this->makeGp($this->rootTeam, 'Rote Bete Püree');
    $gp->update(['commodity_group_code' => '01']);
    $recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rote_bete_creme_basis',
        'name' => 'Rote Bete Creme Basis', 'status' => 'draft', 'is_sales_recipe' => false,
    ]);
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Convenience GmbH']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Rote Bete Creme gegart', 'is_discontinued' => false,
    ]);
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => null,
    ]);

    $result = app(ReplacementSuggestionService::class)->forGp($this->rootTeam, $source);
    $keys = collect($result)->map(fn (array $c) => $c['kind'].':'.$c['id']);

    expect($keys)->toContain('gp:'.$gp->id)
        ->toContain('recipe:'.$recipe->id)
        ->toContain('supplier_item:'.$la->id)
        ->and(collect($result)->every(fn (array $c) => isset($c['name'], $c['score'], $c['reason'])))->toBeTrue();
});
