<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · Phase N: Navigation — ui.ROUTES (Katalog) + ui.NAVIGATE (Seiten) +
 * ui.OPEN erweitert auf alle Haupt-Datensatztypen (mit Sichtbarkeits-Guard).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
});

it('Registry-Smoke: ui.ROUTES / ui.NAVIGATE / ui.OPEN registriert', function () {
    foreach (['ui.ROUTES', 'ui.NAVIGATE', 'ui.OPEN'] as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('ui.ROUTES: liefert Seiten-Katalog + Record-Typen', function () {
    $r = ($this->run)('foodalchemist.ui.ROUTES', []);
    expect($r->success)->toBeTrue('routes: ' . ($r->error ?? ''));
    $keys = collect($r->data['routes'])->pluck('route_key')->all();
    expect($keys)->toContain('orders')->toContain('gps')->toContain('planung')
        ->and($r->data['record_types'])->toContain('angebot')->toContain('production_order');
});

it('ui.NAVIGATE: gültiger route_key + unbekannter + Detail-id-Pflicht', function () {
    $nav = ($this->run)('foodalchemist.ui.NAVIGATE', ['route_key' => 'orders']);
    expect($nav->success)->toBeTrue('nav: ' . ($nav->error ?? ''))
        ->and($nav->data['navigate']['route'])->toBe('foodalchemist.orders.index');

    expect(($this->run)('foodalchemist.ui.NAVIGATE', ['route_key' => 'quatsch'])->errorCode)->toBe('NOT_FOUND');
    // Detail-Route ohne id → VALIDATION_ERROR
    expect(($this->run)('foodalchemist.ui.NAVIGATE', ['route_key' => 'gp_detail'])->errorCode)->toBe('VALIDATION_ERROR');
});

it('ui.NAVIGATE: Detail-Route mit sichtbarem GP + Sichtbarkeits-Guard', function () {
    $gp = $this->makeGp($this->rootTeam, 'Mehl');
    $nav = ($this->run)('foodalchemist.ui.NAVIGATE', ['route_key' => 'gp_detail', 'id' => $gp->id]);
    expect($nav->success)->toBeTrue('nav: ' . ($nav->error ?? ''))
        ->and($nav->data['navigate']['params']['grundprodukt'])->toBe($gp->id);

    expect(($this->run)('foodalchemist.ui.NAVIGATE', ['route_key' => 'gp_detail', 'id' => 999999])->errorCode)->toBe('NOT_FOUND');
});

it('ui.OPEN: erweiterte Typen (recipe/gp/concept) + Guards', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'DES: Testrezept');
    $gp = $this->makeGp($this->rootTeam, 'Butter');
    $concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Menü']);

    expect(($this->run)('foodalchemist.ui.OPEN', ['type' => 'recipe', 'id' => $recipe->id])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.ui.OPEN', ['type' => 'gp', 'id' => $gp->id])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.ui.OPEN', ['type' => 'concept', 'id' => $concept->id])->success)->toBeTrue();

    // recipe-Typ trifft kein VK-Gericht; verkaufsrezept-Typ trifft kein Basisrezept
    expect(($this->run)('foodalchemist.ui.OPEN', ['type' => 'verkaufsrezept', 'id' => $recipe->id])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.ui.OPEN', ['type' => 'angebot', 'id' => 999999])->errorCode)->toBe('NOT_FOUND');
});
