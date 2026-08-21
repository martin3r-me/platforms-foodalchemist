<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #6.2 N+1 (Bug-Runde 2026-08): die Bestell-Liste (Orders/Index) beschriftete die Produktions-
 * Herkunft JE ORDER — ein ProductionOrder-whereIn-Query pro Zeile. Fix: herkunftMitProduktionsnamenBulk
 * zieht alle Produktions-IDs ueber alle Orders in EINEM Query und beschriftet dann in-memory.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('#6.2: herkunftMitProduktionsnamenBulk laedt alle Produktions-Namen in EINEM Query', function () {
    $prod = app(ProductionOrderService::class);
    $p1 = $prod->resolveOrCreate($this->rootTeam, null, '2026-07-06', 'Prod A', (int) Auth::id());
    $p2 = $prod->resolveOrCreate($this->rootTeam, null, '2026-07-07', 'Prod B', (int) Auth::id());

    // Drei Bestellungen, die auf zwei Produktions-Auftraege zeigen (p1 doppelt).
    $herkunftProOrder = [
        10 => [['key' => 'a', 'type' => 'produktion', 'label' => '?', 'production_order_id' => $p1->id]],
        11 => [['key' => 'b', 'type' => 'produktion', 'label' => '?', 'production_order_id' => $p2->id]],
        12 => [['key' => 'c', 'type' => 'produktion', 'label' => '?', 'production_order_id' => $p1->id]],
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();
    $out = app(OrderService::class)->herkunftMitProduktionsnamenBulk($this->rootTeam, $herkunftProOrder);
    $prodQueries = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'foodalchemist_production_orders'))->count();
    DB::disableQueryLog();

    // Der Kern des Fixes: EIN Query fuer drei Orders (vorher: drei).
    expect($prodQueries)->toBe(1);

    // Und die Beschriftung stimmt weiterhin je Order.
    expect($out[10][0]['label'])->toContain('Prod A')
        ->and($out[11][0]['label'])->toContain('Prod B')
        ->and($out[12][0]['label'])->toContain('Prod A');
});

it('#6.2: die Einzel-Methode bleibt unveraendert korrekt (Ruecklauf ohne Produktions-Ref)', function () {
    $ohne = [['key' => 'x', 'type' => 'rezept', 'label' => 'Rezept X', 'production_order_id' => null]];
    $out = app(OrderService::class)->herkunftMitProduktionsnamen($this->rootTeam, $ohne);
    expect($out)->toBe($ohne);   // ohne Produktions-Ref: unveraendert
});
