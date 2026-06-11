<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\KpiService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M0-13: KpiService — Zähler je Team-Kette (D1), 60 s Cache.
 * DoD: Zahlen stimmen mit SQL-Counts überein.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS Food']);
    $itemA = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Limettensaft 1l']);
    $itemB = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Zanderfilet TK']);
    // LAs im KPI-Sinn = kuratierte Strukturen (eine je Artikel hier)
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $itemA->id]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $itemB->id]);

    $this->makeGp($this->rootTeam, 'Zanderfilet');
    $this->makeGp($this->rootTeam, 'Limettensaft');
    $this->makeGp($this->childA, 'Hauslimonade');
});

it('zählt je Team-Kette und stimmt mit SQL-Counts überein', function () {
    $kpis = app(KpiService::class)->forTeam($this->childA);

    $ancestry = FoodAlchemistGp::teamAncestryIds($this->childA);
    $sql = fn (string $table) => DB::table($table)->whereIn('team_id', $ancestry)->whereNull('deleted_at')->count();

    expect($kpis['lieferanten'])->toBe($sql('foodalchemist_suppliers'))->toBe(1)
        ->and($kpis['gps'])->toBe($sql('foodalchemist_gps'))->toBe(3)
        ->and($kpis['las'])->toBe($sql('foodalchemist_supplier_item_structures'))->toBe(2)
        ->and($kpis['rezepte'])->toBeNull(); // Tabelle existiert erst ab M4-01
});

it('sieht als Geschwister-Team nur den Eltern-Katalog (kein Leak in den Zahlen)', function () {
    $kpis = app(KpiService::class)->forTeam($this->childB);

    expect($kpis['gps'])->toBe(2); // 2× Root — Hauslimonade von Kind A bleibt unsichtbar
});

it('cacht 60 s und lässt sich gezielt flushen', function () {
    $service = app(KpiService::class);

    expect($service->forTeam($this->childA)['gps'])->toBe(3);

    $this->makeGp($this->rootTeam, 'Rote Bete');
    expect($service->forTeam($this->childA)['gps'])->toBe(3); // Cache-Treffer, bewusst stale

    $service->flush($this->childA);
    expect($service->forTeam($this->childA)['gps'])->toBe(4); // frisch gezählt
});

afterEach(function () {
    Cache::flush(); // KPI-Cache nicht in den nächsten Test tragen
});
