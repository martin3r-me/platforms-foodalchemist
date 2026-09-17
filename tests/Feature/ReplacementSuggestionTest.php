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

it('Fund Orchestrator (Paket G): component.replacement_suggest bekommt jetzt seinen Discovery-Block', function () {
    // Bisher trug der propose()-Call schon ein drittes Argument (target_table/target_id), aber
    // ohne den Discovery-Anschluss — PREVIEW zeigte brauchbare Substitutions-Dossiers, die nie
    // ankamen. Beleg: das dritte Argument enthält jetzt auch knowledge/knowledge_used/
    // knowledge_dropped_chars (Muster wie recipe.geschmack/recipe.sektor).
    $source = $this->makeGp($this->rootTeam, 'Rote Bete Creme');
    $source->update(['commodity_group_code' => '01']);
    $gp = $this->makeGp($this->rootTeam, 'Rote Bete Püree');
    $gp->update(['commodity_group_code' => '01']);

    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) use ($gp) {
        $mock->shouldReceive('propose')
            ->with('component.replacement_suggest', \Mockery::any(), \Mockery::on(function ($opts) {
                return is_array($opts) && array_key_exists('knowledge', $opts)
                    && array_key_exists('knowledge_used', $opts) && array_key_exists('knowledge_dropped_chars', $opts)
                    && array_key_exists('target_table', $opts) && array_key_exists('target_id', $opts);
            }))
            ->once()
            ->andReturn(new \Platform\FoodAlchemist\Services\Ai\AiProposal([
                'vorschlaege' => [['kind' => 'gp', 'id' => $gp->id, 'score' => 0.9, 'reason' => 'stub']],
            ], 0.9));
    });

    app(ReplacementSuggestionService::class)->forGp($this->rootTeam, $source);
});
