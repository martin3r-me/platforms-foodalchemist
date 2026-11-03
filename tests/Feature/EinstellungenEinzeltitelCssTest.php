<?php

/**
 * Abnahme E-4 (Dominique 24.10.): steht im Unter-Reiter nur EIN Abschnitt (Index::EINZELTITEL), entfällt dessen innerer Titel.
 * 03.11. im Browser gemessen: als Tailwind-Variante lag die Regel in `utilities.fa` und das Host-`.flex` des h3 gewann —
 * KI, Teamprofil, Zuschläge … zeigten den Titel doppelt. Der Test prüft die Ebene im gebauten CSS, nicht nur die Klasse.
 */
it('Einzeltitel-Regel steht ungeschichtet im Modul-CSS: als Tailwind-Variante lag sie in utilities.fa und verlor gegen das Host-.flex (03.11.)', function () {
    $dist = file_get_contents(__DIR__ . '/../../resources/dist/foodalchemist.css');
    $regel = '[data-settings-einzeltitel] section.fa-surface:not(section section section)>header h3';
    $pos = strpos($dist, $regel);
    expect($pos)->not->toBeFalse();

    // umschließende Blöcke bis zur Regel: kein @layer (sonst schlägt die Host-Utility sie wieder), @scope ist erlaubt
    $stapel = [];
    $kopfStart = 0;
    for ($i = 0; $i < $pos; $i++) {
        if ($dist[$i] === '{') {
            $stapel[] = trim(substr($dist, $kopfStart, $i - $kopfStart));
            $kopfStart = $i + 1;
        } elseif ($dist[$i] === '}') {
            array_pop($stapel);
            $kopfStart = $i + 1;
        } elseif ($dist[$i] === ';') {
            $kopfStart = $i + 1;
        }
    }
    expect(array_values(array_filter($stapel, fn ($k) => str_starts_with($k, '@layer'))))->toBe([])
        ->and(file_get_contents(__DIR__ . '/../../resources/views/livewire/settings/partials/bereich.blade.php'))->not->toContain('header_h3]:hidden');
});
