<?php

use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionTimeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->times = app(ProductionTimeService::class);
});

it('kombiniert Rüst-, Batch- und variable Personenminuten', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['default_batch_max_kg' => 20]);
    $recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'hybrid', 'name' => 'Hybrid',
        'status' => 'approved', 'is_sales_recipe' => false,
        'setup_time_min' => 15, 'work_time_min' => 20,
        'variable_work_time_min' => 2, 'variable_work_time_basis' => 'kg',
        'standzeit_min' => 90, 'batch_max_kg' => 10,
    ]);

    $result = $this->times->calculate($this->rootTeam, $recipe, 25, 'kg');

    expect($result['operations'])->toBe(3)
        ->and($result['batch_limit'])->toBe(10.0)
        ->and($result['batch_limit_source'])->toBe('recipe')
        ->and($result['setup_minutes'])->toBe(15.0)
        ->and($result['batch_minutes'])->toBe(60.0)
        ->and($result['variable_minutes'])->toBe(50.0)
        ->and($result['active_person_minutes'])->toBe(125.0)
        ->and($result['passive_minutes'])->toBe(90.0)
        ->and($result['elapsed_minutes'])->toBe(215.0);
});

it('verwendet die kleinste physische Grenze aus Rezept, Posten und Team', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['default_batch_max_kg' => 30]);
    $station = FoodAlchemistProductionStation::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'saucier', 'name' => 'Saucier', 'batch_max_kg' => 8,
    ]);
    $recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'cap', 'name' => 'Grenzen',
        'status' => 'approved', 'is_sales_recipe' => false, 'work_time_min' => 10, 'batch_max_kg' => 12,
    ]);

    $result = $this->times->calculate($this->rootTeam, $recipe, 17, 'kg', $station);

    expect($result['batch_limit'])->toBe(8.0)
        ->and($result['batch_limit_source'])->toBe('station')
        ->and($result['operations'])->toBe(3)
        ->and($result['active_person_minutes'])->toBe(30.0);
});

it('rechnet passive Standzeit weder als Arbeit noch als Personalkapazität', function () {
    $recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'stand', 'name' => 'Standzeit',
        'status' => 'approved', 'is_sales_recipe' => false, 'standzeit_min' => 240,
    ]);

    $result = $this->times->calculate($this->rootTeam, $recipe, 5, 'kg');

    expect($result['active_person_minutes'])->toBe(0.0)
        ->and($result['passive_minutes'])->toBe(240.0)
        ->and($result['elapsed_minutes'])->toBe(240.0);
});

it('meldet eine nicht umrechenbare variable Bezugsart sichtbar', function () {
    $recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'basis', 'name' => 'Bezugsart',
        'status' => 'approved', 'is_sales_recipe' => false,
        'variable_work_time_min' => 1, 'variable_work_time_basis' => 'piece',
    ]);

    $result = $this->times->calculate($this->rootTeam, $recipe, 5, 'kg');

    expect($result['variable_minutes'])->toBe(0.0)
        ->and($result['warnings'])->toHaveCount(1);
});
