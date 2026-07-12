<?php

use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M2-09/10: LA-Allergene — 4-Wert-Modell ohne Lücken, manuelle Lineage, D1-Gate.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SupplierItemService::class);

    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Limettensaft 0,75l',
    ]);
});

it('liefert IMMER 14 Werte — ohne Zeile alles unbekannt (GL-01: nie Lücken)', function () {
    $werte = $this->svc->getAllergens($this->la);

    expect($werte)->toHaveCount(14)
        ->and(array_unique(array_values($werte)))->toBe(['unbekannt'])
        ->and(array_keys($werte))->toBe(array_keys(FoodAlchemistItemAllergen::ALLERGENE));
});

it('Set-Roundtrip: unbekannt ⇒ NULL in DB, manuelle Pflege stempelt quelle=manual (GL-07)', function () {
    $this->svc->setAllergens($this->rootTeam, $this->la, [
        'milk' => 'enthalten', 'soy' => 'spuren', 'fish' => 'nicht_enthalten',
    ]);

    $zeile = $this->la->fresh()->allergens;
    expect($zeile->allergen_milk)->toBe('enthalten')
        ->and($zeile->allergen_soy)->toBe('spuren')
        ->and($zeile->allergen_fish)->toBe('nicht_enthalten')
        ->and($zeile->allergen_mustard)->toBeNull() // unbekannt ⇒ NULL
        ->and($zeile->source)->toBe('manual');

    expect($this->svc->getAllergens($this->la->fresh())['mustard'])->toBe('unbekannt');
});

it('Edit ändert die Werte (DoD M2-10 — Aggregations-Quelle für M3-04/05)', function () {
    $this->svc->setAllergens($this->rootTeam, $this->la, ['milk' => 'enthalten']);
    $this->svc->setAllergens($this->rootTeam, $this->la, ['milk' => 'spuren']);

    expect($this->la->fresh()->allergens->allergen_milk)->toBe('spuren')
        ->and(FoodAlchemistItemAllergen::where('supplier_item_id', $this->la->id)->count())->toBe(1); // Upsert, keine Duplikate
});

it('Kind-Team darf geerbte LA-Allergene nicht pflegen (D1); ungültige Werte typisiert abgelehnt (V-06)', function () {
    expect(fn () => $this->svc->setAllergens($this->childA, $this->la, ['milk' => 'enthalten']))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
    expect(fn () => $this->svc->setAllergens($this->rootTeam, $this->la, ['milk' => 'vielleicht']))
        ->toThrow(RuntimeException::class, 'Ungültiger Allergen-Wert');
});
