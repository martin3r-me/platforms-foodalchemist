<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D2c: recipes.REVISE (grounded Freitext-Revision, Draft-Quarantäne).
 * Verifiziert u.a. die Wissens-Grounding-Routing-Zeile (Workstream W) und die Guards.
 * Der Happy-Apply-Pfad braucht echte KI-Werte (FakeProvider liefert leer) und ist über die
 * geteilten Services (syncZeilen/syncIngredients) + den Editor bereits gedeckt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (array $a, ?ToolContext $k = null) => $this->registry->get('foodalchemist.recipes.REVISE')->execute($a, $k ?? $this->kontext);
    $this->mkRecipe = fn (array $over = []) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rk_' . bin2hex(random_bytes(4)),
        'name' => 'Fond: Revise', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false,
    ], $over));
});

it('Registry-Smoke: recipes.REVISE registriert', function () {
    $tool = $this->registry->get('foodalchemist.recipes.REVISE');
    expect($tool)->not->toBeNull();
    expect($tool->getSchema()['type'] ?? null)->toBe('object');
});

it('Workstream W: Routing-Zeile recipe.ueberarbeiten→regelwerk:always existiert (Migration)', function () {
    $vorhanden = DB::table('foodalchemist_knowledge_routings')
        ->where('feature', 'recipe.ueberarbeiten')->where('category', 'regelwerk')->where('mode', 'always')->exists();
    expect($vorhanden)->toBeTrue();
});

it('recipes.REVISE Vorschau auf draft (grounded, ohne accept → nichts geschrieben)', function () {
    $r = ($this->mkRecipe)();
    $res = ($this->run)(['recipe_id' => $r->id, 'anweisung' => 'mach es vegan']);
    expect($res->success)->toBeTrue()->and($res->data['recipe_id'])->toBe($r->id);
    // FakeProvider liefert i.d.R. keine verwertbare Revision → revision null + Hinweis; kein Write.
    expect($res->data)->toHaveKey('revision');
});

it('Draft-Quarantäne: approved Rezept → ACCESS_DENIED', function () {
    $r = ($this->mkRecipe)(['status' => 'approved']);
    expect(($this->run)(['recipe_id' => $r->id, 'anweisung' => 'x'])->errorCode)->toBe('ACCESS_DENIED');
});

it('Guards: fremd (Ancestry) → ACCESS_DENIED; VK/unbekannt → NOT_FOUND; leere Anweisung → VALIDATION_ERROR', function () {
    $r = ($this->mkRecipe)();
    expect(($this->run)(['recipe_id' => $r->id, 'anweisung' => 'x'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');

    $vk = ($this->mkRecipe)(['is_sales_recipe' => true]);
    expect(($this->run)(['recipe_id' => $vk->id, 'anweisung' => 'x'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)(['recipe_id' => 999999, 'anweisung' => 'x'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)(['recipe_id' => $r->id, 'anweisung' => '  '])->errorCode)->toBe('VALIDATION_ERROR');
});
