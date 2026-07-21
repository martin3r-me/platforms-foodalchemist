<?php

use Platform\FoodAlchemist\Services\GebindeRechner;

/**
 * S0 (Spec 17 §E3) — Bedarf (Gramm) → ganze Bestell-Gebinde des Lead-LA.
 * Pure Unit-Tests: Lead-LA duck-typed (qty/unit_code/packaging_unit/article_number/aktiver_preis).
 */
beforeEach(function () {
    $this->r = new GebindeRechner;
    $this->la = fn (array $a) => (object) array_merge([
        'qty' => null, 'unit_code' => null, 'packaging_unit' => null,
        'article_number' => null, 'aktiver_preis' => null,
    ], $a);
});

it('kg: rundet Teilbedarf auf ein ganzes Gebinde auf + zeigt Überkauf', function () {
    // 1,296 kg Bedarf, 2-kg-Gebinde à 10 € → 1 Gebinde, Rest 0,704 kg.
    $la = ($this->la)(['qty' => 2, 'unit_code' => 'kg', 'packaging_unit' => 'Karton', 'article_number' => '4711', 'aktiver_preis' => 10.0]);
    $out = $this->r->berechne($la, 1296.0);

    expect($out['berechenbar'])->toBeTrue()
        ->and($out['qty_packs'])->toBe(1)
        ->and($out['pack_unit_code'])->toBe('kg')
        ->and($out['article_number'])->toBe('4711')
        ->and($out['needed_base'])->toBe(1.296)
        ->and($out['line_total'])->toBe(10.0)
        ->and($out['ueberkauf_base'])->toBe(0.704);
});

it('kg: exaktes Vielfaches rundet NICHT hoch (Epsilon-Guard)', function () {
    // 4,0 kg bei 2-kg-Gebinde = genau 2 Gebinde, nicht 3.
    $la = ($this->la)(['qty' => 2, 'unit_code' => 'kg', 'aktiver_preis' => 5.0]);
    $out = $this->r->berechne($la, 4000.0);

    expect($out['qty_packs'])->toBe(2)->and($out['line_total'])->toBe(10.0)
        ->and($out['ueberkauf_base'])->toBe(0.0);
});

it('rundet auf dem AGGREGIERTEN Bedarf (kein Doppel-Aufrunden bei 2 Quellen)', function () {
    // Aufrufer summiert 1,2 kg + 0,7 kg = 1,9 kg VOR der Rechnung → 1 Gebinde (nicht 2).
    $la = ($this->la)(['qty' => 2, 'unit_code' => 'kg', 'aktiver_preis' => 8.0]);
    $out = $this->r->berechne($la, 1200.0 + 700.0);

    expect($out['qty_packs'])->toBe(1);
});

it('l: behandelt Liter wie kg (Dichte 1.0)', function () {
    $la = ($this->la)(['qty' => 2, 'unit_code' => 'l', 'packaging_unit' => 'Schlauch', 'aktiver_preis' => 11.72]);
    $out = $this->r->berechne($la, 1296.0);

    expect($out['berechenbar'])->toBeTrue()
        ->and($out['qty_packs'])->toBe(1)
        ->and($out['needed_base_unit'])->toBe('l')
        ->and($out['line_total'])->toBe(11.72);
});

it('Stk: rechnet Gramm über das Stückgewicht in Kartons', function () {
    // 5.000 g Eier, Stückgewicht 50 g → 100 Stück; 30er-Karton à 6 € → 4 Kartons.
    $la = ($this->la)(['qty' => 30, 'unit_code' => 'Stk', 'packaging_unit' => 'Karton', 'aktiver_preis' => 6.0]);
    $out = $this->r->berechne($la, 5000.0, 50.0);

    expect($out['berechenbar'])->toBeTrue()
        ->and($out['qty_packs'])->toBe(4)
        ->and($out['needed_base'])->toBe(100.0)
        ->and($out['needed_base_unit'])->toBe('Stk')
        ->and($out['line_total'])->toBe(24.0);
});

it('Stk ohne Stückgewicht: nicht berechenbar (kein Gramm-÷-Stück-Unsinn)', function () {
    $la = ($this->la)(['qty' => 30, 'unit_code' => 'Stk', 'aktiver_preis' => 6.0]);
    $out = $this->r->berechne($la, 5000.0, null);

    expect($out['berechenbar'])->toBeFalse()
        ->and($out['grund'])->toContain('Stückgewicht');
});

it('qty NULL (Preisfalle): nicht berechenbar, Grund benannt', function () {
    $la = ($this->la)(['qty' => null, 'unit_code' => 'kg', 'aktiver_preis' => 10.0]);
    $out = $this->r->berechne($la, 1296.0);

    expect($out['berechenbar'])->toBeFalse()
        ->and($out['grund'])->toContain('Preisfalle');
});

it('kein Lead-LA: nicht berechenbar', function () {
    $out = $this->r->berechne(null, 1296.0);

    expect($out['berechenbar'])->toBeFalse()
        ->and($out['grund'])->toContain('Kein Lead');
});

it('Preis unbekannt: Gebinde-Anzahl steht, line_total bleibt null', function () {
    $la = ($this->la)(['qty' => 5, 'unit_code' => 'kg', 'aktiver_preis' => null]);
    $out = $this->r->berechne($la, 1000.0);

    expect($out['berechenbar'])->toBeTrue()
        ->and($out['qty_packs'])->toBe(1)
        ->and($out['line_total'])->toBeNull();
});

it('unbekannte Einheit: nicht berechenbar', function () {
    $la = ($this->la)(['qty' => 6, 'unit_code' => 'Pack', 'aktiver_preis' => 3.0]);
    $out = $this->r->berechne($la, 1000.0);

    expect($out['berechenbar'])->toBeFalse()
        ->and($out['grund'])->toContain('nicht bestellbar');
});
