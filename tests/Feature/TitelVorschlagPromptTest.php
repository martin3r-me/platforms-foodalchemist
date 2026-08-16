<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Et.4 (Eingabe-Reife) »Titel-/Namensvorschlag aus dem Brief« — erster Baustein ist der Contract:
 * zwei nüchterne Titel-aus-Brief-Prompts, gespiegelt an der `name_putzen`-Ebenen-Trennung
 * (Basisrezept = §1-Syntax, Gericht = Pipe-Syntax §4.4). Concept bleibt bewusst außen vor
 * (kreativer `name_claim` via `concept.plan` ≠ nüchtern). Reiner Contract-Test — die
 * Service-Verdrahtung + der UI-Knopf folgen in den nächsten Chunks.
 */

it('recipe.titel_vorschlag ist als Tier-B-Prompt mit Task registriert', function () {
    $p = config('foodalchemist.prompts', [])['recipe.titel_vorschlag'] ?? null;

    expect($p)->toBeArray()
        ->and($p['tier'] ?? null)->toBe('B')
        ->and($p['task'] ?? '')->not->toBe('');
});

it('vk.titel_vorschlag ist als Tier-B-Prompt mit Task registriert', function () {
    $p = config('foodalchemist.prompts', [])['vk.titel_vorschlag'] ?? null;

    expect($p)->toBeArray()
        ->and($p['tier'] ?? null)->toBe('B')
        ->and($p['task'] ?? '')->not->toBe('');
});

it('recipe.titel_vorschlag fordert §1-Syntax + name-Feld, nüchtern (keine Marketing-Adjektive)', function () {
    $task = config('foodalchemist.prompts', [])['recipe.titel_vorschlag']['task'] ?? '';

    // §1-Basisrezept-Syntax + Ausgabe-Feld
    expect($task)->toContain('§1')
        ->and($task)->toContain('Typ')
        ->and($task)->toContain('werte = {name}');
    // Nüchtern + keine Erfindung (Grundsatz: nur benennen, was der Brief hergibt)
    expect($task)->toContain('keine Marketing-Adjektive')
        ->and($task)->toContain('Brief');
});

it('vk.titel_vorschlag fordert §4.4-Pipe-Syntax + name-Feld, nüchtern (keine Marketing-Adjektive)', function () {
    $task = config('foodalchemist.prompts', [])['vk.titel_vorschlag']['task'] ?? '';

    // §4.4-Gericht-Pipe-Syntax + Ausgabe-Feld
    expect($task)->toContain('§4.4')
        ->and($task)->toContain('HG-Code')
        ->and($task)->toContain('werte = {name}');
    // Nüchtern + keine Erfindung
    expect($task)->toContain('keine Marketing-Adjektive')
        ->and($task)->toContain('Brief');
});
