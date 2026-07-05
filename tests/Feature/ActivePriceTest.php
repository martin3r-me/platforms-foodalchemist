<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M2-04/05 — GL-11 Golden: aktiver Preis (§3.3) + Vergleichspreis-Normalisierung (§3.2).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->preise = app(PriceService::class);

    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Delta Fleisch']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Limettensaft konserviert', 'qty' => null, 'unit_code' => 'l',
    ]);
    $this->mkPreis = fn (array $attrs) => FoodAlchemistPrice::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->la->id, 'is_blocked' => false,
        ...$attrs,
    ]);
});

it('GT-1: aktiver Preis 47,50 € mit status 2 (aktion) gewinnt — Service-Zuschlag und eingestellt unsichtbar', function () {
    ($this->mkPreis)(['price' => -9.0, 'status' => '0', 'valid_to' => '2027-12-31 00:00:00']); // service_charge (I5)
    ($this->mkPreis)(['price' => null, 'status' => '2', 'valid_to' => null]);                   // eingestellt
    $aktion = ($this->mkPreis)(['price' => 47.50, 'status' => '2', 'valid_to' => '2026-12-31 00:00:00']);

    $aktiv = $this->preise->activeFor($this->la->id);

    expect($aktiv?->id)->toBe($aktion->id)
        ->and((float) $aktiv->price)->toBe(47.50)
        ->and($aktiv->status)->toBe('2');
});

it('§3.3: neueste aktive Zeile gewinnt (valid_to DESC, Tiebreak id DESC), geblockte fliegen raus', function () {
    $alt = ($this->mkPreis)(['price' => 10.0, 'status' => '0', 'valid_to' => '2026-01-31 00:00:00']);
    $neu = ($this->mkPreis)(['price' => 12.0, 'status' => '0', 'valid_to' => '2026-12-31 00:00:00']);
    ($this->mkPreis)(['price' => 5.0, 'status' => '0', 'valid_to' => '2027-12-31 00:00:00', 'is_blocked' => true]);

    expect($this->preise->activeFor($this->la->id)?->id)->toBe($neu->id);

    // Tiebreak: gleiche valid_to ⇒ höchste id
    $spaeter = ($this->mkPreis)(['price' => 13.0, 'status' => '0', 'valid_to' => '2026-12-31 00:00:00']);
    expect($this->preise->activeFor($this->la->id)?->id)->toBe($spaeter->id);
});

it('GT-5: qty NULL ⇒ Vergleichspreis NULL trotz aktiven Preises (I4, nie Division)', function () {
    expect($this->preise->vergleichspreis($this->la, 47.50))->toBeNull();
});

it('GT-2/3/4: Normalisierung kg, l, Stk — Werte wörtlich aus GL-11 §5', function () {
    $zucker = new FoodAlchemistSupplierItem(['qty' => 25, 'unit_code' => 'kg']);
    $bos = new FoodAlchemistSupplierItem(['qty' => 2.4, 'unit_code' => 'l']);
    $limette = new FoodAlchemistSupplierItem(['qty' => 0.75, 'unit_code' => 'l']);
    $epos = new FoodAlchemistSupplierItem(['qty' => 1.0, 'unit_code' => 'Stk']);

    expect($this->preise->vergleichspreis($zucker, 42.00))->toBe(['value' => 1.68, 'unit' => '€/kg'])
        ->and($this->preise->preisProGramm($zucker, 42.00))->toEqualWithDelta(0.00168, 1e-12)
        ->and($this->preise->vergleichspreis($bos, 22.50))->toBe(['value' => 9.375, 'unit' => '€/l'])
        ->and(round($this->preise->vergleichspreis($limette, 2.69)['value'], 4))->toBe(3.5867)
        ->and($this->preise->vergleichspreis($epos, 1.72))->toBe(['value' => 1.72, 'unit' => '€/Stk'])
        ->and($this->preise->preisProGramm($epos, 1.72))->toBeNull(); // Stk→g nur via Stückgewichts-Brücke (T3)
});

it('I5: negativer Preis ist nie Vergleichsbasis; qty 0 ebenfalls NULL', function () {
    $kg = new FoodAlchemistSupplierItem(['qty' => 25, 'unit_code' => 'kg']);
    $nullQty = new FoodAlchemistSupplierItem(['qty' => 0, 'unit_code' => 'kg']);

    expect($this->preise->vergleichspreis($kg, -9.0))->toBeNull()
        ->and($this->preise->vergleichspreis($nullQty, 42.0))->toBeNull();
});
