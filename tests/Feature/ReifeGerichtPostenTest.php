<?php

use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Reife\RecipeReifeAdapter;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 83 B2 — Gerichte ohne Posten bekommen in der Reife-Ampel die Lücke `default_station_id` (vorher nur am Basisrezept).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->posten = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'saucier', 'name' => 'Saucier']);
    $this->gericht = fn (?int $posten) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g'.uniqid(), 'name' => 'DES: Gericht', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 3.0, 'sales_unit_count' => 1, 'default_station_id' => $posten,
    ]);
    $this->luecken = fn ($recipe) => collect(app(RecipeReifeAdapter::class)->messe($this->rootTeam, $recipe->id)['luecken'])->where('code', 'default_station_id');
});

it('★ ein Gericht ohne Posten meldet die Lücke default_station_id (Ebene Gericht)', function () {
    $luecke = ($this->luecken)(($this->gericht)(null));

    expect($luecke)->toHaveCount(1)->and($luecke->first()['ebene'])->toBe('gericht');
});

it('ein Gericht mit Posten meldet sie nicht', function () {
    expect(($this->luecken)(($this->gericht)($this->posten->id)))->toHaveCount(0);
});

it('das Basisrezept ohne Posten meldet sie weiter (Ebene Basisrezept)', function () {
    $basis = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'b1', 'name' => 'Fond', 'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0]);

    expect(($this->luecken)($basis)->first()['ebene'])->toBe('basisrezept');
});

it('der Soll-Katalog führt default_station_id für beide Ebenen', function () {
    $ebenen = collect(app(RecipeReifeAdapter::class)->sollAspekte())->where('code', 'default_station_id')->pluck('ebene')->sort()->values()->all();

    expect($ebenen)->toBe(['basisrezept', 'gericht']);
});
