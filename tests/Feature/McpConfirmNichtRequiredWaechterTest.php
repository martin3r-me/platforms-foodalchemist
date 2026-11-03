<?php

use Platform\FoodAlchemist\Support\ToolListe;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Wächter: `confirm` steht nie im Schema-`required`. Die Tool-Hülle (FaRechteToolHuelle) prüft `required` vor execute() und
 * meldet VALIDATION_ERROR; ein fehlendes `confirm` muss aber CONFIRM_REQUIRED aus dem Tool selbst ergeben (wie konfiguratoren.DELETE).
 */
it('kein FA-Tool führt confirm im Schema-required', function () {
    $verstoesse = [];
    foreach (ToolListe::klassen() as $klasse) {
        $tool = new $klasse();
        if (in_array('confirm', (array) ($tool->getSchema()['required'] ?? []), true)) {
            $verstoesse[] = $tool->getName();
        }
    }

    expect($verstoesse)->toBe([], 'confirm aus required nehmen, das Tool prüft selbst (CONFIRM_REQUIRED)');
});
