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

it('zaehlt Ruestzeit am Basisrezept, ignoriert sie am Gericht', function () {
    // Regelwerk Verkaufsgerichte §3.4a: Rüstzeit ist eine Herstellungs-Größe. Die Grenze
    // muss BEIDSEITIG geprüft werden — ein Guard, der versehentlich global greift, würde
    // die Produktionsplanung aller Basisrezepte um ihre Rüstzeit verkürzen, ohne dass es
    // irgendwo auffällt.
    $felder = [
        'team_id' => $this->rootTeam->id, 'status' => 'approved',
        'setup_time_min' => 25, 'work_time_min' => 40,
    ];
    $basis = FoodAlchemistRecipe::create($felder + [
        'recipe_key' => 'ruest-basis', 'name' => 'Fond: Ansatz', 'is_sales_recipe' => false,
    ]);
    $gericht = FoodAlchemistRecipe::create($felder + [
        'recipe_key' => 'ruest-gericht', 'name' => 'Teller mit Fond', 'is_sales_recipe' => true,
    ]);

    $mitRuest = $this->times->calculateForBatches($this->rootTeam, $basis, 2);
    $ohneRuest = $this->times->calculateForBatches($this->rootTeam, $gericht, 2);

    expect($mitRuest['active_person_minutes'])->toBe(105.0)      // 25 Rüst + 2 × 40
        ->and($ohneRuest['active_person_minutes'])->toBe(80.0);  // nur 2 × 40
});

it('rechnet variable Portionszeit aus Ansätzen und Portionen je Ansatz um', function () {
    $recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'portion-time', 'name' => 'Portionszeit',
        'status' => 'approved', 'is_sales_recipe' => true,
        'yield_kg' => 2, 'sales_unit_count' => 20,
        'variable_work_time_min' => 0.5, 'variable_work_time_basis' => 'portion',
    ]);

    $result = $this->times->calculateForBatches($this->rootTeam, $recipe, 3);

    expect($result['variable_quantity'])->toBe(60.0)
        ->and($result['variable_quantity_basis'])->toBe('portion')
        ->and($result['variable_minutes'])->toBe(30.0)
        ->and($result['warnings'])->toBeEmpty();
});
