<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\EinkaufOptimizerService;
use Platform\FoodAlchemist\Services\RebateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einkauf E4 (DoD): Der Optimizer stellt dem Ist-Wareneinsatz den optimalen (günstigster
 * Lieferant) gegenüber — als Listenpreis UND inkl. Rückvergütung, wobei ein Lieferant mit
 * höherem Listenpreis aber besserer Rückvergütung das Optimum kippen kann. „Lieferant
 * ausklammern" liefert das Was-wäre-wenn.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->optimizer = app(EinkaufOptimizerService::class);
    $this->rebate = app(RebateService::class);
    $team = $this->childA;

    $this->gp = $this->makeGp($team, 'Zwiebel');

    $mkLa = function (string $name, float $preis) use ($team) {
        $sup = FoodAlchemistSupplier::create(['team_id' => $team->id, 'name' => $name, 'status' => 'aktiv']);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $team->id, 'supplier_id' => $sup->id,
            'designation' => 'Zwiebel ' . $name, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $team->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id]);
        FoodAlchemistPrice::create(['team_id' => $team->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);

        return [$sup, $la];
    };
    [$this->aldi] = $mkLa('Aldi', 1.00);       // günstigster Listenpreis, keine Rückvergütung
    [$this->baldur] = $mkLa('Baldur', 2.00);    // teurer, aber 60 % Rückvergütung → effektiv 0,80
    $this->gp->update(['n_las_total' => 2]);

    // Baldur: 60 % Rückvergütung ab dem ersten € → effektiv 2,00 × 0,4 = 0,80.
    $this->rebate->saveTiers($team, $this->baldur->id, [['threshold_eur' => 0, 'percent' => 60]]);
    $this->rebate->saveConfig($team, $this->baldur->id, ['active' => true, 'assumed_annual_revenue' => 1000]);

    // Journal: 10 kg Zwiebel tatsächlich zu 25 € gekauft (2,50 €/kg — teurer als beide).
    FoodAlchemistPurchaseTransaction::create([
        'team_id' => $team->id, 'supplier_id' => $this->aldi->id, 'gp_id' => $this->gp->id,
        'designation_raw' => 'Zwiebel', 'unit_code' => 'kg', 'qty' => 10, 'unit_price' => 2.5,
        'line_total' => 25.0, 'purchased_at' => '2026-06-01', 'source' => 'necta_import',
    ]);
});

it('stellt Ist vs. Optimal (Listenpreis) vs. Optimal (inkl. Rückvergütung) gegenüber', function () {
    $r = $this->optimizer->optimieren($this->childA);

    expect($r['ist_total'])->toBe(25.0)
        ->and($r['optimal_list_total'])->toBe(10.0)      // 10 kg × 1,00 (Aldi)
        ->and($r['optimal_rebate_total'])->toBe(8.0)     // 10 kg × 0,80 (Baldur inkl. 60 %)
        ->and($r['saving_list'])->toBe(15.0)
        ->and($r['saving_rebate'])->toBe(17.0)
        ->and($r['n_articles'])->toBe(1)
        ->and($r['top'][0]['cheapest_list_supplier'])->toBe('Aldi')
        ->and($r['top'][0]['cheapest_rebate_supplier'])->toBe('Baldur');
});

it('Lieferant ausklammern: ohne Baldur fällt das Rückvergütungs-Optimum auf Aldi zurück', function () {
    $r = $this->optimizer->optimieren($this->childA, [$this->baldur->id]);

    expect($r['optimal_list_total'])->toBe(10.0)         // Aldi
        ->and($r['optimal_rebate_total'])->toBe(10.0)    // nur noch Aldi (ohne Rückvergütung)
        ->and($r['top'][0]['cheapest_rebate_supplier'])->toBe('Aldi');
});
