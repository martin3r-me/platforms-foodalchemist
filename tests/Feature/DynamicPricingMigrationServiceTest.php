<?php

use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\CatalogPricingService;
use Platform\FoodAlchemist\Services\DynamicPricingMigrationService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

it('migriert aktive Preise idempotent und lässt historische Angebote unverändert', function () {
    $this->seedTeamHierarchy();
    app(TeamSettingsService::class)->update($this->rootTeam, ['target_food_cost_pct' => 30]);
    $class = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id, 'code' => 'ALC', 'label' => 'Altklasse',
        'raw_markup_pct' => 420, 'vat_rate' => 19,
    ]);
    $recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'migration-v2', 'name' => 'Migration',
        'status' => 'approved', 'is_sales_recipe' => true, 'ek_per_kg_eur' => 100,
        'yield_kg' => 2, 'sales_unit_count' => 10,
    ]);
    $form = FoodAlchemistServierform::create([
        'team_id' => $this->rootTeam->id, 'code' => 'unbestimmt', 'label' => 'Unbestimmt',
    ]);
    $presentation = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id,
        'serving_form_id' => $form->id, 'is_standard' => true,
        'quantity_per_unit_g' => 200, 'unit_count' => 10, 'ek_portion' => 200,
        'markup_class_id' => $class->id, 'price_mode' => 'manuell', 'sales_net' => 99,
        'created_via' => 'one_shot',
    ]);
    $withoutPresentation = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'migration-v2-ohne-form', 'name' => 'Ohne Form',
        'status' => 'approved', 'is_sales_recipe' => true, 'ek_per_kg_eur' => 20,
        'yield_kg' => 1, 'sales_unit_count' => 5, 'markup_class_id' => $class->id,
    ]);
    $open = FoodAlchemistAngebot::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Offen', 'status' => 'angebot',
        'personen' => 100, 'price_mode' => 'manuell', 'total_price' => 999,
    ]);
    $historic = FoodAlchemistAngebot::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Historisch', 'status' => 'versendet',
        'personen' => 100, 'price_mode' => 'manuell', 'total_price' => 888,
    ]);

    $service = app(DynamicPricingMigrationService::class);
    $first = $service->migrate($this->rootTeam->id, 10);
    $snapshot = [
        'class_factor' => (float) $class->fresh()->class_factor_pct,
        'presentation' => (float) $presentation->fresh()->sales_net,
        'open' => (float) $open->fresh()->total_price,
        'historic' => (float) $historic->fresh()->total_price,
    ];
    $second = $service->migrate($this->rootTeam->id, 10);

    expect($first['classes'])->toBe(1)
        ->and($first['presentations'])->toBe(2)
        ->and($presentation->fresh()->price_mode)->toBe('auto')
        ->and((float) $presentation->fresh()->unit_count)->toBe(1.0)
        ->and($withoutPresentation->fresh()->standardPresentation()->exists())->toBeTrue()
        ->and($presentation->fresh()->price_calculation_version)->toBe(CatalogPricingService::VERSION)
        ->and($open->fresh()->price_mode)->toBe('auto')
        ->and((float) $historic->fresh()->total_price)->toBe(888.0)
        ->and($historic->fresh()->price_mode)->toBe('manuell')
        ->and((float) $class->fresh()->class_factor_pct)->toBe($snapshot['class_factor'])
        ->and((float) $presentation->fresh()->sales_net)->toBe($snapshot['presentation'])
        ->and((float) $open->fresh()->total_price)->toBe($snapshot['open'])
        ->and((float) $historic->fresh()->total_price)->toBe($snapshot['historic'])
        ->and($second['classes'])->toBe(0)
        ->and($second['presentations'])->toBe(0);
});
