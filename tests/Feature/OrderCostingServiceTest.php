<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\OrderCostingService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    app(TeamSettingsService::class)->update($this->rootTeam, [
        'target_food_cost_pct' => 30,
        'stundensatz_eur' => 60,
        'margin_pct' => 15,
        'default_batch_max_kg' => 20,
    ]);

    $g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm',
        'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $portion = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'portion', 'display_de' => 'Portion',
        'dimension' => 'count',
    ]);
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Testlieferant']);
    $gp = $this->makeGp($this->rootTeam, 'Kartoffel');
    $item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Kartoffel 1 kg', 'article_number' => 'K-1', 'qty' => 1, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $gp->id,
    ]);
    FoodAlchemistPrice::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'price' => 2, 'status' => '0',
    ]);
    $gp->update(['lead_la_supplier_item_id' => $item->id]);

    $this->recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'order-cost-v2', 'name' => 'Kartoffelgericht',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_unit_count' => 10,
        'setup_time_min' => 30, 'work_time_min' => 60,
        'variable_work_time_min' => 1, 'variable_work_time_basis' => 'kg',
    ]);
    $this->recipe->ingredients()->create([
        'team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $gp->id,
        'raw_text' => 'Kartoffel', 'quantity' => 1000, 'unit_vocab_id' => $g->id,
    ]);
    app(RecipeRecomputeService::class)->recomputePipeline($this->recipe->id);

    $form = FoodAlchemistServierform::create([
        'team_id' => $this->rootTeam->id, 'code' => 'portion', 'label' => 'Portion',
    ]);
    $presentation = app(DarreichungService::class)->anlegen($this->rootTeam, $this->recipe->id, $form->id, [
        'quantity_per_unit_g' => 100, 'unit_count' => 1, 'price_mode' => 'auto',
    ]);
    app(DarreichungService::class)->recomputePreise($presentation);

    $concepts = app(ConceptService::class);
    $this->concept = $concepts->create($this->rootTeam, ['name' => 'Menü']);
    $slot = $concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Hauptgang']);
    $slot = $concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->recipe->id]);
    $slot->update(['quantity' => 1, 'unit_vocab_id' => $portion->id]);
    $concepts->recomputeCache($this->concept->refresh());
});

it('hält den Katalogpreis pax-unabhängig und skaliert den realen Auftrags-HK2', function () {
    $service = app(OrderCostingService::class);

    $small = $service->costConcept($this->rootTeam, $this->concept->refresh(), 100);
    $large = $service->costConcept($this->rootTeam, $this->concept->refresh(), 5000);

    expect($small['catalog_price_per_person'])->toBe($large['catalog_price_per_person'])
        ->and($small['catalog_price_total'])->toBe(round($small['catalog_price_per_person'] * 100, 2))
        ->and($large['catalog_price_total'])->toBe(round($large['catalog_price_per_person'] * 5000, 2))
        ->and($large['active_person_minutes'])->toBeGreaterThan($small['active_person_minutes'])
        ->and($large['hk2'])->toBeGreaterThan($small['hk2'])
        ->and($small['target_price'])->toBeGreaterThanOrEqual($small['minimum_price'])
        ->and($large['target_price'])->toBeGreaterThanOrEqual($large['minimum_price']);
});

it('weist Rüstzeit je Auftrag nur einmal und Batchzeit nach physischer Grenze aus', function () {
    $service = app(OrderCostingService::class);

    $small = $service->costConcept($this->rootTeam, $this->concept->refresh(), 100);
    $large = $service->costConcept($this->rootTeam, $this->concept->refresh(), 5000);

    // 100 Pax = 10 kg: 30 Rüst + 1 × 60 Vorgang + 10 variabel.
    expect($small['active_person_minutes'])->toBe(100.0)
        // 5.000 Pax = 500 kg: 30 Rüst + 25 × 60 Vorgang + 500 variabel.
        ->and($large['active_person_minutes'])->toBe(2030.0);
});

it('weist den Fertigungslohn aus und markiert fehlende Produktionszeiten als unvollständig', function () {
    $service = app(OrderCostingService::class);

    $result = $service->costConcept($this->rootTeam, $this->concept->refresh(), 100);
    expect($result['fek'])->toBeGreaterThan(0)
        ->and($result['complete'])->toBeTrue();

    $this->recipe->update([
        'setup_time_min' => 0,
        'work_time_min' => 0,
        'variable_work_time_min' => 0,
    ]);
    $withoutTime = $service->costConcept($this->rootTeam, $this->concept->refresh(), 100);

    expect($withoutTime['fek'])->toBe(0.0)
        ->and($withoutTime['complete'])->toBeFalse()
        ->and(implode(' ', $withoutTime['warnings']))->toContain('Produktionszeit fehlt');
});

it('löst eingebettete Paket-Concepts in den Auftragsbedarf auf', function () {
    $concepts = app(ConceptService::class);
    $package = $concepts->createPaket($this->rootTeam, ['name' => 'Kartoffel-Paket']);
    $packageSlot = $concepts->addSlot($this->rootTeam, $package->id, ['role' => 'Hauptgang']);
    $concepts->fillSlot($this->rootTeam, $packageSlot->id, [
        'sales_recipe_id' => $this->recipe->id,
        'quantity' => 1,
    ]);
    $packageSlot->update(['unit_vocab_id' => FoodAlchemistVocabEinheit::where('slug', 'portion')->value('id')]);
    $concepts->recomputeCache($package->refresh());

    $concept = $concepts->create($this->rootTeam, ['name' => 'Menü mit Paket']);
    $slot = $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Paket']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['embedded_concept_id' => $package->id]);
    $concepts->recomputeCache($concept->refresh());

    $result = app(OrderCostingService::class)->costConcept($this->rootTeam, $concept->refresh(), 100);

    expect($result['requirements'])->not->toBeEmpty()
        ->and($result['exploded_mek'])->toBe(20.0)
        ->and($result['mek'])->toBeGreaterThanOrEqual($result['catalog_mek_total'])
        ->and($result['hk2'])->toBeGreaterThanOrEqual($result['mek'])
        ->and($result['complete'])->toBeTrue();
});
