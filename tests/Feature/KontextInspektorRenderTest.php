<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * Kontext-Inspektor (2026-08-07): Blade-Render-Absicherung der Transparenz-Komponente
 * „auf welches Wissen greift der Generator". Prüft Gruppierung, Präfix-/Versions-Bereinigung
 * und den fail-safe null/leer-Pfad (Panel verschwindet, kein Blade-/PHP-Fehler).
 */
uses(TestCase::class);

it('rendert das Wissen gruppiert je Kanal + bereinigt Präfixe/Versionen', function () {
    $k = [
        'wissen' => [
            'cross_cutting' => ['substitutionen@v1', 'saisonkalender@v2'],
            'domain' => ['fisch_seafood@v1'],
            'niveau' => ['niveau.niveau-1-haute-cuisine@v1'],
            'pairing' => ['graph:tomate', 'graph:basilikum'],
        ],
        'chars' => 12345,
        'templates' => [['id' => 1, 'name' => 'Vinaigrette-Basis']],
    ];

    $html = Blade::render('<x-foodalchemist::kontext-inspektor :kontext="$kontext" />', ['kontext' => $k]);

    expect($html)->toContain('Verwendetes Wissen')
        ->toContain('Cross-Cutting')->toContain('Domänen')->toContain('Niveau')->toContain('Pairing-Anker')
        ->toContain('>tomate<')            // graph:-Präfix entfernt
        ->toContain('>substitutionen<')    // @vN am Chip abgeschnitten
        ->toContain('Vinaigrette-Basis')   // gematchtes Template
        ->toContain('12.345')              // Zeichen-Budget, dt. formatiert
        ->not->toContain('graph:tomate')   // Präfix nicht mehr roh sichtbar
        ->not->toContain('@v1');           // Version wird am Chip gekappt
});

it('ist fail-safe: null oder leerer Kontext rendert nichts (Panel verschwindet)', function () {
    expect(trim(Blade::render('<x-foodalchemist::kontext-inspektor :kontext="$kontext" />', ['kontext' => null])))->toBe('');
    expect(trim(Blade::render('<x-foodalchemist::kontext-inspektor :kontext="$kontext" />', ['kontext' => ['wissen' => [], 'templates' => [], 'chars' => 0]])))->toBe('');
});

it('beide Generator-Modals kompilieren mit dem eingehängten Inspektor (kein Blade-ParseError)', function () {
    $basis = __DIR__ . '/../../resources/views';
    foreach ([
        'livewire/recipes/generator-modal.blade.php',
        'livewire/verkauf/vk-generator-modal.blade.php',
    ] as $rel) {
        $md = (string) file_get_contents("{$basis}/{$rel}");
        $kompilat = Blade::compileString($md);   // wirft bei fehlbalancierten Direktiven / kaputtem Component-Tag
        expect($kompilat)->toContain('kontext-inspektor');
    }
});
