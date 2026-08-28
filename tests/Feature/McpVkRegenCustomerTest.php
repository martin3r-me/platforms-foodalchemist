<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D3c: recipe_regeneration.PUT/DELETE/REORDER + recipe_customer_names.POST/DELETE.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->vkId = $this->registry->get('foodalchemist.verkaufsrezepte.POST')->execute(['name' => 'HG: Regen'], $this->kontext)->data['id'];
});

it('Registry-Smoke: regeneration.* + customer_names.* registriert', function () {
    foreach ([
        'foodalchemist.recipe_regeneration.PUT', 'foodalchemist.recipe_regeneration.DELETE', 'foodalchemist.recipe_regeneration.REORDER',
        'foodalchemist.recipe_customer_names.POST', 'foodalchemist.recipe_customer_names.DELETE',
    ] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name);
        expect($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('regeneration PUT (create) + REORDER + DELETE', function () {
    $put = ($this->run)('foodalchemist.recipe_regeneration.PUT', ['recipe_id' => $this->vkId, 'felder' => ['component_label' => 'Kombidämpfer', 'temp_c' => 140, 'duration_min' => 12]]);
    expect($put->success)->toBeTrue('PUT: ' . ($put->error ?? ''));

    $regId = (int) DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $this->vkId)->value('id');
    expect($regId)->toBeGreaterThan(0);

    expect(($this->run)('foodalchemist.recipe_regeneration.REORDER', ['recipe_id' => $this->vkId, 'ids' => [$regId]])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.recipe_regeneration.DELETE', ['recipe_id' => $this->vkId, 'id' => $regId])->data['deleted'])->toBeTrue();
});

it('customer_names POST + DELETE', function () {
    $post = ($this->run)('foodalchemist.recipe_customer_names.POST', ['recipe_id' => $this->vkId, 'kunde' => 'Adler', 'marketing_name' => 'Fjord-Lachs']);
    expect($post->success)->toBeTrue('POST: ' . ($post->error ?? ''));

    $cnId = (int) DB::table('foodalchemist_recipe_customer_names')->where('recipe_id', $this->vkId)->value('id');
    expect($cnId)->toBeGreaterThan(0);
    expect(($this->run)('foodalchemist.recipe_customer_names.DELETE', ['recipe_id' => $this->vkId, 'id' => $cnId])->data['deleted'])->toBeTrue();
});

it('Guards: leere felder/kunde → VALIDATION_ERROR; unbekanntes Gericht → NOT_FOUND; fremd → ACCESS_DENIED', function () {
    expect(($this->run)('foodalchemist.recipe_regeneration.PUT', ['recipe_id' => $this->vkId, 'felder' => []])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.recipe_customer_names.POST', ['recipe_id' => $this->vkId, 'kunde' => '', 'marketing_name' => 'X'])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.recipe_regeneration.PUT', ['recipe_id' => 999999, 'felder' => ['component_label' => 'X']])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.recipe_customer_names.POST', ['recipe_id' => $this->vkId, 'kunde' => 'A', 'marketing_name' => 'B'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});
