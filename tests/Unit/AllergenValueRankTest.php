<?php

use Platform\FoodAlchemist\Enums\AllergenValue;

/**
 * M0-05-Beispieltest (Harness-Smoke, Ebene a: Golden-Unit ohne DB).
 * Fixiert die GL-01 §4.1 Merge-Rangordnung: enthalten > spuren >
 * nicht_enthalten > unbekannt — unbekannt ist der NIEDRIGSTE Rang
 * (Rust-Kommentar Z. 6961 behauptet das Gegenteil; Code/Regelwerk gewinnen).
 */
it('ordnet die Allergen-Merge-Ränge nach GL-01 §4.1', function () {
    expect(AllergenValue::Enthalten->rank())->toBe(3)
        ->and(AllergenValue::Spuren->rank())->toBe(2)
        ->and(AllergenValue::NichtEnthalten->rank())->toBe(1)
        ->and(AllergenValue::Unbekannt->rank())->toBe(0);
});

it('hat unbekannt als niedrigsten Rang (ALL-MAXIMAL-Voraussetzung)', function () {
    $ranks = array_map(fn (AllergenValue $v) => $v->rank(), AllergenValue::cases());

    expect(min($ranks))->toBe(AllergenValue::Unbekannt->rank());
});
