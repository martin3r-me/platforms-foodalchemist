<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D3d: recipe_rollen.POST (KI-Rollen, Vorschlag/accept) + recipe_coherence.POST (judge/heber).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->vkId = $this->registry->get('foodalchemist.verkaufsrezepte.POST')->execute(['name' => 'HG: Rollen'], $this->kontext)->data['id'];
});

it('Registry-Smoke: recipe_rollen.POST + recipe_coherence.POST registriert', function () {
    foreach (['foodalchemist.recipe_rollen.POST', 'foodalchemist.recipe_coherence.POST'] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name);
        expect($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('recipe_rollen.POST: Vorschlag (ohne accept) + accept', function () {
    $vor = ($this->run)('foodalchemist.recipe_rollen.POST', ['recipe_id' => $this->vkId]);
    expect($vor->success)->toBeTrue('rollen: ' . ($vor->error ?? ''))->and($vor->data['accepted'])->toBeFalse();

    $acc = ($this->run)('foodalchemist.recipe_rollen.POST', ['recipe_id' => $this->vkId, 'accept' => true]);
    expect($acc->success)->toBeTrue()->and($acc->data['accepted'])->toBeTrue()->and($acc->data['uebernommen'])->toBeInt();
});

it('recipe_coherence.POST: ungültiger mode → VALIDATION_ERROR; judge/heber graceful bei FakeProvider', function () {
    expect(($this->run)('foodalchemist.recipe_coherence.POST', ['recipe_id' => $this->vkId, 'mode' => 'quatsch'])->errorCode)->toBe('VALIDATION_ERROR');

    // FakeProvider liefert kein Urteil → Service wirft → Tool gibt strukturiert VALIDATION_ERROR (kein 500).
    $judge = ($this->run)('foodalchemist.recipe_coherence.POST', ['recipe_id' => $this->vkId, 'mode' => 'judge']);
    expect($judge->success)->toBeFalse()->and($judge->errorCode)->toBe('VALIDATION_ERROR');
});

it('Guards: unbekanntes Rezept → NOT_FOUND; fremd → ACCESS_DENIED', function () {
    expect(($this->run)('foodalchemist.recipe_rollen.POST', ['recipe_id' => 999999])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.recipe_coherence.POST', ['recipe_id' => $this->vkId, 'mode' => 'judge'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});
