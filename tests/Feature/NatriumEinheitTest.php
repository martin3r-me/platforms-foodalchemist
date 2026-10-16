<?php

use Platform\FoodAlchemist\Services\Necta\NectaMapper;
use Platform\FoodAlchemist\Services\SupplierItemService;

/**
 * Natrium steht in FA in mg je 100 g: der Necta-Mapper rechnet Salz (g) × 400, die Aggregation (GL-08) Salz g = Natrium mg × 0,0025.
 * Das Label im Artikel-Modal sagte „g" und ließ Eingaben 1000-fach falsch lesen. Der Test hält Label und Mapper zusammen.
 */
it('das Natrium-Label im Artikel-Modal nennt mg — dieselbe Einheit, in der der Necta-Mapper Natrium speichert', function () {
    $zeile = (new NectaMapper())->naehrwerte(['supplierItemId' => 1, 'totalTableSalt' => 1.0]);   // 1 g Salz je 100 g

    expect(SupplierItemService::NAEHRWERT_FELDER['sodium'][1])->toBe('mg')
        ->and((float) $zeile['sodium'])->toBe(400.0)                                           // mg Natrium
        ->and((float) $zeile['sodium'] * 0.0025)->toBe(1.0);                                   // GL-08 zurück: g Salz — Einheit mg ist die, die rückrechnet
});
