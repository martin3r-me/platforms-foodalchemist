<?php

use Illuminate\Support\Facades\Log;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Support\ToolListe;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 84 C3a · Tool-Liste als eigene Datei (`src/Tools/_liste.php`) statt 605 Zeilen im ServiceProvider.
 */
it('Spec 84 C3a · Liste ist alphabetisch und ohne Dubletten', function () {
    $klassen = ToolListe::klassen();
    $sortiert = $klassen;
    usort($sortiert, fn ($a, $b) => strcasecmp($a, $b));

    expect($klassen)->toBe($sortiert, 'Bitte alphabetisch einsortieren (eine Zeile je Tool)')
        ->and(array_values(array_unique($klassen)))->toBe($klassen);
});

it('Spec 84 C3a · jede konkrete Tool-Klasse in src/Tools steht in der Liste, jede Listen-Zeile ist ein Tool', function () {
    $dir = dirname(__DIR__, 2) . '/src/Tools';
    $konkret = [];
    foreach (glob($dir . '/*.php') as $datei) {
        $klasse = 'Platform\\FoodAlchemist\\Tools\\' . basename($datei, '.php');
        if (! class_exists($klasse)) {
            continue;   // _liste.php
        }
        $r = new ReflectionClass($klasse);
        if (! $r->isAbstract() && $r->implementsInterface(ToolContract::class) && $klasse !== \Platform\FoodAlchemist\Tools\FaRechteToolHuelle::class) {
            $konkret[] = $klasse;
        }
    }
    $liste = array_map(fn ($k) => ltrim($k, '\\'), ToolListe::klassen());

    expect(array_values(array_diff($konkret, $liste)))->toBe([], 'Tool-Klasse fehlt in src/Tools/_liste.php')
        ->and(array_values(array_diff($liste, $konkret)))->toBe([], 'Listen-Zeile ohne konkrete Tool-Klasse');
});

it('Spec 84 C3a · keine zwei Tool-Klassen mit demselben Namen (sonst entscheidet die Reihenfolge still den Gewinner)', function () {
    $namen = [];
    foreach (ToolListe::klassen() as $klasse) {
        $namen[(new $klasse())->getName()][] = $klasse;
    }

    expect(array_filter($namen, fn ($k) => count($k) > 1))->toBe([]);
});

it('Spec 84 C3a · nach dem Boot sind alle Tools der Liste registriert, jedes in der Rechte-Hülle', function () {
    $registry = app(ToolRegistry::class);
    $fehlt = [];
    foreach (ToolListe::klassen() as $klasse) {
        $name = (new $klasse())->getName();
        $tool = $registry->get($name);
        if (! $tool instanceof \Platform\FoodAlchemist\Tools\FaRechteToolHuelle) {
            $fehlt[] = $name;
        }
    }

    expect($fehlt)->toBe([]);
});

it('Spec 84 C3a · ein kaputtes Tool reißt den Boot nicht, landet aber mit Klasse und Fehler im Log', function () {
    Log::spy();
    $registry = new ToolRegistry();

    ToolListe::registriere($registry, ['Platform\\FoodAlchemist\\Tools\\GibtEsNicht', \Platform\FoodAlchemist\Tools\GpsGetTool::class]);

    expect($registry->has((new \Platform\FoodAlchemist\Tools\GpsGetTool())->getName()))->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg) => str_contains($msg, 'GibtEsNicht') && str_contains($msg, 'Error'));
});

it('Spec 84 C3a · ein schon registrierter Name bleibt unangetastet (idempotent)', function () {
    $registry = new ToolRegistry();
    ToolListe::registriere($registry, [\Platform\FoodAlchemist\Tools\GpsGetTool::class]);
    $erst = $registry->get('foodalchemist.gps.GET');
    ToolListe::registriere($registry, [\Platform\FoodAlchemist\Tools\GpsGetTool::class]);

    expect($registry->get('foodalchemist.gps.GET'))->toBe($erst);
});
