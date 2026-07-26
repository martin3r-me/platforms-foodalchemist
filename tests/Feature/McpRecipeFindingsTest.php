<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFinding;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S5a — MCP-Lockstep zur neuen Befund-Ablage.
 *
 * Zwei Dinge zählen hier (die Fach-Logik hängt in RecipeFindingServiceTest):
 *  1. Der Lese-Weg kostet keinen Provider-Call und ist team-dicht — ein Befund ist
 *     ein Urteil über ein Rezept und darf die Team-Grenze nicht überqueren (#504).
 *  2. Der Schreib-Weg entscheidet nur über den BEFUND, nicht über das Rezept.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    $this->recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 's5a-mcp', 'name' => 'Kartoffelpüree', 'status' => 'approved',
    ]);

    $this->befund = fn (array $extra = []) => FoodAlchemistRecipeFinding::create([
        ...['team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id, 'kind' => 'menge',
            'ingredient_text' => 'Butter', 'quantity' => 120, 'unit_slug' => 'g', 'reason' => 'Zu wenig Fett.',
            'confidence' => 0.85, 'auto_applicable' => true, 'applicability' => 'anwendbar', 'status' => 'offen',
            'fingerprint' => sha1('menge|butter'), 'seen_count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()],
        ...$extra,
    ]);
});

it('S5a: beide Tools sind registriert; SEARCH liest read-only, PUT schreibt', function () {
    $search = $this->registry->get('foodalchemist.recipe_findings.SEARCH');
    $put = $this->registry->get('foodalchemist.recipe_findings.PUT');

    expect($search)->not->toBeNull()->and($put)->not->toBeNull()
        ->and($search->getMetadata()['read_only'])->toBeTrue()
        ->and($put->getMetadata()['read_only'])->toBeFalse()
        ->and($put->getSchema()['required'])->toBe(['finding_id', 'status']);
});

it('S5a: SEARCH liefert offene Befunde inkl. Signal-Kandidaten-Zähler', function () {
    ($this->befund)();
    ($this->befund)(['kind' => 'hinweis', 'confidence' => 0.4, 'auto_applicable' => false,
        'applicability' => 'nur_hinweis', 'fingerprint' => sha1('hinweis|salz'), 'ingredient_text' => null,
        'reason' => 'Salz nicht dosiert.']);
    ($this->befund)(['status' => 'verworfen', 'fingerprint' => sha1('entfernen|butter'), 'kind' => 'entfernen']);

    $res = $this->registry->get('foodalchemist.recipe_findings.SEARCH')->execute([], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['total'])->toBe(2)                            // verworfen ist per Default draussen
        // S5b-2: zwei Zähler, getrennt nach Pass — der Bauart-Zweifel ist ein eigenes Signal.
        ->and($res->data['signal_kandidaten'])->toBe(['copilot' => 1, 'bauart' => 0]) // nur der über der Schwelle
        ->and($res->data['befunde'][0]['kind'])->toBe('menge')         // nach Konfidenz sortiert
        ->and($res->data['befunde'][0]['ebene'])->toBe('basisrezept');

    $gefiltert = $this->registry->get('foodalchemist.recipe_findings.SEARCH')
        ->execute(['min_confidence' => 0.8], $this->kontext);
    expect($gefiltert->data['total'])->toBe(1);
});

it('S5a: PUT stellt einen Befund ruhig, ohne das Rezept anzufassen', function () {
    $zeile = ($this->befund)();
    $rezeptVorher = $this->recipe->fresh()->toArray();

    $res = $this->registry->get('foodalchemist.recipe_findings.PUT')
        ->execute(['finding_id' => $zeile->id, 'status' => 'verworfen'], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['status'])->toBe('verworfen')
        ->and($zeile->refresh()->decided_at)->not->toBeNull()
        ->and($this->recipe->fresh()->toArray())->toEqual($rezeptVorher);

    $ungueltig = $this->registry->get('foodalchemist.recipe_findings.PUT')
        ->execute(['finding_id' => $zeile->id, 'status' => 'offen'], $this->kontext);
    expect($ungueltig->success)->toBeFalse()->and($ungueltig->errorCode)->toBe('VALIDATION_ERROR');
});

it('#504-Muster: ein fremder Befund ist weder lesbar noch entscheidbar', function () {
    $zeile = ($this->befund)();

    $fremd = new ToolContext($this->makeUser($this->childB, 'Kind B User'), $this->childB);

    $lesen = $this->registry->get('foodalchemist.recipe_findings.SEARCH')->execute([], $fremd);
    expect($lesen->success)->toBeTrue()->and($lesen->data['total'])->toBe(0);   // strikt team_id, nicht geerbt

    $schreiben = $this->registry->get('foodalchemist.recipe_findings.PUT')
        ->execute(['finding_id' => $zeile->id, 'status' => 'verworfen'], $fremd);
    expect($schreiben->success)->toBeFalse()->and($schreiben->errorCode)->toBe('NOT_FOUND')
        ->and($zeile->refresh()->status)->toBe('offen');
});
