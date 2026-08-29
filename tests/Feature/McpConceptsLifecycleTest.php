<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D5a: Concepts-Lifecycle (PUT/STATUS/DUPLICATE/RECOMPUTE/PRICE_TARGET/SEKTOR/
 * TEMPLATE_SAVE/TEMPLATE_FORK) + GET-Modernisierung (price_display/price_mode).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->conceptId = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Testkonzept'])->id;
});

it('Registry-Smoke: concepts.PUT/STATUS/DUPLICATE/RECOMPUTE/PRICE_TARGET/SEKTOR/TEMPLATE_SAVE/TEMPLATE_FORK', function () {
    foreach (['PUT', 'STATUS', 'DUPLICATE', 'RECOMPUTE', 'PRICE_TARGET', 'SEKTOR', 'TEMPLATE_SAVE', 'TEMPLATE_FORK'] as $v) {
        expect($this->registry->get("foodalchemist.concepts.{$v}"))->not->toBeNull($v);
        expect($this->registry->get("foodalchemist.concepts.{$v}")->getSchema()['type'] ?? null)->toBe('object', $v);
    }
});

it('PUT + DUPLICATE + RECOMPUTE + PRICE_TARGET', function () {
    $put = ($this->run)('foodalchemist.concepts.PUT', ['id' => $this->conceptId, 'felder' => ['occasion' => 'Sommerfest', 'target_price_per_person' => 40]]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));

    $dup = ($this->run)('foodalchemist.concepts.DUPLICATE', ['id' => $this->conceptId]);
    expect($dup->success)->toBeTrue('dup: ' . ($dup->error ?? ''));

    expect(($this->run)('foodalchemist.concepts.RECOMPUTE', ['id' => $this->conceptId])->data['recomputed'])->toBeTrue();

    $pt = ($this->run)('foodalchemist.concepts.PRICE_TARGET', ['id' => $this->conceptId, 'target_price_per_person' => 45]);
    expect($pt->success)->toBeTrue('price_target: ' . ($pt->error ?? ''))->and($pt->data)->toHaveKey('vorschlag');
});

it('TEMPLATE_SAVE + TEMPLATE_FORK', function () {
    $save = ($this->run)('foodalchemist.concepts.TEMPLATE_SAVE', ['id' => $this->conceptId, 'name' => 'Vorlage A']);
    expect($save->success)->toBeTrue('save: ' . ($save->error ?? ''));

    $fork = ($this->run)('foodalchemist.concepts.TEMPLATE_FORK', ['template_id' => $save->data['id'], 'name' => 'Fork A']);
    expect($fork->success)->toBeTrue('fork: ' . ($fork->error ?? ''))->and($fork->data['name'])->toBe('Fork A');
});

it('GET-Modernisierung: price_display/price_mode im Payload', function () {
    $get = ($this->run)('foodalchemist.concepts.GET', ['concept_id' => $this->conceptId]);
    expect($get->success)->toBeTrue()->and($get->data['concept'])->toHaveKeys(['price_display', 'price_mode']);
});

it('Guards: unbekannt → NOT_FOUND; fremd → ACCESS_DENIED; leere felder → VALIDATION_ERROR', function () {
    expect(($this->run)('foodalchemist.concepts.PUT', ['id' => 999999, 'felder' => ['occasion' => 'X']])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.concepts.PUT', ['id' => $this->conceptId, 'felder' => ['occasion' => 'X']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->run)('foodalchemist.concepts.PUT', ['id' => $this->conceptId, 'felder' => []])->errorCode)->toBe('VALIDATION_ERROR');
});
