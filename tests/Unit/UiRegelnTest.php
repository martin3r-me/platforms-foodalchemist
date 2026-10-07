<?php

/**
 * fa-pass Welle 0 — Design-Regeln als Sperrklinke.
 *
 * Warum: Die Oberfläche war in 234 Views auseinandergelaufen (536 Hex-Farben, 16 Schriftgrößen,
 * 290 Emoji, 43 × !important). Bausteine allein halten das nicht auf — dieser Test tut es.
 *
 * Mechanik:
 *   - Bausteine unter components/fa/ müssen JEDE Regel zu 100 % einhalten.
 *   - Alle anderen Views dürfen nicht SCHLECHTER werden als ihr eingefrorener Stand
 *     (tests/Fixtures/ui_regeln_baseline.json). Neue Dateien starten bei null.
 *   - Wird eine View umgebaut, sinken ihre Zähler — danach den Stand neu einfrieren:
 *       FA_UI_BASELINE_SCHREIBEN=1 vendor/bin/pest --filter=UiRegeln
 *     So wird die Klinke mit jeder Welle enger, nie lockerer.
 */

use Platform\FoodAlchemist\Tests\Support\UiRegeln;

it('hält die Bausteine unter components/fa vollständig regelkonform', function () {
    $verstoesse = array_filter(UiRegeln::zaehlen(), fn ($_, $pfad) => str_starts_with($pfad, 'components/fa/'), ARRAY_FILTER_USE_BOTH);

    expect($verstoesse)->toBe([], 'Bausteine verletzen Design-Regeln: ' . json_encode($verstoesse, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
});

it('lässt keine View schlechter werden als ihren eingefrorenen Stand', function () {
    $ist = UiRegeln::zaehlen();

    if (getenv('FA_UI_BASELINE_SCHREIBEN') === '1') {
        file_put_contents(UiRegeln::baselinePfad(), json_encode($ist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $this->markTestSkipped('Stand neu eingefroren: ' . count($ist) . ' Dateien mit Altlasten.');
    }

    $soll = json_decode((string) @file_get_contents(UiRegeln::baselinePfad()), true) ?? [];
    $schlechter = [];
    foreach ($ist as $pfad => $regeln) {
        foreach ($regeln as $regel => $anzahl) {
            $erlaubt = $soll[$pfad][$regel] ?? 0;
            if ($anzahl > $erlaubt) {
                $schlechter[] = "{$pfad}: {$regel} {$anzahl} (erlaubt {$erlaubt})";
            }
        }
    }

    expect($schlechter)->toBe([], "Design-Regeln verletzt — Token/Baustein nutzen statt Einzelwert:\n" . implode("\n", $schlechter));
});
