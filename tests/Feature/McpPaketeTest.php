<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D5d: Pakete-Ressource (GET/LIST/SEARCH/POST/PUT/DELETE/DUPLICATE/RECOMPUTE)
 * + paket_gerichte (SET/MENGE/GESCHIRR/REORDER). Spiegelt Livewire\Pakete\Index (Web↔MCP-Parität).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->svc = app(PaketService::class);
    $this->paket = $this->svc->create($this->rootTeam, ['name' => 'Grill-Buffet', 'role' => 'Buffet']);
    $this->dishA = $this->makeRecipe($this->rootTeam, 'DES: Steak', ['is_sales_recipe' => true, 'sales_net' => 9.0]);
    $this->dishB = $this->makeRecipe($this->rootTeam, 'DES: Salat', ['is_sales_recipe' => true, 'sales_net' => 4.0]);
});

it('Registry-Smoke: alle 12 D5d-Tools registriert mit type=object', function () {
    $namen = [
        'pakete.GET', 'pakete.LIST', 'pakete.SEARCH', 'pakete.POST', 'pakete.PUT',
        'pakete.DELETE', 'pakete.DUPLICATE', 'pakete.RECOMPUTE',
        'paket_gerichte.SET', 'paket_gerichte.MENGE', 'paket_gerichte.GESCHIRR', 'paket_gerichte.REORDER',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('GET / LIST / SEARCH', function () {
    $get = ($this->run)('foodalchemist.pakete.GET', ['id' => $this->paket->id]);
    expect($get->success)->toBeTrue('get: ' . ($get->error ?? ''))
        ->and($get->data['paket']['name'])->toBe('Grill-Buffet')
        ->and($get->data['paket'])->toHaveKey('dishes');

    $list = ($this->run)('foodalchemist.pakete.LIST', []);
    expect($list->success)->toBeTrue()->and($list->data['total'])->toBeGreaterThanOrEqual(1);

    $search = ($this->run)('foodalchemist.pakete.SEARCH', ['query' => 'Grill']);
    expect($search->success)->toBeTrue()
        ->and(collect($search->data['pakete'])->pluck('id')->all())->toContain($this->paket->id);
});

it('POST / PUT / DUPLICATE / RECOMPUTE', function () {
    $post = ($this->run)('foodalchemist.pakete.POST', ['name' => 'Fingerfood', 'role' => 'Snack']);
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''));
    $newId = $post->data['paket']['id'];

    $put = ($this->run)('foodalchemist.pakete.PUT', ['id' => $newId, 'felder' => ['level' => 'premium', 'price_mode' => 'auto']]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''))->and($put->data['paket']['level'])->toBe('premium');

    $dup = ($this->run)('foodalchemist.pakete.DUPLICATE', ['id' => $this->paket->id]);
    expect($dup->success)->toBeTrue('dup: ' . ($dup->error ?? ''))->and($dup->data['paket']['name'])->toContain('(Kopie)');

    $rec = ($this->run)('foodalchemist.pakete.RECOMPUTE', ['id' => $newId]);
    expect($rec->success)->toBeTrue('rec: ' . ($rec->error ?? ''))->and($rec->data['recomputed'])->toBeTrue();
});

it('paket_gerichte: SET (+FK-Re-Auth) / MENGE / REORDER / GESCHIRR(lösen)', function () {
    $set = ($this->run)('foodalchemist.paket_gerichte.SET', [
        'paket_id' => $this->paket->id,
        'items' => [['sales_recipe_id' => $this->dishA->id], ['sales_recipe_id' => $this->dishB->id]],
    ]);
    expect($set->success)->toBeTrue('set: ' . ($set->error ?? ''))
        ->and(count($set->data['paket']['dishes']))->toBe(2);

    $rows = FoodAlchemistPaketGericht::where('package_id', $this->paket->id)->orderBy('position')->pluck('id')->all();

    $menge = ($this->run)('foodalchemist.paket_gerichte.MENGE', ['paket_id' => $this->paket->id, 'row_id' => $rows[0], 'quantity' => 80]);
    expect($menge->success)->toBeTrue('menge: ' . ($menge->error ?? ''));
    expect((float) FoodAlchemistPaketGericht::find($rows[0])->quantity)->toBe(80.0);

    $re = ($this->run)('foodalchemist.paket_gerichte.REORDER', ['paket_id' => $this->paket->id, 'ids' => [$rows[1], $rows[0]]]);
    expect($re->success)->toBeTrue('reorder: ' . ($re->error ?? ''));
    expect(FoodAlchemistPaketGericht::find($rows[1])->position)->toBe(0);

    // Geschirr lösen (item_id weglassen) — läuft ohne Geschirr-Artikel durch
    $g = ($this->run)('foodalchemist.paket_gerichte.GESCHIRR', ['row_id' => $rows[0], 'role' => 'haupt']);
    expect($g->success)->toBeTrue('geschirr: ' . ($g->error ?? ''));
});

it('SET: fremdes Gericht → NOT_FOUND, nichts geschrieben', function () {
    // childA-eigenes Gericht ist für root nicht sichtbar (recipes.team_id NOT NULL, keine Ancestry nach unten)
    $fremd = $this->makeRecipe($this->childA, 'DES: Fremd', ['is_sales_recipe' => true, 'sales_net' => 3.0]);
    $res = ($this->run)('foodalchemist.paket_gerichte.SET', [
        'paket_id' => $this->paket->id,
        'items' => [['sales_recipe_id' => $fremd->id]],
    ]);
    expect($res->errorCode)->toBe('NOT_FOUND');
    expect(FoodAlchemistPaketGericht::where('package_id', $this->paket->id)->count())->toBe(0);
});

it('DELETE: confirm-gated, dann soft-deleted', function () {
    expect(($this->run)('foodalchemist.pakete.DELETE', ['id' => $this->paket->id])->errorCode)->toBe('CONFIRM_REQUIRED');

    $del = ($this->run)('foodalchemist.pakete.DELETE', ['id' => $this->paket->id, 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
    expect($this->svc->detail($this->rootTeam, $this->paket->id))->toBeNull();
});

it('Guards: unbekannt NOT_FOUND, fremd ACCESS_DENIED', function () {
    expect(($this->run)('foodalchemist.pakete.GET', ['id' => 999999])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.pakete.PUT', ['id' => 999999, 'felder' => ['level' => 'x']])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.pakete.PUT', ['id' => $this->paket->id, 'felder' => ['level' => 'x']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->run)('foodalchemist.paket_gerichte.SET', ['paket_id' => $this->paket->id, 'items' => []], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});
