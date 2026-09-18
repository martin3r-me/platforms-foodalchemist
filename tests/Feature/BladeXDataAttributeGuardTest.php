<?php

use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Live-Bruch 2026-09-18 (zweimal am selben Tag, in ZWEI verschiedenen Dateien): eigene
 * JS-Kommentarzeilen INNERHALB von `x-data="…"`/`x-init="…"` enthielten geradeaus-
 * Anführungszeichen (") — ein HTML-Attribut endet beim ERSTEN `"`, ganz gleich ob er aus
 * Blade/PHP oder rohem Kommentartext stammt. Das Attribut riss dort ab, Alpine bekam nur
 * noch ein Bruchstück-Objekt und fiel für das GANZE Modal/den schwebenden Knopf aus (Code
 * als Klartext sichtbar bzw. Element komplett unsichtbar).
 *
 * 148 grüne Tests VOR diesem Bruch hatten das NICHT gesehen: `Livewire::test()->html()` und
 * `Blade::render()` prüfen Textinhalt/Marker, kein Attribut-Parsing wie ein echter Browser.
 * Dieser Wächter tut GENAU das — ein HTML-Attribut-Parser in Miniatur — und läuft über JEDE
 * Blade-Datei des Moduls, nicht nur die beiden bisher getroffenen.
 *
 * REGEL für neuen `x-data`/`x-init`-Code: NIE `"` im JS/Kommentar innerhalb des Attributs —
 * Backticks (`) oder Guillemets (»«) statt Anführungszeichen. `{{ }}`/`@js()`-Ausgaben sind
 * SICHER (escapen automatisch), reiner Kommentartext/hartkodiertes JS ist es NICHT.
 */
it('WÄCHTER (modulweit): jedes x-data="…"/x-init="…" in jeder Blade-Datei ist syntaktisch vollständig', function () {
    $basis = __DIR__ . '/../../resources/views';
    $dateien = collect(File::allFiles($basis))
        ->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php'));

    expect($dateien)->not->toBeEmpty('keine Blade-Dateien gefunden — Test misst nichts');

    $gepruefteAttribute = 0;
    $fehlend = [];

    foreach ($dateien as $datei) {
        $inhalt = file_get_contents($datei->getPathname());
        foreach (['x-data', 'x-init'] as $attribut) {
            if (preg_match_all('/' . preg_quote($attribut, '/') . '="([^"]*)"/s', $inhalt, $treffer) === 0) {
                continue;
            }
            foreach ($treffer[1] as $wert) {
                $gepruefteAttribute++;
                $getrimmt = rtrim($wert);
                // Ein abgerissenes Attribut endet mitten in einem Kommentar/Bezeichner/Wort —
                // nie auf einer schliessenden JS-Klammer oder einem Semikolon. Leer ist erlaubt
                // (z. B. ein Platzhalter-`x-init=""`).
                $gueltig = $getrimmt === ''
                    || str_ends_with($getrimmt, '}')
                    || str_ends_with($getrimmt, ')')
                    || str_ends_with($getrimmt, ';');
                if (! $gueltig) {
                    $fehlend[] = $datei->getRelativePathname() . " ({$attribut}, endet auf '"
                        . mb_substr($getrimmt, -1) . "'): …" . mb_substr($getrimmt, -150);
                }
            }
        }
    }

    // Riegel gegen einen wirkungslosen Test: die Regex muss wirklich etwas gefunden haben.
    expect($gepruefteAttribute)->toBeGreaterThan(30);
    expect($fehlend)->toBe([], "Abgerissenes Attribut gefunden:\n" . implode("\n", $fehlend));
});
