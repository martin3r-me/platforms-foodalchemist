<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M2-11 (Artikel-CRUD, D1) + M2-12 (Preis-Anomalien).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->items = app(SupplierItemService::class);
    $this->preise = app(PriceService::class);

    $this->bos = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS Food']);
});

it('DoD M2-11: Kind legt eigenen Artikel an — Eltern und Geschwister sehen ihn NICHT', function () {
    $eigen = $this->items->create($this->childA, $this->bos->id, ['designation' => 'Hauslimonade A', 'qty' => '0,5', 'unit_code' => 'l']);

    expect($eigen->team_id)->toBe($this->childA->id)
        ->and((float) $eigen->qty)->toBe(0.5)
        ->and(FoodAlchemistSupplierItem::visibleToTeam($this->childA)->pluck('id'))->toContain($eigen->id)
        ->and(FoodAlchemistSupplierItem::visibleToTeam($this->rootTeam)->pluck('id'))->not->toContain($eigen->id)
        ->and(FoodAlchemistSupplierItem::visibleToTeam($this->childB)->pluck('id'))->not->toContain($eigen->id);
});

it('Pflichtfeld + Sichtbarkeits-Guard, Deaktivieren soft und nur Besitzer', function () {
    expect(fn () => $this->items->create($this->rootTeam, $this->bos->id, ['designation' => '  ']))
        ->toThrow(RuntimeException::class, 'Pflicht');

    $item = $this->items->create($this->rootTeam, $this->bos->id, ['designation' => 'Zander TK']);
    $this->items->setDiscontinued($this->rootTeam, $item, true);
    expect($item->fresh()->is_discontinued)->toBeTrue()
        ->and(FoodAlchemistSupplierItem::find($item->id))->not->toBeNull(); // soft, kein Delete

    expect(fn () => $this->items->setDiscontinued($this->childA, $item, false))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});

it('DoD M2-12: Ausreißer je WG (Faktor ≥ 4 vom Median) und Generationen-Sprünge > 30 %', function () {
    $gp = $this->makeGp($this->rootTeam, 'Tomate');
    $gp->update(['commodity_group_code' => '01']);

    // 4 normale + 1 Ausreißer in WG 01 (kg)
    foreach ([2.0, 2.2, 2.4, 2.6, 24.0] as $i => $preis) {
        $item = $this->items->create($this->rootTeam, $this->bos->id, ['designation' => "Tomate {$i}", 'qty' => '1', 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $gp->id]);
        $this->preise->createFor($this->rootTeam, $item, $preis);
        if ($i === 0) {
            $this->preise->createFor($this->rootTeam, $item, $preis * 2); // +100 % Sprung
        }
    }

    $ergebnis = $this->preise->detectAnomalies($this->rootTeam);

    expect($ergebnis['ausreisser'])->toHaveCount(1)
        ->and($ergebnis['ausreisser']->first()->value)->toBe(24.0)->and($ergebnis['ausreisser']->first()->label)->toBe('Tomate 4')
        ->and($ergebnis['ausreisser']->first()->faktor)->toBeGreaterThanOrEqual(4.0)
        ->and($ergebnis['spruenge'])->toHaveCount(1)
        ->and($ergebnis['spruenge']->first()->sprung_pct)->toBe(100.0);
});
