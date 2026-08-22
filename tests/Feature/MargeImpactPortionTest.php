<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\MargeImpactService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Audit 2026-08-22: impactFuerGps mischte Batch-EK (ek_total_eur) mit Portions-VK
 * (sales_net) -> marge%/wareneinsatz% falsch. Fix normalisiert EK auf die Portion
 * (ek_total/sales_unit_count, Modul-Konvention). Dieser Test sperrt die Portions-Skala.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeRecomputeService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1])->id;

    $gp = $this->makeGp($this->rootTeam, 'Impact-Zutat');
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id, 'designation' => 'Impact-Zutat LA', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 10.0, 'status' => '0']);
    $gp->update(['lead_la_supplier_item_id' => $la->id, 'n_las_total' => 1]);
    $this->gp = $gp->refresh();
});

it('impactFuerGps rechnet marge% gegen den Portions-EK (ek_total / sales_unit_count), nicht den Batch-EK', function () {
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sup_impact_test', 'name' => '[SUP] Impact | Test',
        'status' => 'draft', 'is_sales_recipe' => true, 'sales_net' => 2.0, 'sales_unit_count' => 10,
    ]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'position' => 1, 'raw_text' => 'Impact-Zutat',
        'match_method' => 'manual', 'unit_vocab_id' => $this->g, 'quantity' => 1000, 'gp_id' => $this->gp->id,
    ]);
    $this->svc->recomputePipeline($r->id);
    $r->refresh();

    $ekBatch = (float) $r->ek_total_eur;
    // Guard: Batch-EK > Portions-VK (sonst wäre die Skala-Unterscheidung nicht sichtbar).
    expect($ekBatch)->toBeGreaterThan(2.0);
    $ekPortion = $ekBatch / 10;

    $impact = app(MargeImpactService::class)->impactFuerGps($this->rootTeam, [$this->gp->id], 1.5);
    expect($impact['top'])->not->toBeEmpty();
    $top = collect($impact['top'])->firstWhere('recipe_id', $r->id);

    // marge% gegen PORTIONS-EK — mit Batch-EK (10) wäre marge_pct_ist negativ.
    expect($top['marge_pct_ist'])->toBe(round((2.0 - $ekPortion) / 2.0 * 100, 1))
        ->and($top['marge_pct_ist'])->toBeGreaterThan(0.0)
        ->and($top['marge_delta_eur'])->toBe(round(-($ekPortion) * (1.5 - 1), 2)); // Pro-Portion-Delta
});
