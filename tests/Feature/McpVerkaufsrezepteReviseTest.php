<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D3d: verkaufsrezepte.REVISE (grounded vk.ueberarbeiten, Draft-Quarantäne).
 * Verifiziert die Grounding-Routing-Zeile (Workstream W) und die Guards.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (array $a, ?ToolContext $k = null) => $this->registry->get('foodalchemist.verkaufsrezepte.REVISE')->execute($a, $k ?? $this->kontext);
    $this->vkId = $this->registry->get('foodalchemist.verkaufsrezepte.POST')->execute(['name' => 'HG: Revise'], $this->kontext)->data['id'];
});

it('Registry-Smoke: verkaufsrezepte.REVISE registriert', function () {
    $tool = $this->registry->get('foodalchemist.verkaufsrezepte.REVISE');
    expect($tool)->not->toBeNull();
    expect($tool->getSchema()['type'] ?? null)->toBe('object');
});

it('Workstream W: Routing-Zeile vk.ueberarbeiten→regelwerk:always existiert (Migration)', function () {
    expect(DB::table('foodalchemist_knowledge_routings')
        ->where('feature', 'vk.ueberarbeiten')->where('category', 'regelwerk')->where('mode', 'always')->exists())->toBeTrue();
});

it('Vorschau auf draft-Gericht (grounded, ohne accept)', function () {
    $res = ($this->run)(['recipe_id' => $this->vkId, 'anweisung' => 'eleganteres Wording']);
    expect($res->success)->toBeTrue()->and($res->data['recipe_id'])->toBe($this->vkId)->and($res->data)->toHaveKey('revision');
});

it('Draft-Quarantäne: approved Gericht → ACCESS_DENIED', function () {
    FoodAlchemistRecipe::whereKey($this->vkId)->update(['status' => 'approved']);
    expect(($this->run)(['recipe_id' => $this->vkId, 'anweisung' => 'x'])->errorCode)->toBe('ACCESS_DENIED');
});

it('Guards: fremd → ACCESS_DENIED; Basisrezept/unbekannt → NOT_FOUND; leere Anweisung → VALIDATION_ERROR', function () {
    expect(($this->run)(['recipe_id' => $this->vkId, 'anweisung' => 'x'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');

    $basis = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'b_' . bin2hex(random_bytes(3)), 'name' => 'Fond', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false]);
    expect(($this->run)(['recipe_id' => $basis->id, 'anweisung' => 'x'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)(['recipe_id' => 999999, 'anweisung' => 'x'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)(['recipe_id' => $this->vkId, 'anweisung' => '  '])->errorCode)->toBe('VALIDATION_ERROR');
});
