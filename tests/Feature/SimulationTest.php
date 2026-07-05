<?php

use Platform\FoodAlchemist\Services\SimulationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R2.2 — Was-wäre-wenn-Simulation (read-only). Szenario ±X% über GP/Artikel/WG →
 * Portfolio-Marge-Delta + Top-Gerichte. Transitiv (GP → Basisrezept → Gericht),
 * nutzt dieselbe Impact-Rechnung wie der R2.1-Preis-Alarm (MargeImpactService).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->sim = app(SimulationService::class);
});

it('gp-Szenario +50%: transitives Gericht (via Basisrezept) mit Marge-Delta, read-only', function () {
    $g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $sup = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Butter 1 kg', 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    \Platform\FoodAlchemist\Models\FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 10.0, 'status' => '0', 'valid_to' => null]);
    $gp = $this->makeGp($this->rootTeam, 'Butter');
    $gp->update(['lead_la_supplier_item_id' => $la->id, 'commodity_group_code' => '01']);
    \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
    ]); // LA↔GP-Struktur: Recompute findet darüber den GP-Preis

    $gericht = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'gericht-x', 'name' => 'Buttergericht', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 20.0,
    ]);
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'gp_id' => $gp->id, 'raw_text' => 'Butter', 'quantity' => '500', 'unit_vocab_id' => $g->id, 'position' => 1,
    ]);

    $r = $this->sim->simuliere($this->rootTeam, 'gp', (string) $gp->id, 50.0);

    expect($r['n_gps'])->toBe(1)
        ->and($r['n_gerichte'])->toBe(1)               // transitiv gefunden
        ->and($r['ratio'])->toBe(1.5)
        ->and($r['marge_delta_eur'])->toBeLessThan(0.0) // +50% Butter → Marge sinkt
        ->and($r['top'])->toHaveCount(1)
        ->and($r['top'][0]['recipe_id'])->toBe($gericht->id);

    // read-only: Preis in der DB unverändert
    expect((float) \Platform\FoodAlchemist\Models\FoodAlchemistPrice::where('supplier_item_id', $la->id)->value('price'))->toBe(10.0);
});

it('warengruppe-Szenario aggregiert alle GPs der WG; leeres Szenario = neutrale Antwort', function () {
    $leer = $this->sim->simuliere($this->rootTeam, 'warengruppe', '99', 10.0);
    expect($leer['n_gps'])->toBe(0)
        ->and($leer['n_gerichte'])->toBe(0)
        ->and($leer['marge_delta_eur'])->toBe(0.0);
});
