<?php

use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — die Concepter-/Concepts-Übersichtsliste zeigt den €/Gast betriebsscharf: Slots
 * einmal eager-geladen, dann je Zeile preisCockpit(outlet). Ohne Brille leer (Liste zeigt Cache).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    app(TeamSettingsService::class)->update($this->childA, ['target_food_cost_pct' => 25]);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Betrieb Nord']);
    app(OutletSettingsService::class)->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 20]);   // Basissatz 5,0

    $recipe = $this->makeRecipe($this->childA, 'HG Zander', ['is_sales_recipe' => true, 'sales_net' => 99.0, 'ek_total_eur' => 10.0]);
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $recipe->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10, 'sales_net' => 99.0,
    ]);
    $this->concept = $this->makeConcept($this->childA, 'Edition A');
    $this->makeConceptSlot($this->concept, ['sales_recipe_id' => $recipe->id]);
});

it('outletPreisMap: €/Gast je Zeile betriebsscharf; ohne Brille leer', function () {
    $svc = app(ConceptService::class);
    $concepts = collect([$this->concept]);

    expect($svc->outletPreisMap($this->childA, $concepts, null))->toBe([])
        ->and($svc->outletPreisMap($this->childA, $concepts, $this->betrieb)[$this->concept->id])->toBe(50.0);
});
