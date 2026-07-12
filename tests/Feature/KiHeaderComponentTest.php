<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * M0-09: Baustein ki-header (P-3) — rendert alle 3 Quellen-Zustände (GL-07 §4.1)
 * und verdrahtet das GL-07-Quadrupel als wire:click-Vertrag (ai_/accept_/clear_/manual_).
 */
it('rendert Zustand unbefüllt: kein Reset, keine Konfidenz, Autopilot verdrahtet', function () {
    // „Manuell"-Button entfernt (Dominique 2026-07-01): redundant — Editieren+Speichern setzt source=manual ohnehin
    $html = Blade::render('<x-foodalchemist::ki-header label="Tags" field="tags" />');

    expect($html)->toContain('data-source="leer"')
        ->and($html)->toContain('unbefüllt')
        ->and($html)->toContain('wire:click="ai_tags"')
        ->and($html)->not->toContain('wire:click="manual_tags"')
        ->and($html)->not->toContain('wire:click="clear_tags"')
        ->and($html)->not->toContain('data-ki-confidence');
});

it('rendert Zustand ki: KI-Badge, Konfidenz %, Begründung als Tooltip, Reset verdrahtet', function () {
    $html = Blade::render('<x-foodalchemist::ki-header label="Tags" field="tags" source="ki" :confidence="0.92" reasoning="Aggregiert aus LAs" />');

    expect($html)->toContain('data-source="ki"')
        ->and($html)->toContain('>KI</span>')
        ->and($html)->toContain('92%')
        ->and($html)->toContain('Aggregiert aus LAs')
        ->and($html)->toContain('wire:click="clear_tags"');
});

it('rendert Zustand manual: Manuell-Badge ohne Konfidenz, Reset bleibt möglich', function () {
    $html = Blade::render('<x-foodalchemist::ki-header label="Tags" field="tags" source="manual" />');

    expect($html)->toContain('data-source="manual"')
        ->and($html)->toContain('>Manuell</span>')
        ->and($html)->not->toContain('data-ki-confidence')
        ->and($html)->toContain('wire:click="clear_tags"'); // clear setzt auch manual zurück (GL-07 §4.1)
});

it('zeigt Übernehmen nur bei anstehendem Vorschlag (accept_-Vertrag)', function () {
    $ohne = Blade::render('<x-foodalchemist::ki-header label="Tags" field="tags" />');
    $mit = Blade::render('<x-foodalchemist::ki-header label="Tags" field="tags" :has-proposal="true" />');

    expect($ohne)->not->toContain('wire:click="accept_tags"')
        ->and($mit)->toContain('wire:click="accept_tags"')
        ->and($mit)->toContain('Übernehmen');
});
