<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D2c: recipes.INSTANTIATE — Basisrezept aus Vorlage.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (array $a) => $this->registry->get('foodalchemist.recipes.INSTANTIATE')->execute($a, $this->kontext);
    $this->mkTemplate = fn (bool $tpl = true) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'tpl_' . bin2hex(random_bytes(4)),
        'name' => 'Grundfond', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false, 'is_template' => $tpl,
    ]);
});

it('Registry-Smoke: recipes.INSTANTIATE registriert', function () {
    $tool = $this->registry->get('foodalchemist.recipes.INSTANTIATE');
    expect($tool)->not->toBeNull();
    expect($tool->getSchema()['type'] ?? null)->toBe('object');
});

it('instanziiert aus Vorlage; idempotent per Name', function () {
    $tpl = ($this->mkTemplate)();

    $erst = ($this->run)(['template_id' => $tpl->id, 'name' => 'Grundfond: Wild']);
    expect($erst->success)->toBeTrue()->and($erst->data['created'])->toBeTrue();
    $neu = FoodAlchemistRecipe::find($erst->data['id']);
    expect((int) $neu->instantiated_from_recipe_id)->toBe((int) $tpl->id);

    $zweit = ($this->run)(['template_id' => $tpl->id, 'name' => 'Grundfond: Wild']);
    expect($zweit->success)->toBeTrue()->and($zweit->data['created'])->toBeFalse()->and($zweit->data['id'])->toBe($erst->data['id']);
});

it('Nicht-Template → NOT_FOUND; leerer Name → VALIDATION_ERROR', function () {
    $kein = ($this->mkTemplate)(false);
    expect(($this->run)(['template_id' => $kein->id, 'name' => 'X'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)(['template_id' => 999999, 'name' => 'X'])->errorCode)->toBe('NOT_FOUND');

    $tpl = ($this->mkTemplate)();
    expect(($this->run)(['template_id' => $tpl->id, 'name' => '  '])->errorCode)->toBe('VALIDATION_ERROR');
});
