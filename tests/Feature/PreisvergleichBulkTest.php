<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #6.3 N+1 (Bug-Runde 2026-08): der Controlling-Preisvergleich rief rangliste() JE GP (bis 60) →
 * ~3 Queries pro GP. Fix: ranglisteBulk() laedt Kandidaten + Overlay je in EINEM whereIn und nutzt
 * dieselbe Sortier-Logik (sortiere()). Kritisch: das Ergebnis muss bit-identisch zum Einzel-Pfad sein.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(LeadLaService::class);
    FoodAlchemistLookupWarengruppe::create(['team_id' => $this->rootTeam->id, 'code' => '10', 'name' => 'Getränke']);

    $this->mkGp = function (string $name) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['commodity_group_code' => '10']);

        return $gp->refresh();
    };
    $this->mkLa = function ($gp, string $lieferant, array $item = [], ?float $preis = null, string $status = '0') {
        $supplier = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => $lieferant]);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'LA ' . uniqid(), ...$item,
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
        ]);
        if ($preis !== null) {
            FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => $status]);
        }

        return $la;
    };
});

it('#6.3: ranglisteBulk ist reihenfolge-identisch zum Einzel-rangliste je GP', function () {
    $gpA = ($this->mkGp)('GP A');
    ($this->mkLa)($gpA, 'Chefs', ['qty' => 1.0, 'unit_code' => 'l'], 5.00);
    ($this->mkLa)($gpA, 'Hanos', ['qty' => 1.0, 'unit_code' => 'l'], 3.00);
    ($this->mkLa)($gpA, 'Delta', ['qty' => 1.0, 'unit_code' => 'l'], 8.00);
    $gpB = ($this->mkGp)('GP B');
    ($this->mkLa)($gpB, 'BOS', ['qty' => 1.0, 'unit_code' => 'kg'], 2.00);
    ($this->mkLa)($gpB, 'Edna', ['qty' => 1.0, 'unit_code' => 'kg'], 4.00);

    $gps = collect([$gpA, $gpB]);
    $bulk = $this->svc->ranglisteBulk($gps, $this->rootTeam);

    foreach ($gps as $gp) {
        $einzeln = $this->svc->rangliste($gp, $this->rootTeam)->pluck('id')->all();
        $ausBulk = collect($bulk[$gp->id])->pluck('id')->all();
        expect($ausBulk)->toBe($einzeln);   // bit-identische Reihenfolge
    }
});

it('#6.3: ranglisteBulk laedt Kandidaten + Overlay je in EINEM Query (kein per-GP-N+1)', function () {
    $gpA = ($this->mkGp)('GP A'); ($this->mkLa)($gpA, 'Chefs', ['qty' => 1.0, 'unit_code' => 'l'], 5.00);
    $gpB = ($this->mkGp)('GP B'); ($this->mkLa)($gpB, 'BOS', ['qty' => 1.0, 'unit_code' => 'kg'], 2.00);
    $gpC = ($this->mkGp)('GP C'); ($this->mkLa)($gpC, 'Hanos', ['qty' => 1.0, 'unit_code' => 'l'], 3.00);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->svc->ranglisteBulk(collect([$gpA, $gpB, $gpC]), $this->rootTeam);
    $log = collect(DB::getQueryLog());
    DB::disableQueryLog();

    // Kandidaten (structures) + Overlay (gp_la_preferences) je genau 1× fuer 3 GPs — nicht 3×.
    expect($log->filter(fn ($q) => str_contains($q['query'], 'foodalchemist_supplier_item_structures'))->count())->toBe(1)
        ->and($log->filter(fn ($q) => str_contains($q['query'], 'foodalchemist_gp_la_preferences'))->count())->toBe(1);
});
