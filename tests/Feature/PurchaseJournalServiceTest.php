<?php

use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\PurchaseJournalService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einkauf E2 (DoD): Ein in FA getätigter Einkauf (Bestellschiene) landet als Ist-Einkauf
 * im Journal — mit korrekter Menge (qty_packs × pack_qty), €/Einheit und Spend — und
 * verschwindet bei Storno wieder. Idempotent (erneutes Spiegeln dupliziert nicht).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->journal = app(PurchaseJournalService::class);

    $this->supplier = FoodAlchemistSupplier::create([
        'team_id' => $this->childA->id,
        'name' => 'Chefs Culinar',
        'status' => 'aktiv',
    ]);

    $this->order = FoodAlchemistOrder::create([
        'team_id' => $this->childA->id,
        'supplier_id' => $this->supplier->id,
        'status' => 'delivered',
    ]);
    FoodAlchemistOrderLine::create([
        'team_id' => $this->childA->id, 'order_id' => $this->order->id, 'position' => 1,
        'designation' => 'Kartoffel festkochend', 'unit_code' => 'kg',
        'pack_qty' => 5, 'pack_price' => 10.0, 'qty_packs' => 3, 'line_total' => 30.0,
    ]);
    FoodAlchemistOrderLine::create([
        'team_id' => $this->childA->id, 'order_id' => $this->order->id, 'position' => 2,
        'designation' => 'Zwiebel', 'unit_code' => 'kg',
        'pack_qty' => 1, 'pack_price' => 4.0, 'qty_packs' => 2, 'line_total' => 8.0,
    ]);
});

it('spiegelt eine Bestellschiene als Ist-Einkäufe (Menge, €/Einheit, Spend)', function () {
    $n = $this->journal->spiegelOrder($this->order);

    expect($n)->toBe(2);

    $tx = FoodAlchemistPurchaseTransaction::where('team_id', $this->childA->id)
        ->orderBy('id')->get();
    expect($tx)->toHaveCount(2)
        ->and((float) $tx[0]->qty)->toBe(15.0)          // 3 Gebinde × 5 kg
        ->and((float) $tx[0]->unit_price)->toBe(2.0)     // 10 € / 5 kg
        ->and((float) $tx[0]->line_total)->toBe(30.0)
        ->and($tx[0]->source)->toBe('fa_order')
        ->and((float) $tx[1]->qty)->toBe(2.0);

    expect($this->journal->spend($this->childA))->toBe(38.0)
        ->and($this->journal->spendProLieferant($this->childA)[$this->supplier->id])->toBe(38.0);
});

it('ist idempotent — erneutes Spiegeln dupliziert nicht', function () {
    $this->journal->spiegelOrder($this->order);
    $this->journal->spiegelOrder($this->order);

    expect(FoodAlchemistPurchaseTransaction::where('team_id', $this->childA->id)->count())->toBe(2)
        ->and($this->journal->spend($this->childA))->toBe(38.0);
});

it('Storno entfernt die Ist-Buchungen wieder', function () {
    $this->journal->spiegelOrder($this->order);
    $entfernt = $this->journal->entferneOrder($this->order);

    expect($entfernt)->toBe(2)
        ->and(FoodAlchemistPurchaseTransaction::where('team_id', $this->childA->id)->count())->toBe(0)
        ->and($this->journal->spend($this->childA))->toBe(0.0);
});

it('Journal-Trigger-Default ist delivered', function () {
    expect(app(TeamSettingsService::class)->purchaseJournalTrigger($this->childA))->toBe('delivered');
});
