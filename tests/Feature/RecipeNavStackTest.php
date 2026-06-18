<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * C: Sub-Rezept-Navigation im Rezept-Modal. Aus Rezept A heraus ein referenziertes
 * Rezept B öffnen → A landet auf dem Nav-Stack; ✕ (schliessenOderZurueck) springt
 * zurück zu A statt komplett zu schließen. Erst ohne Stack schließt ✕ hart.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->mkRezept = fn (string $name) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'nav-' . uniqid(), 'name' => $name, 'status' => 'draft',
    ]);
});

it('✕ springt vom Sub-Rezept zurück zum Eltern, danach schließt es hart', function () {
    $a = ($this->mkRezept)('Basis: A');
    $b = ($this->mkRezept)('Basis: B');

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $a->id)
        ->assertSet('recipeId', $a->id)
        ->assertSet('navStack', [])
        ->call('oeffnen', $b->id)                       // Sub-Navigation aus A → B
        ->assertSet('recipeId', $b->id)
        ->assertSet('navStack', [$a->id])
        ->call('schliessenOderZurueck')                  // ✕ → zurück zu A (Modal bleibt offen)
        ->assertSet('recipeId', $a->id)
        ->assertSet('navStack', [])
        ->call('schliessenOderZurueck')                  // Stack leer → hart schließen
        ->assertDispatched('modal.close');
});

it('frisches Öffnen pusht nicht, modal.closed leert den Stack', function () {
    $a = ($this->mkRezept)('Basis: A');
    $b = ($this->mkRezept)('Basis: B');

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $a->id)
        ->call('oeffnen', $b->id)
        ->assertSet('navStack', [$a->id])
        ->call('beiModalClosed', 'recipe-modal')         // hartes Schließen (Backdrop/Escape)
        ->assertSet('navStack', [])
        ->assertSet('istOffen', false)
        ->call('oeffnen', $a->id)                        // frisch (istOffen war false) → KEIN Push
        ->assertSet('navStack', []);
});
