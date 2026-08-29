<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D4c/D4d: match.RUN + match_proposals.PUT sowie Geschirr (suppliers/items).
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

it('Registry-Smoke: match.* + geschirr_suppliers/items.* registriert', function () {
    foreach ([
        'foodalchemist.match.RUN', 'foodalchemist.match_proposals.PUT',
        'foodalchemist.geschirr_suppliers.LIST', 'foodalchemist.geschirr_suppliers.POST',
        'foodalchemist.geschirr_suppliers.PUT', 'foodalchemist.geschirr_suppliers.DEACTIVATE',
        'foodalchemist.geschirr_items.LIST', 'foodalchemist.geschirr_items.POST',
        'foodalchemist.geschirr_items.PUT', 'foodalchemist.geschirr_items.DEACTIVATE',
    ] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name);
        expect($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('match.RUN auf eigenem Lieferanten (0 Artikel → Stats); match_proposals.PUT guards', function () {
    $supplierId = ($this->run)('foodalchemist.suppliers.POST', ['input' => ['name' => 'Hanos']])->data['id'];
    $run = ($this->run)('foodalchemist.match.RUN', ['supplier_id' => $supplierId]);
    expect($run->success)->toBeTrue('run: ' . ($run->error ?? ''))->and($run->data)->toHaveKey('stats');

    expect(($this->run)('foodalchemist.match_proposals.PUT', ['proposal_id' => 1, 'action' => 'quatsch'])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.match.RUN', ['supplier_id' => 999999])->errorCode)->toBe('NOT_FOUND');
});

it('Geschirr: supplier POST/LIST/PUT/DEACTIVATE + item POST/LIST', function () {
    $post = ($this->run)('foodalchemist.geschirr_suppliers.POST', ['input' => ['name' => 'Villeroy & Boch']]);
    expect($post->success)->toBeTrue('supplier-post: ' . ($post->error ?? ''));
    $sid = $post->data['id'];

    $list = ($this->run)('foodalchemist.geschirr_suppliers.LIST', []);
    expect($list->success)->toBeTrue()->and(collect($list->data['suppliers'])->pluck('id'))->toContain($sid);

    expect(($this->run)('foodalchemist.geschirr_suppliers.PUT', ['id' => $sid, 'input' => ['name' => 'V&B GmbH']])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.geschirr_suppliers.DEACTIVATE', ['id' => $sid])->data['inactive'])->toBeTrue();

    $item = ($this->run)('foodalchemist.geschirr_items.POST', ['supplier_id' => $sid, 'input' => ['label' => 'Teller flach 28cm']]);
    expect($item->success)->toBeTrue('item-post: ' . ($item->error ?? ''));
    $iid = $item->data['id'];

    $items = ($this->run)('foodalchemist.geschirr_items.LIST', ['supplier_id' => $sid]);
    expect($items->success)->toBeTrue()->and(collect($items->data['items'])->pluck('id'))->toContain($iid);
});

it('Geschirr Guards: unbekannt → NOT_FOUND; fremd → ACCESS_DENIED', function () {
    $sid = ($this->run)('foodalchemist.geschirr_suppliers.POST', ['input' => ['name' => 'Schönwald']])->data['id'];
    expect(($this->run)('foodalchemist.geschirr_suppliers.PUT', ['id' => 999999, 'input' => ['name' => 'X']])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.geschirr_suppliers.PUT', ['id' => $sid, 'input' => ['name' => 'X']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});
