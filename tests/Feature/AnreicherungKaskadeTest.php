<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #4 (Bug-Runde 2026-08), Teil 2 „Kaskade": „Alles anreichern" reichert das Top-Rezept synchron an
 * und schiebt seine Sub-Basisrezepte als Hintergrund-Jobs nach (async, sonst Timeout). Getestet:
 * der cycle-safe Sub-Rezept-Sammler + dass die Editor-Aktion die Jobs (refresh=true) dispatcht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $this->link = function ($parent, $child, $pos = 1) {
        return FoodAlchemistRecipeIngredient::create([
            'team_id' => $parent->team_id,
            'recipe_id' => $parent->id,
            'referenced_recipe_id' => $child->id,
            'raw_text' => 'Sub: ' . $child->name,
            'quantity' => '100',
            'unit_vocab_id' => $this->unitG($this->rootTeam)->id,
            'position' => $pos,
        ]);
    };
    $this->svc = app(RecipeOneShotService::class);
});

it('#4: subRezeptIds sammelt transitiv (Kette A→B→C)', function () {
    $a = $this->makeRecipe($this->rootTeam, 'A', ['status' => 'draft']);
    $b = $this->makeRecipe($this->rootTeam, 'B', ['status' => 'draft']);
    $c = $this->makeRecipe($this->rootTeam, 'C', ['status' => 'draft']);
    ($this->link)($a, $b);
    ($this->link)($b, $c);

    expect($this->svc->subRezeptIds($a->id))->toEqualCanonicalizing([$b->id, $c->id]);
});

it('#4: subRezeptIds ist cycle-safe (A→B→A endet, kein Endlos-Loop)', function () {
    $a = $this->makeRecipe($this->rootTeam, 'A', ['status' => 'draft']);
    $b = $this->makeRecipe($this->rootTeam, 'B', ['status' => 'draft']);
    ($this->link)($a, $b);
    ($this->link)($b, $a);   // Zyklus

    expect($this->svc->subRezeptIds($a->id))->toEqualCanonicalizing([$b->id]);
});

it('#4: subRezeptIds dedupt den Diamant (A→B, A→C, B→D, C→D)', function () {
    $a = $this->makeRecipe($this->rootTeam, 'A', ['status' => 'draft']);
    $b = $this->makeRecipe($this->rootTeam, 'B', ['status' => 'draft']);
    $c = $this->makeRecipe($this->rootTeam, 'C', ['status' => 'draft']);
    $d = $this->makeRecipe($this->rootTeam, 'D', ['status' => 'draft']);
    ($this->link)($a, $b, 1);
    ($this->link)($a, $c, 2);
    ($this->link)($b, $d);
    ($this->link)($c, $d);

    expect($this->svc->subRezeptIds($a->id))->toEqualCanonicalizing([$b->id, $c->id, $d->id]);
});

it('#4: subRezeptIds respektiert das Tiefenlimit', function () {
    $a = $this->makeRecipe($this->rootTeam, 'A', ['status' => 'draft']);
    $b = $this->makeRecipe($this->rootTeam, 'B', ['status' => 'draft']);
    $c = $this->makeRecipe($this->rootTeam, 'C', ['status' => 'draft']);
    $d = $this->makeRecipe($this->rootTeam, 'D', ['status' => 'draft']);
    ($this->link)($a, $b);
    ($this->link)($b, $c);
    ($this->link)($c, $d);

    expect($this->svc->subRezeptIds($a->id, maxTiefe: 2))->toEqualCanonicalizing([$b->id, $c->id]);
});

it('#4: allesAnreichern dispatcht je Sub-Basisrezept einen EnrichRecipeJob mit refresh=true', function () {
    Queue::fake();
    // RecipeModal ist der Basisrezept-Editor: Wurzel = Basisrezept mit einem Sub-Basisrezept.
    $parent = $this->makeRecipe($this->rootTeam, 'Parent-Basis', ['status' => 'draft', 'is_sales_recipe' => false]);
    $komp = $this->makeRecipe($this->rootTeam, 'Komponente', ['status' => 'draft', 'is_sales_recipe' => false]);
    ($this->link)($parent, $komp);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $parent->id)
        ->call('allesAnreichern')
        ->assertSet('fehler', null);

    Queue::assertPushed(EnrichRecipeJob::class, 1);
    Queue::assertPushed(EnrichRecipeJob::class, fn ($job) => $job->recipeId === $komp->id && $job->refresh === true);
});
