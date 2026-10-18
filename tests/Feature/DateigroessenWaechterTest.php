<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 85 §4 · Größen-Wächter.
 *
 * Keine PHP-Datei unter `src/` und `resources/views/` (Blade eingeschlossen) wird größer als 1.500 Zeilen.
 * Ältere große Dateien stehen mit ihrer Zeilenzahl in `tests/Support/DateigroessenBasis.php`. Rot, wenn
 *
 *   1. eine Datei über der Grenze liegt und nicht im Bestand steht → schneiden (Spec 85 §1) statt eintragen,
 *   2. eine Datei aus dem Bestand über ihre Basis wächst,
 *   3. eine Datei aus dem Bestand geschrumpft ist, die Basis aber nicht → Basis auf den Ist-Wert senken
 *      (Sperrklinke: jeder Schnitt-PR senkt die Basis mit),
 *   4. ein Eintrag auf eine fehlende Datei oder eine Datei unter der Grenze zeigt → Eintrag entfernen.
 */
const SPEC85_GRENZE = 1500;

/** @return array<string, int> Pfad (relativ zum Modul) → Zeilen (Zeilenumbrüche wie `wc -l`) */
function spec85_zeilen(): array
{
    $modul = dirname(__DIR__, 2);
    $ist = [];
    foreach (['src', 'resources/views'] as $ordner) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modul . '/' . $ordner, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'php') {
                $ist[substr($datei->getPathname(), strlen($modul) + 1)] = substr_count(file_get_contents($datei->getPathname()), "\n");
            }
        }
    }
    ksort($ist);

    return $ist;
}

/**
 * @param  array<string, int>  $ist
 * @param  array<string, array{0: int, 1: string}>  $basis
 * @return list<string>
 */
function spec85_befunde(array $ist, array $basis, int $grenze): array
{
    $befunde = [];
    foreach ($ist as $pfad => $n) {
        if ($n > $grenze && ! isset($basis[$pfad])) {
            $befunde[] = "{$pfad}: {$n} Zeilen > {$grenze} und nicht im Bestand — schneiden (Spec 85 §1), nicht eintragen";
        }
    }
    foreach ($basis as $pfad => [$soll, $grund]) {
        $n = $ist[$pfad] ?? null;
        if ($n === null) {
            $befunde[] = "{$pfad}: Datei fehlt — Eintrag aus dem Bestand entfernen";
        } elseif ($n <= $grenze) {
            $befunde[] = "{$pfad}: {$n} Zeilen ≤ {$grenze} — Eintrag aus dem Bestand entfernen";
        } elseif ($n > $soll) {
            $befunde[] = "{$pfad}: {$n} Zeilen, Basis {$soll} (+" . ($n - $soll) . ") — nicht wachsen lassen; Ziel: {$grund}";
        } elseif ($n < $soll) {
            $befunde[] = "{$pfad}: {$n} Zeilen, Basis {$soll} — Basis auf {$n} senken (Sperrklinke)";
        }
    }

    return $befunde;
}

it('Spec 85 §4 · Größen-Wächter: keine Datei über 1.500 Zeilen außer dem Bestand, Bestand wächst nicht und folgt jedem Schnitt', function () {
    $basis = require dirname(__DIR__) . '/Support/DateigroessenBasis.php';
    $befunde = spec85_befunde(spec85_zeilen(), $basis, SPEC85_GRENZE);

    expect($befunde)->toBe([], 'Größen-Wächter (Spec 85 §4):' . PHP_EOL . implode(PHP_EOL, $befunde));
});

it('Spec 85 §4 · Größen-Wächter erkennt alle vier Fälle (Selbsttest)', function () {
    $basis = [
        'gleich.php' => [2000, 'Ziel A'],
        'gewachsen.php' => [2000, 'Ziel B'],
        'geschrumpft.php' => [2000, 'Ziel C'],
        'unter_grenze.php' => [2000, 'Ziel D'],
        'fehlt.php' => [2000, 'Ziel E'],
    ];
    $ist = [
        'gleich.php' => 2000,
        'gewachsen.php' => 2001,
        'geschrumpft.php' => 1900,
        'unter_grenze.php' => 1400,
        'neu_gross.php' => 1501,
        'neu_klein.php' => 1500,
    ];

    $befunde = implode(PHP_EOL, spec85_befunde($ist, $basis, 1500));

    expect($befunde)->toContain('neu_gross.php: 1501 Zeilen > 1500 und nicht im Bestand')
        ->toContain('gewachsen.php: 2001 Zeilen, Basis 2000 (+1)')
        ->toContain('Ziel B')
        ->toContain('geschrumpft.php: 1900 Zeilen, Basis 2000 — Basis auf 1900 senken')
        ->toContain('unter_grenze.php: 1400 Zeilen ≤ 1500 — Eintrag aus dem Bestand entfernen')
        ->toContain('fehlt.php: Datei fehlt')
        ->not->toContain('gleich.php')
        ->not->toContain('neu_klein.php')
        // Der echte Scan findet die Modul-Dateien (sonst wäre der Wächter still blind).
        ->and(count(spec85_zeilen()))->toBeGreaterThan(500)
        ->and(spec85_zeilen())->toHaveKey('src/Livewire/Planung/Index.php');
});
