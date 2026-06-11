<?php

use Platform\FoodAlchemist\Enums\PriceCategory;

/**
 * GL-11 GT-1 (Golden, DB-verifizierte Realfälle): (price, status) → preis_kategorie.
 * Schwellen wörtlich aus §3.1 — price<0 schlägt status (I5).
 */
it('kategorisiert nach GL-11 §3.1', function (?float $price, ?string $status, PriceCategory $erwartet) {
    expect(PriceCategory::fuer($price, $status))->toBe($erwartet);
})->with([
    'GT-1 LA 29344887: 2.69/0 → standard_ek' => [2.69, '0', PriceCategory::StandardEk],
    'GT-1 LA 31141191: 47.5/2 → aktion' => [47.5, '2', PriceCategory::Aktion],
    'GT-1 LA 23614830: NULL/2 → eingestellt' => [null, '2', PriceCategory::Eingestellt],
    'GT-1 synthetisch: NULL/0 → datenluecke' => [null, '0', PriceCategory::Datenluecke],
    'GT-1 LA 31303090: −9.0/0 → service_charge (price<0 VOR status)' => [-9.0, '0', PriceCategory::ServiceCharge],
    'unbekannter Status' => [1.0, '7', PriceCategory::Unbekannt],
]);

it('istAktiv nur für standard_ek und aktion (I3)', function () {
    expect(PriceCategory::StandardEk->istAktiv())->toBeTrue()
        ->and(PriceCategory::Aktion->istAktiv())->toBeTrue()
        ->and(PriceCategory::ServiceCharge->istAktiv())->toBeFalse()
        ->and(PriceCategory::Eingestellt->istAktiv())->toBeFalse()
        ->and(PriceCategory::Datenluecke->istAktiv())->toBeFalse();
});
