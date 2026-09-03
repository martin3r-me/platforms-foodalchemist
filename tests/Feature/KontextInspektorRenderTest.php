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

/*
 * W3-5 (2026-09-03): der Inspektor zeigte NUR `chars` aus contextFor — also allein den
 * Retrieval-Topf. Gemessen sind das ~36.000 Zeichen, wo der Prompt ~77.500 hat: der Bound-Block
 * (das verbindliche Regelwerk, der GRÖSSTE Posten), Task, Hüllen und das Kontext-JSON fehlten in
 * der Anzeige komplett. Wer die Zahl las, unterschätzte den Prompt um mehr als die Hälfte — und
 * das ausgerechnet an dem Werkzeug, das Transparenz herstellen soll.
 *
 * Die Werte entstehen erst im Gateway (Messsonde `prompt_parts`) und werden nach dem Call über
 * die Call-Log-ID nachgezogen.
 */
it('zeigt die ECHTEN Prompt-Groessen, nicht nur den Retrieval-Anteil', function () {
    $k = [
        'wissen' => ['cross_cutting' => ['substitutionen@v1']],
        'chars' => 11000,     // nur Retrieval — die alte, irreführende Zahl
        'templates' => [],
        'prompt' => [
            'chars' => 51008, 'huelle' => 333, 'bound' => 28630, 'task' => 5024,
            'retrieval' => 11000, 'kontext' => 6021, 'dropped' => 13667,
            'tokens_in' => 16778, 'tokens_cached' => 3840,
        ],
    ];

    $html = Blade::render('<x-foodalchemist::kontext-inspektor :kontext="$kontext" />', ['kontext' => $k]);

    expect($html)->toContain('data-prompt-groessen')
        // Die Kopfzeile nennt jetzt den GANZEN Prompt, nicht den Retrieval-Anteil.
        ->toContain('Prompt 51.008 Zeichen')
        ->not->toContain('~11.000 Zeichen')
        // Der größte Posten war vorher unsichtbar.
        ->toContain('Regelwerk (verbindlich) 28.630')
        ->toContain('Kontext-JSON 6.021')
        ->toContain('Aufgabe 5.024')
        // `dropped` muss sichtbar sein: gebaut-und-weggeworfen ist die Größe, an der man den
        // Deckel überhaupt erst bemerkt.
        ->toContain('verworfen 13.667')
        // Und der Cache-Anteil, weil er über den Preis entscheidet (gecacht = 10 %).
        ->toContain('16.778 Token, 23 % aus dem Cache');
});

it('bleibt bei der alten Anzeige, wenn die Messsonde nichts liefert', function () {
    // Keine erfundenen Nullen: ohne Sonden-Daten zeigt der Inspektor weiter das Retrieval-Budget.
    // Eine Tabelle voller Nullen würde Wissen behaupten, das wir nicht haben.
    $k = ['wissen' => ['domain' => ['fisch_seafood@v1']], 'chars' => 11000, 'templates' => []];

    $html = Blade::render('<x-foodalchemist::kontext-inspektor :kontext="$kontext" />', ['kontext' => $k]);

    expect($html)->toContain('~11.000 Zeichen')
        ->not->toContain('data-prompt-groessen');
});
