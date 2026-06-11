<?php

use Platform\FoodAlchemist\Support\Ui;

/**
 * M0-12: Dichte-Maps — eine Quelle für alle Content-Klassen.
 */
it('liefert alle Map-Schlüssel, die Views und Bausteine erwarten', function () {
    $maps = Ui::maps();

    foreach ([
        'card', 'cardAccent', 'input', 'label',
        'table', 'th', 'td', 'tr', 'row', 'dt', 'dd',
        'pill', 'statusPill', 'variantPill',
        'btnPrimary', 'btnGhost', 'btnGhostXs',
    ] as $key) {
        expect($maps)->toHaveKey($key);
    }
});

it('trägt die Ist-App-Dichte: Tabelle 13px, Zellen py-1.5', function () {
    $maps = Ui::maps();

    expect($maps['table'])->toContain('text-[13px]')
        ->and($maps['td'])->toContain('py-1.5')
        ->and($maps['label'])->toContain('uppercase tracking-wider');
});

it('kennt alle vier GP-Status als Pills', function () {
    expect(array_keys(Ui::maps()['statusPill']))->toBe(['approved', 'tentative', 'rejected', 'merged']);
});
