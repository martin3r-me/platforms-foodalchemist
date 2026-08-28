<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D3b: Darreichungen (recipe_darreichung.POST/PUT/DELETE/STANDARD + _delta.PUT).
 * Owner-Guard über das Gericht, Servierform-Auflösung, Standard-Wechsel.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    FoodAlchemistServierform::firstOrCreate(['code' => 'teller', 'team_id' => $this->rootTeam->id], ['label' => 'Teller']);
    FoodAlchemistServierform::firstOrCreate(['code' => 'schale', 'team_id' => $this->rootTeam->id], ['label' => 'Schale']);
    $this->vkId = $this->registry->get('foodalchemist.verkaufsrezepte.POST')->execute(['name' => 'HG: Darreichung'], $this->kontext)->data['id'];
    $this->postDar = fn (string $form) => $this->registry->get('foodalchemist.recipe_darreichung.POST')
        ->execute(['recipe_id' => $this->vkId, 'serving_form' => $form], $this->kontext);
});

it('Registry-Smoke: recipe_darreichung.* + _delta.PUT registriert', function () {
    foreach ([
        'foodalchemist.recipe_darreichung.POST', 'foodalchemist.recipe_darreichung.PUT',
        'foodalchemist.recipe_darreichung.DELETE', 'foodalchemist.recipe_darreichung.STANDARD',
        'foodalchemist.recipe_darreichung_delta.PUT',
    ] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name);
        expect($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('POST legt Darreichung an (erste = Standard); unbekannte serving_form → VALIDATION_ERROR', function () {
    $res = ($this->postDar)('teller');
    expect($res->success)->toBeTrue()->and($res->data['is_standard'])->toBeTrue();

    $bad = ($this->postDar)('gibtsnicht');
    expect($bad->success)->toBeFalse()->and($bad->errorCode)->toBe('VALIDATION_ERROR');
});

it('PUT bearbeitet + STANDARD (idempotent) + DELETE auf eigener Darreichung', function () {
    $p1 = ($this->postDar)('teller')->data['presentation_id'];

    $put = ($this->run)('foodalchemist.recipe_darreichung.PUT', ['presentation_id' => $p1, 'attrs' => ['note' => 'Testnotiz']]);
    expect($put->success)->toBeTrue('PUT: ' . ($put->error ?? ''));

    $std = ($this->run)('foodalchemist.recipe_darreichung.STANDARD', ['presentation_id' => $p1]);
    expect($std->success)->toBeTrue('STANDARD: ' . ($std->error ?? ''))->and($std->data['is_standard'])->toBeTrue();

    $del = ($this->run)('foodalchemist.recipe_darreichung.DELETE', ['presentation_id' => $p1]);
    expect($del->success)->toBeTrue('DELETE: ' . ($del->error ?? ''))->and($del->data['deleted'])->toBeTrue();
});

it('Guards: unbekannte presentation_id → NOT_FOUND; fremd → ACCESS_DENIED', function () {
    $p1 = ($this->postDar)('teller')->data['presentation_id'];
    expect(($this->run)('foodalchemist.recipe_darreichung.PUT', ['presentation_id' => 999999, 'attrs' => ['note' => 'x']])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.recipe_darreichung.PUT', ['presentation_id' => $p1, 'attrs' => ['note' => 'x']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});

it('delta.PUT: ungültige action → VALIDATION_ERROR; unbekannte Darreichung → NOT_FOUND', function () {
    $p1 = ($this->postDar)('teller')->data['presentation_id'];
    expect(($this->run)('foodalchemist.recipe_darreichung_delta.PUT', ['presentation_id' => $p1, 'recipe_ingredient_id' => 1, 'action' => 'quatsch'])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.recipe_darreichung_delta.PUT', ['presentation_id' => 999999, 'recipe_ingredient_id' => 1, 'action' => 'remove'])->errorCode)->toBe('NOT_FOUND');
});
