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

it('trägt die fa-pass-Skala: Tabelle 13 px, Labels 12 px normal geschrieben, keine Großbuchstaben-Labels', function () {
    // fa-pass Welle 0 (2026-10-05): die 10-px-Großbuchstaben-Labels der Jarvis-Skala (R14) sind abgelöst —
    // Mindestgröße 12 px, Labels normal geschrieben (Lesbarkeit an der Ausgabe).
    $maps = Ui::maps();

    expect($maps['table'])->toContain('text-[13px]')
        ->and($maps['td'])->toContain('px-3')
        ->and($maps['th'])->toContain('whitespace-nowrap')->toContain('text-[12px]')
        ->and($maps['input'])->toContain('text-[13px]')
        ->and($maps['dt'])->toContain('text-[12px]')->not->toContain('uppercase')
        ->and($maps['label'])->toContain('text-[12px]')->not->toContain('uppercase');
});

it('kennt GP- UND Rezept-/Gericht-Status als einheitliche Pills (#4)', function () {
    // #4 (Dominique 2026-08-27): statusPill ist bewusst über GP + Rezept/Gericht vereinheitlicht —
    // gleiche Farben je Rolle (grau in Arbeit · orange Review · grün freigegeben · rot abgelehnt/veraltet).
    expect(array_keys(Ui::maps()['statusPill']))
        ->toBe(['approved', 'tentative', 'rejected', 'merged', 'draft', 'review', 'deprecated', 'stub']);
    // Review muss orange sein (der ursprüngliche Bug: Review erschien grau).
    expect(Ui::maps()['statusPill']['review'])->toContain('amber');
});
