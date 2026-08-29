<?php

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
 * Ebene 2 — Slice C: ambienter „aktiver Betrieb" (Session-Kontext) + die VK-Cockpit-Fläche
 * löst gegen ihn auf. Beweis, dass der VK-Vorschlag dem gewählten Betrieb folgt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->ctx = app(ActiveOutletContext::class);
    $this->settings = app(TeamSettingsService::class);
    $this->outletSettings = app(OutletSettingsService::class);
    $this->sales = app(SalesRecipeService::class);
});

it('ActiveOutletContext: set/current/forget + strikt team-scoped', function () {
    $betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Süd']);

    expect($this->ctx->current($this->childA))->toBeNull();               // Default = Team-Baseline
    $this->ctx->set($this->childA, $betrieb->id);
    expect($this->ctx->current($this->childA)?->id)->toBe($betrieb->id);

    // Session-Key je Team → anderes Team sieht ihn nicht.
    expect($this->ctx->current($this->childB))->toBeNull();

    // Fremdes Outlet wird nicht gesetzt (team-scoped Re-Autorisierung).
    expect($this->ctx->set($this->childB, $betrieb->id))->toBeNull()
        ->and($this->ctx->current($this->childB))->toBeNull();

    // Zurück auf Baseline.
    $this->ctx->set($this->childA, null);
    expect($this->ctx->current($this->childA))->toBeNull();
});

it('cockpit: der VK-Vorschlag folgt dem Betrieb (on-the-fly)', function () {
    $this->settings->update($this->childA, ['target_food_cost_pct' => 25]);   // Basissatz 4,0
    $betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Kantine']);
    $this->outletSettings->update($this->childA, $betrieb, ['target_food_cost_pct' => 20]);  // Basissatz 5,0

    $gericht = $this->makeRecipe($this->childA, 'Gericht');
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10,
    ]);

    // MEK 10 × Basissatz: ohne Betrieb 4,0 ⇒ 40,00; mit Betrieb 5,0 ⇒ 50,00.
    expect($this->sales->cockpit($gericht, $this->childA)['vk']['sales_net'])->toBe(40.0)
        ->and($this->sales->cockpit($gericht, $this->childA, $betrieb)['vk']['sales_net'])->toBe(50.0);
});
