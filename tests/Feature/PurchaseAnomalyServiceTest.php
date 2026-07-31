<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\PurchaseAnomalyService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einkauf E2 (DoD): Der Theil-Sen-Detektor flaggt Fehlbuchungen gegen die robuste
 * Zeit-Trendlinie (nicht gegen einen flachen Median) und lässt echte Trend-Punkte in Ruhe.
 * Fallback auf globalen Median bei zu wenig Datenpunkten. Nur flaggen, nie korrigieren.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->anomaly = app(PurchaseAnomalyService::class);
    $this->supplier = FoodAlchemistSupplier::create([
        'team_id' => $this->childA->id, 'name' => 'Bergischländer', 'status' => 'aktiv',
    ]);
});

function tx($team, $supplier, $gp, string $datum, float $preis): FoodAlchemistPurchaseTransaction
{
    return FoodAlchemistPurchaseTransaction::create([
        'team_id' => $team->id,
        'supplier_id' => $supplier->id,
        'gp_id' => $gp?->id,
        'designation_raw' => 'Rinderhüfte',
        'unit_code' => 'kg',
        'qty' => 10,
        'unit_price' => $preis,
        'line_total' => $preis * 10,
        'purchased_at' => $datum,
        'source' => 'necta_import',
    ]);
}

it('theilSen: Steigung + Achsenabschnitt robust; median korrekt', function () {
    [$slope, $intercept] = $this->anomaly->theilSen([0, 1, 2, 3], [2.0, 3.0, 4.0, 5.0]);
    expect($slope)->toBe(1.0)->and($intercept)->toBe(2.0);
    expect($this->anomaly->median([1, 2, 3, 4]))->toBe(2.5)
        ->and($this->anomaly->median([5, 1, 3]))->toBe(3.0);
});

it('Theil-Sen: flaggt Fehlbuchungen gegen den Zeittrend, nicht die Trend-Punkte', function () {
    $gp = $this->makeGp($this->childA, 'Rinderhüfte');

    // 8 saubere Punkte auf einem echten steigenden Trend (2,00 → 5,50 €/kg).
    $clean = [
        ['2026-01-01', 2.00], ['2026-01-15', 2.50], ['2026-01-29', 3.00], ['2026-02-12', 3.50],
        ['2026-02-26', 4.00], ['2026-03-12', 4.50], ['2026-03-26', 5.00], ['2026-04-09', 5.50],
    ];
    $cleanRows = [];
    foreach ($clean as [$d, $p]) {
        $cleanRows[] = tx($this->childA, $this->supplier, $gp, $d, $p);
    }
    // Zwei Fehlbuchungen: viel zu billig / viel zu teuer.
    $low = tx($this->childA, $this->supplier, $gp, '2026-02-19', 0.50);
    $high = tx($this->childA, $this->supplier, $gp, '2026-03-05', 18.00);

    $flags = $this->anomaly->detect($this->childA, 3.0, 4);
    $ids = array_column($flags, 'transaction_id');

    expect($ids)->toContain($low->id)
        ->and($ids)->toContain($high->id)
        ->and($ids)->not->toContain($cleanRows[0]->id)
        ->and($ids)->not->toContain($cleanRows[4]->id)
        ->and(collect($flags)->firstWhere('transaction_id', $low->id)['method'])->toBe('theil_sen');
});

it('Fallback: globaler Median bei < 4 Punkten', function () {
    $gp = $this->makeGp($this->childA, 'Rinderhüfte');
    tx($this->childA, $this->supplier, $gp, '2026-01-01', 10.00);
    tx($this->childA, $this->supplier, $gp, '2026-01-15', 10.00);
    $ausreisser = tx($this->childA, $this->supplier, $gp, '2026-02-01', 1.00);

    $flags = $this->anomaly->detect($this->childA, 3.0, 4);
    $treffer = collect($flags)->firstWhere('transaction_id', $ausreisser->id);

    expect($treffer)->not->toBeNull()
        ->and($treffer['method'])->toBe('global_median');
});
