<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * M0-11: Baustein chips (P-5) — Add/Remove-Markup, Vokabular-Datalist, ★-Prefix.
 * Echtes Add/Remove via Livewire prüft der Sandbox-Browser-Check.
 */
it('rendert Chip-Template mit Remove, Add-Input und Vokabular-Datalist', function () {
    $html = Blade::render('<x-foodalchemist::chips :values="[\'apfel\']" :vocabular="[\'apfel\', \'birne\', \'zimt\']" />');

    expect($html)->toContain('data-chips')
        ->and($html)->toContain('data-chip-remove')      // ×-Button im Template
        ->and($html)->toContain('data-chip-add')          // „+ manuell…"-Input
        ->and($html)->toContain('chips.splice(i, 1)')     // Remove-Verhalten
        ->and($html)->toContain('keydown.enter.prevent')  // Enter fügt hinzu
        ->and(substr_count($html, '<option value='))->toBe(3)
        ->and($html)->toContain((string) \Illuminate\Support\Js::from(['apfel']));
});

it('verhindert Duplikate clientseitig (includes-Guard im add)', function () {
    $html = Blade::render('<x-foodalchemist::chips :values="[]" />');

    expect($html)->toContain('!this.chips.includes(v)');
});

it('zeigt ★-Prefix nur mit star-Prop', function () {
    $ohne = Blade::render('<x-foodalchemist::chips :values="[\'apfel\']" />');
    $mit = Blade::render('<x-foodalchemist::chips :values="[\'apfel\']" star />');

    expect($ohne)->not->toContain('★')
        ->and($mit)->toContain('★');
});

it('read-only: kein Add-Input, kein Remove-Button, keine Datalist', function () {
    $html = Blade::render('<x-foodalchemist::chips readonly :values="[\'apfel\']" :vocabular="[\'birne\']" />');

    expect($html)->toContain('data-chips')
        ->and($html)->not->toContain('data-chip-add')
        ->and($html)->not->toContain('data-chip-remove')
        ->and($html)->not->toContain('<option');
});

it('bindet im Livewire-Modus genau ein entangle aufs Array', function () {
    $html = Blade::render('<x-foodalchemist::chips model="anker" />');

    expect($html)->toContain("\$wire.entangle('anker')")
        ->and(substr_count($html, 'entangle'))->toBe(1);
});
