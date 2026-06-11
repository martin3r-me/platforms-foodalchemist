<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * M0-10: Baustein tri-state (P-4/GL-01) — 4-Wert-Modell mit einem Array-Binding.
 * Echtes Togglen (Alpine) prüft der Sandbox-Browser-Check.
 */
it('rendert je Zeile drei Toggle-Buttons mit unbekannt-Fallback', function () {
    $html = Blade::render('<x-foodalchemist::tri-state :items="[\'eier\' => \'Eier\', \'milch\' => \'Milch\']" />');

    expect($html)->toContain('data-tri-row="eier"')
        ->and($html)->toContain('data-tri-row="milch"')
        ->and(substr_count($html, 'data-tri-btn="nicht_enthalten"'))->toBe(2)
        ->and(substr_count($html, 'data-tri-btn="spuren"'))->toBe(2)
        ->and(substr_count($html, 'data-tri-btn="enthalten"'))->toBe(2)
        // erneuter Klick auf aktiven Wert toggelt zurück auf unbekannt (4. Zustand)
        ->and($html)->toContain("=== 'enthalten' ? 'unbekannt' : 'enthalten'");
});

it('initialisiert fehlende Keys als unbekannt und übernimmt values', function () {
    $html = Blade::render('<x-foodalchemist::tri-state :items="[\'eier\' => \'Eier\', \'senf\' => \'Senf\']" :values="[\'eier\' => \'spuren\']" />');

    // exakt dieselbe Kodierung wie die Komponente (Js::from) erwarten
    $erwartet = (string) \Illuminate\Support\Js::from(['eier' => 'spuren', 'senf' => 'unbekannt']);
    expect($html)->toContain($erwartet);
});

it('bindet im Livewire-Modus genau ein entangle aufs Array', function () {
    $html = Blade::render('<x-foodalchemist::tri-state model="allergene" :items="[\'eier\' => \'Eier\']" />');

    expect($html)->toContain("\$wire.entangle('allergene')")
        ->and(substr_count($html, 'entangle'))->toBe(1);
});

it('read-only: Buttons disabled, kein Klick-Handler', function () {
    $html = Blade::render('<x-foodalchemist::tri-state readonly :items="[\'eier\' => \'Eier\']" :values="[\'eier\' => \'enthalten\']" />');

    expect($html)->toContain('disabled')
        ->and($html)->not->toContain('@click');
});
