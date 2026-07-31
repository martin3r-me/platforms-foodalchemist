<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\RebateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einkauf E1 (DoD): Die Rückvergütungs-Staffel wählt den effektiven Prozentsatz
 * NACHWEISBAR — Auto aus Umsatz, manueller Override, Warengruppen-Ausschluss,
 * Flat-Fallback (Legacy rebate_pct) und Was-wäre-wenn-Simulation.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->rebate = app(RebateService::class);

    $this->supplier = FoodAlchemistSupplier::create([
        'team_id' => $this->childA->id,
        'name' => 'Chefs Culinar',
        'status' => 'aktiv',
    ]);

    // Staffel: ab 0 € = 2 %, ab 200k = 3 %, ab 300k = 3,5 %.
    $this->rebate->saveTiers($this->childA, $this->supplier->id, [
        ['threshold_eur' => 0, 'percent' => 2.0],
        ['threshold_eur' => 200000, 'percent' => 3.0],
        ['threshold_eur' => 300000, 'percent' => 3.5],
    ]);
});

it('Auto-Stufe: höchste vom Jahresumsatz erreichte Schwelle gewinnt', function () {
    $this->rebate->saveConfig($this->childA, $this->supplier->id, [
        'active' => true,
        'assumed_annual_revenue' => 250000,
    ]);

    expect($this->rebate->effektiverProzent($this->childA, $this->supplier->id))->toBe(3.0);
});

it('manuelle Stufe schlägt die Auto-Ableitung aus dem Umsatz', function () {
    $tiers = $this->rebate->tiersFor($this->childA, $this->supplier->id);
    $zweiProzent = $tiers->firstWhere('percent', 2.0);

    $this->rebate->saveConfig($this->childA, $this->supplier->id, [
        'active' => true,
        'assumed_annual_revenue' => 250000,        // Auto wäre 3,0 %
        'selected_tier_id' => $zweiProzent->id,     // manuell auf 2,0 %
    ]);

    expect($this->rebate->effektiverProzent($this->childA, $this->supplier->id))->toBe(2.0);
});

it('Vollsortiment: voller Satz für jede Warengruppe', function () {
    $this->rebate->saveConfig($this->childA, $this->supplier->id, [
        'active' => true,
        'assumed_annual_revenue' => 300000,   // Auto = 3,5 %
        'applies_to_all' => true,
    ]);

    expect($this->rebate->effektiverProzent($this->childA, $this->supplier->id, '01'))->toBe(3.5)
        ->and($this->rebate->effektiverProzent($this->childA, $this->supplier->id, '05'))->toBe(3.5);
});

it('WG-Umfang: nur gewählte Warengruppen bekommen den Bonus, andere 0 %', function () {
    $this->rebate->saveConfig($this->childA, $this->supplier->id, [
        'active' => true,
        'assumed_annual_revenue' => 300000,      // Auto = 3,5 %
        'applies_to_all' => false,
        'commodity_groups' => ['12', '01'],      // nur Trockenprodukte + Gemüse
    ]);

    expect($this->rebate->effektiverProzent($this->childA, $this->supplier->id, '12'))->toBe(3.5)
        ->and($this->rebate->effektiverProzent($this->childA, $this->supplier->id, '04'))->toBe(0.0);
});

it('inaktive Config zahlt keinen Bonus (0 %, kein Flat-Fallback)', function () {
    $this->rebate->saveConfig($this->childA, $this->supplier->id, [
        'active' => false,
        'assumed_annual_revenue' => 300000,
    ]);

    expect($this->rebate->effektiverProzent($this->childA, $this->supplier->id, null, null, false))->toBe(0.0);
});

it('Flat-Fallback: ohne Team-Config greift Legacy rebate_pct', function () {
    $flat = FoodAlchemistSupplier::create([
        'team_id' => $this->childA->id,
        'name' => 'Alt-Lieferant flach',
        'status' => 'aktiv',
        'rebate_pct' => 5.0,
    ]);

    expect($this->rebate->effektiverProzent($this->childA, $flat->id))->toBe(5.0);
});

it('Simulation (revenueOverride) überstimmt die gespeicherte Wahl', function () {
    $tiers = $this->rebate->tiersFor($this->childA, $this->supplier->id);
    $this->rebate->saveConfig($this->childA, $this->supplier->id, [
        'active' => true,
        'selected_tier_id' => $tiers->firstWhere('percent', 2.0)->id,   // manuell 2 %
    ]);

    // Was-wäre-wenn bei 350k Umsatz → 3,5 %, ignoriert die manuelle Wahl.
    expect($this->rebate->effektiverProzent($this->childA, $this->supplier->id, null, 350000.0))->toBe(3.5);
});

it('preisNachRabatt rechnet den effektiven Nettopreis', function () {
    expect($this->rebate->preisNachRabatt(10.0, 3.0))->toBe(9.7)
        ->and($this->rebate->preisNachRabatt(10.0, 0.0))->toBe(10.0);
});

it('enrichRangliste legt rabatt_prozent + effektiven Vergleichspreis je Zeile ab', function () {
    $this->rebate->saveConfig($this->childA, $this->supplier->id, [
        'active' => true,
        'assumed_annual_revenue' => 250000,   // 3 %
    ]);

    $la = new FoodAlchemistSupplierItem();
    $la->setAttribute('supplier_id', $this->supplier->id);
    $la->setAttribute('vergleichspreis_wert', 10.0);

    $out = $this->rebate->enrichRangliste($this->childA, collect([$la]));

    expect($out->first()->rabatt_prozent)->toBe(3.0)
        ->and($out->first()->vergleichspreis_mit_rabatt_wert)->toBe(9.7);
});
