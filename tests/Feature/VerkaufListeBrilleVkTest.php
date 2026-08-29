<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\Browser;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\ActiveOutletContext;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — die Gerichte-ÜBERSICHTSLISTE zeigt den VK betriebsscharf: pro Zeile on-the-fly
 * gegen den Betrieb (Basissatz einmal, dann je Zeile salesNetFor). Ohne Brille die Baseline.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);

    app(TeamSettingsService::class)->update($this->childA, ['target_food_cost_pct' => 25]);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Betrieb Nord']);
    app(OutletSettingsService::class)->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 20]);   // Basissatz 5,0

    $this->recipe = $this->makeRecipe($this->childA, 'HG Zander', ['is_sales_recipe' => true, 'status' => 'approved', 'sales_net' => 99.0, 'ek_total_eur' => 10.0]);
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $this->recipe->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10, 'sales_net' => 99.0,
    ]);
});

it('outletVkMap: betriebsscharfer VK je Zeile; ohne Brille leer', function () {
    $svc = app(SalesRecipeService::class);
    $recipes = collect([$this->recipe]);

    expect($svc->outletVkMap($this->childA, $recipes, null))->toBe([])
        ->and($svc->outletVkMap($this->childA, $recipes, $this->betrieb)[$this->recipe->id])->toBe(50.0);
});

it('Verkauf-Browser: vkDisplay trägt den Betriebs-VK bei aktiver Brille', function () {
    app(ActiveOutletContext::class)->set($this->childA, $this->betrieb->id);

    $view = Livewire::test(Browser::class);
    expect($view->viewData('aktiverBetrieb'))->toBe('Betrieb Nord')
        ->and($view->viewData('vkDisplay')[$this->recipe->id] ?? null)->toBe(50.0);
});
