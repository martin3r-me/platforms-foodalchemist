<?php

use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\CatalogPricingService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->pricing = app(CatalogPricingService::class);
    $this->settings = app(TeamSettingsService::class);
    $this->form = FoodAlchemistServierform::create([
        'team_id' => $this->rootTeam->id, 'code' => 'portion', 'label' => 'Portion',
    ]);
    $this->recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'catalog-v2', 'name' => 'Kataloggericht',
        'status' => 'approved', 'is_sales_recipe' => true, 'ek_per_kg_eur' => 100,
    ]);
});

it('leitet den Basissatz aus Monatsbasen, Gemeinkosten und Gewinnaufschlag ab', function () {
    $this->settings->update($this->rootTeam, [
        'calculation_reference_bases' => ['mek' => 10000, 'fek' => 5000, 'hk' => 18000],
        'margin_pct' => 10,
        'calculation_schema' => [
            ['key' => 'mgk', 'label' => 'MGK', 'type' => 'pct_mek', 'value' => 10, 'active' => true, 'sort' => 10, 'mode' => 'manuell'],
            ['key' => 'fgk', 'label' => 'FGK', 'type' => 'pct_fek', 'value' => 20, 'active' => true, 'sort' => 20, 'mode' => 'manuell'],
            ['key' => 'vv', 'label' => 'VwVt', 'type' => 'pct_hk', 'value' => 5, 'active' => true, 'sort' => 30, 'mode' => 'manuell'],
        ],
    ]);

    $base = $this->pricing->enterpriseBaseRate($this->rootTeam);

    expect($base['source'])->toBe('kostenstruktur')
        ->and($base['factor'])->toBe(2.31)
        ->and($base['complete'])->toBeTrue();
});

it('berechnet Auto-VK aus MEK, dynamischem Basissatz und relativem Klassenfaktor', function () {
    $this->settings->update($this->rootTeam, [
        'target_food_cost_pct' => 25,
        'vat_defaults' => ['regulaer' => 19, 'ermaessigt' => 7, 'default_satz' => 'regulaer'],
        'rundungsregeln' => ['nachkommastellen' => 2, 'mode' => 'kaufmaennisch'],
    ]);
    $class = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id, 'code' => 'PREMIUM', 'label' => 'Premium',
        'class_factor_pct' => 120, 'vat_profile_key' => 'ermaessigt',
    ]);
    $presentation = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id,
        'serving_form_id' => $this->form->id, 'is_standard' => true,
        'ek_portion' => 2, 'markup_class_id' => $class->id, 'price_mode' => 'auto',
    ]);

    $price = $this->pricing->catalogPrice($this->rootTeam, $presentation);

    expect($price['base_source'])->toBe('ziel_we_fallback')
        ->and($price['base_factor'])->toBe(4.0)
        ->and($price['calculated_sales_net'])->toBe(9.6)
        ->and($price['sales_net'])->toBe(9.6)
        ->and($price['vat_rate'])->toBe(7.0)
        ->and($price['sales_gross'])->toBe(10.27);
});

it('verwendet die Team-Standard-Preisklasse bei leerer Zuordnung', function () {
    $class = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id, 'code' => 'STD', 'label' => 'Standard',
        'class_factor_pct' => 125, 'vat_profile_key' => 'ermaessigt',
    ]);
    $this->settings->update($this->rootTeam, [
        'target_food_cost_pct' => 25,
        'default_markup_class_id' => $class->id,
    ]);
    $presentation = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id,
        'serving_form_id' => $this->form->id, 'is_standard' => true,
        'ek_portion' => 2, 'price_mode' => 'auto',
    ]);

    $price = $this->pricing->catalogPrice($this->rootTeam, $presentation);

    expect($price['class_id'])->toBe($class->id)
        ->and($price['class_source'])->toBe('team_standard')
        ->and($price['class_factor_pct'])->toBe(125.0)
        ->and($price['calculated_sales_net'])->toBe(10.0);
});

it('belegt neue Darreichungen mit der Team-Standard-Preisklasse vor', function () {
    $class = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id, 'code' => 'STD', 'label' => 'Standard',
        'class_factor_pct' => 100,
    ]);
    $this->settings->update($this->rootTeam, [
        'target_food_cost_pct' => 25,
        'default_markup_class_id' => $class->id,
    ]);

    $presentation = app(DarreichungService::class)->anlegen(
        $this->rootTeam,
        $this->recipe->id,
        $this->form->id,
    );

    expect($presentation->markup_class_id)->toBe($class->id);
});

it('aktualisiert im Fixmodus nur den Vergleichspreis und verlangt eine Begründung', function () {
    $this->settings->update($this->rootTeam, ['target_food_cost_pct' => 25]);
    $presentation = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id,
        'serving_form_id' => $this->form->id, 'is_standard' => true,
        'quantity_per_unit_g' => 20, 'ek_portion' => 2, 'price_mode' => 'auto',
    ]);
    $service = app(DarreichungService::class);

    expect(fn () => $service->aktualisieren($this->rootTeam, $presentation->id, [
        'price_mode' => 'fixed', 'sales_net' => 12,
    ]))->toThrow(RuntimeException::class);

    $service->aktualisieren($this->rootTeam, $presentation->id, [
        'price_mode' => 'fixed', 'sales_net' => 12, 'price_override_reason' => 'Freigegebener Marktpreis',
    ]);
    $presentation->refresh();

    expect((float) $presentation->calculated_sales_net)->toBe(8.0)
        ->and((float) $presentation->sales_net)->toBe(12.0)
        ->and($presentation->price_mode)->toBe('fixed')
        ->and($presentation->price_override_reason)->toBe('Freigegebener Marktpreis');
});

it('akzeptiert deutsche Dezimalkommas beim Fixpreis einer Darreichung', function () {
    $presentation = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id,
        'serving_form_id' => $this->form->id, 'is_standard' => true,
        'quantity_per_unit_g' => 20, 'ek_portion' => 2, 'price_mode' => 'auto',
    ]);

    app(DarreichungService::class)->aktualisieren($this->rootTeam, $presentation->id, [
        'price_mode' => 'fixed',
        'sales_net' => '1,50',
        'price_override_reason' => 'Freigegebener Marktpreis',
    ]);

    expect((float) $presentation->fresh()->sales_net)->toBe(1.5)
        ->and((float) $this->recipe->fresh()->sales_net)->toBe(1.5);
});

it('berechnet die Standard-Darreichung bei leerer Grammatur aus dem Rezept-Yield', function () {
    $this->settings->update($this->rootTeam, ['target_food_cost_pct' => 25]);
    $this->recipe->update([
        'yield_kg' => 0.020,
        'sales_unit_count' => null,
        'sales_quantity_per_unit_g' => null,
    ]);
    $presentation = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id,
        'serving_form_id' => $this->form->id, 'is_standard' => true,
        'quantity_per_unit_g' => null, 'unit_count' => 1, 'price_mode' => 'auto',
    ]);

    app(DarreichungService::class)->recomputePreise($presentation);
    $presentation->refresh();

    expect($presentation->quantity_per_unit_g)->toBeNull()
        ->and((float) $presentation->ek_portion)->toBe(2.0)
        ->and((float) $presentation->calculated_sales_net)->toBe(8.0)
        ->and((float) $presentation->sales_net)->toBe(8.0)
        ->and($presentation->price_mode)->toBe('auto');
});

it('erzeugt ohne MEK keinen stillen Nullpreis', function () {
    $presentation = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id,
        'serving_form_id' => $this->form->id, 'is_standard' => true, 'price_mode' => 'auto',
    ]);

    $price = $this->pricing->catalogPrice($this->rootTeam, $presentation);

    expect($price['calculated_sales_net'])->toBeNull()
        ->and($price['sales_net'])->toBeNull()
        ->and($price['complete'])->toBeFalse();
});

it('rundet Auto-Preise auf den nächsten 0,50-Preis', function (float $unrounded, float $expected) {
    $this->settings->update($this->rootTeam, [
        'target_food_cost_pct' => 100,
        'rundungsregeln' => ['nachkommastellen' => 2, 'mode' => 'next_050'],
    ]);
    $presentation = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id,
        'serving_form_id' => $this->form->id, 'is_standard' => true,
        'ek_portion' => $unrounded, 'price_mode' => 'auto',
    ]);

    $price = $this->pricing->catalogPrice($this->rootTeam, $presentation);

    expect($price['calculated_sales_net'])->toBe($expected)
        ->and($price['rounding']['mode'])->toBe('next_050');
})->with([
    'zwischen zwei Schritten' => [8.16, 8.50],
    'exakter Treffer' => [8.50, 8.50],
    'oberhalb eines Treffers' => [8.51, 9.00],
]);

it('rundet Auto-Preise auf die nächste 90-Cent-Endung', function (float $unrounded, float $expected) {
    $this->settings->update($this->rootTeam, [
        'target_food_cost_pct' => 100,
        'rundungsregeln' => ['nachkommastellen' => 2, 'mode' => 'next_090'],
    ]);
    $presentation = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id,
        'serving_form_id' => $this->form->id, 'is_standard' => true,
        'ek_portion' => $unrounded, 'price_mode' => 'auto',
    ]);

    $price = $this->pricing->catalogPrice($this->rootTeam, $presentation);

    expect($price['calculated_sales_net'])->toBe($expected)
        ->and($price['rounding']['mode'])->toBe('next_090');
})->with([
    'unterhalb der Endung' => [8.16, 8.90],
    'exakter Treffer' => [8.90, 8.90],
    'oberhalb der Endung' => [8.93, 9.90],
]);
