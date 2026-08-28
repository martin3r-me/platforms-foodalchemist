<?php

use Platform\FoodAlchemist\Support\Ui;

/**
 * #4 (Dominique 2026-08-27): einheitliche Status-Ampel. Vorher fehlten die Rezept-Status-Keys in
 * `statusPill` → Review fiel auf secondary/grau zurück (die „veraltete" Farbe). Diese Zuordnung
 * fixiert: Entwurf grau · Review orange · Freigegeben grün · Veraltet rot · (GP) abgelehnt rot.
 */
it('statusPill deckt GP- UND Rezept-Status mit der richtigen Ampel ab', function () {
    $p = Ui::maps()['statusPill'];

    // Freigegeben / approved — grün (geteilt GP + Rezept)
    expect($p['approved'])->toContain('emerald')
        // Review — orange (war vorher grau)
        ->and($p['review'])->toContain('amber')
        // Entwurf — grau
        ->and($p['draft'])->toContain('gray')
        // Veraltet — rot
        ->and($p['deprecated'])->toContain('red')
        // GP vorläufig — orange, abgelehnt — rot
        ->and($p['tentative'])->toContain('amber')
        ->and($p['rejected'])->toContain('red');
});
