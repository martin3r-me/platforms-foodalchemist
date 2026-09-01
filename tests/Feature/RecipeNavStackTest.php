<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->mkRezept = fn (string $name) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'nav-'.uniqid(), 'name' => $name, 'status' => 'draft',
    ]);
});
it('ersetzt beim nächsten Öffnen das vorherige Rezept wie der Gerichte-Editor', function () {
    $a = ($this->mkRezept)('Basis: A');
    $b = ($this->mkRezept)('Basis: B');

    Livewire::test(RecipeModal::class)
        ->dispatch('recipe-modal.oeffnen', id: $a->id)
        ->assertSet('recipeId', $a->id)
        ->dispatch('recipe-modal.oeffnen', id: $b->id)
        ->assertSet('recipeId', $b->id)
        ->assertSet('form.name', 'Basis: B');
});

it('verwendet keinen serverseitigen Close-Sonderweg mehr', function () {
    $modulRoot = dirname((new ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2);
    $blade = file_get_contents($modulRoot.'/resources/views/livewire/recipes/recipe-modal.blade.php');

    expect($blade)->not->toContain('close-via')
        ->and(method_exists(RecipeModal::class, 'schliessenOderZurueck'))->toBeFalse()
        ->and(method_exists(RecipeModal::class, 'beiModalClosed'))->toBeFalse();
});
