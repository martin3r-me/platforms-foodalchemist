<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R18: Drei-Spalten-Browser — browseKatalog liefert beide Listen serverseitig
 * gefiltert (stapelbare Filter + zentrales q auf BEIDE), inkl. Auto-Fill-Einheit
 * und Niveau-Slugs für die Farbpunkte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'wrap', 'name' => 'HG: Wrap', 'status' => 'draft',
    ]);
    $this->gpTomate = $this->makeGp($this->rootTeam, 'Tomatenmark');
    $this->gpTomate->update(['commodity_group_code' => '10', 'sub_category' => '10.1 Pasten', 'condition' => 'konserviert']);
    $this->gpBier = $this->makeGp($this->rootTeam, 'Veltins Bier: fluessig');
    $this->gpBier->update(['commodity_group_code' => '15', 'condition' => 'frisch']);

    $this->sub = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond_tomate', 'name' => 'Fond: Tomate', 'status' => 'draft',
    ]);
    app(\Platform\FoodAlchemist\Services\RecipeService::class)
        ->setzeEignung($this->rootTeam, $this->sub->id, 'level', 'gehoben');
});

it('filtert GPs nach Warengruppe/Zustand und liefert die Auto-Fill-Einheit (fluessig → ml)', function () {
    $komponente = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->instance();

    $alle = $komponente->browseKatalog();
    expect($alle['gps']['total'])->toBe(2)
        ->and(collect($alle['gps']['items'])->firstWhere('id', $this->gpBier->id)['einheit_slug'])->toBe('ml')
        ->and(collect($alle['gps']['items'])->firstWhere('id', $this->gpTomate->id)['einheit_slug'])->toBe('g');

    $nurWg10 = $komponente->browseKatalog(['wg' => '10']);
    expect($nurWg10['gps']['total'])->toBe(1)
        ->and($nurWg10['gps']['items'][0]['name'])->toBe('Tomatenmark');

    $zustand = $komponente->browseKatalog(['condition' => 'frisch']);
    expect(collect($zustand['gps']['items'])->pluck('id'))->toContain($this->gpBier->id)->not->toContain($this->gpTomate->id);
});

it('filtert Rezepte nach Niveau, schließt das eigene Rezept aus und trägt Niveau-Slugs', function () {
    $komponente = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->instance();

    $alle = $komponente->browseKatalog();
    expect(collect($alle['rezepte']['items'])->pluck('id'))->toContain($this->sub->id)->not->toContain($this->rezept->id)
        ->and(collect($alle['rezepte']['items'])->firstWhere('id', $this->sub->id)['niveaus'])->toBe(['gehoben']);

    $gehoben = $komponente->browseKatalog([], ['level' => 'gehoben']);
    expect($gehoben['rezepte']['total'])->toBe(1);
    $haute = $komponente->browseKatalog([], ['level' => 'haute_cuisine']);
    expect($haute['rezepte']['total'])->toBe(0);
});

it('q wirkt als Textfilter auf BEIDE Listen gleichzeitig', function () {
    $komponente = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->instance();

    $r = $komponente->browseKatalog([], [], 'tomate');
    expect($r['gps']['total'])->toBe(1)
        ->and($r['gps']['items'][0]['name'])->toBe('Tomatenmark')
        ->and($r['rezepte']['total'])->toBe(1)
        ->and($r['rezepte']['items'][0]['name'])->toBe('↳ Fond: Tomate');
});
