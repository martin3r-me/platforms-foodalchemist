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

it('trägt die Jarvis-Skala (R14): Tabelle 12px, Zellen py-1/px-3, Header einzeilig, Labels 10px', function () {
    $maps = Ui::maps();

    expect($maps['table'])->toContain('text-xs')
        ->and($maps['td'])->toContain('py-1')->toContain('px-3')
        ->and($maps['th'])->toContain('whitespace-nowrap')
        ->and($maps['input'])->toContain('text-xs')
        ->and($maps['dt'])->toContain('text-[10px]')->toContain('uppercase')
        ->and($maps['label'])->toContain('uppercase tracking-wider');
});

it('kennt GP- UND Rezept-/Gericht-Status als einheitliche Pills (#4)', function () {
    // #4 (Dominique 2026-08-27): statusPill ist bewusst über GP + Rezept/Gericht vereinheitlicht —
    // gleiche Farben je Rolle (grau in Arbeit · orange Review · grün freigegeben · rot abgelehnt/veraltet).
    expect(array_keys(Ui::maps()['statusPill']))
        ->toBe(['approved', 'tentative', 'rejected', 'merged', 'draft', 'review', 'deprecated', 'stub']);
    // Review muss orange sein (der ursprüngliche Bug: Review erschien grau).
    expect(Ui::maps()['statusPill']['review'])->toContain('amber');
});
