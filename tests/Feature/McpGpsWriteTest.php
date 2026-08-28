<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D1: GP-Kern-CRUD (gps.POST/PUT/STATUS/DELETE) + GET-Modernisierung.
 * Deckt Registry-Smoke, Happy-Paths, §6-Naming-Guard/Dubletten (force), Owner-Guard
 * (globale/geerbte GPs read-only = ACCESS_DENIED), Delete-Confirm, cross-tenant FK.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    // lokaler Helfer (kein globaler — Parallel-Worker-sicher): GP via POST-Tool anlegen
    $this->gpPost = fn (array $args) => $this->registry->get('foodalchemist.gps.POST')->execute($args, $this->kontext);
});

it('Registry-Smoke: gps.POST/PUT/STATUS/DELETE registriert, Schema=object', function () {
    foreach (['foodalchemist.gps.POST', 'foodalchemist.gps.PUT', 'foodalchemist.gps.STATUS', 'foodalchemist.gps.DELETE'] as $name) {
        $tool = $this->registry->get($name);
        expect($tool)->not->toBeNull($name);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('gps.POST legt team-eigen an (status=tentative, §6-Name gerendert)', function () {
    $res = ($this->gpPost)(['hauptzutat' => 'Zanderfilet', 'condition' => 'frisch']);
    expect($res->success)->toBeTrue()
        ->and($res->data['status'])->toBe('tentative')
        ->and($res->data['name'])->toBe('Zanderfilet: frisch');

    $gp = FoodAlchemistGp::find($res->data['id']);
    expect((int) $gp->team_id)->toBe((int) $this->rootTeam->id)
        ->and($gp->main_ingredient_slug)->toBe('zanderfilet');
});

it('gps.POST ohne hauptzutat → VALIDATION_ERROR', function () {
    $res = ($this->gpPost)(['condition' => 'frisch']);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

it('gps.POST Dubletten-Hard-Stop; force=true legt trotzdem an', function () {
    expect(($this->gpPost)(['hauptzutat' => 'Kabeljau'])->success)->toBeTrue();

    $dup = ($this->gpPost)(['hauptzutat' => 'Kabeljau']);
    expect($dup->success)->toBeFalse()->and($dup->errorCode)->toBe('VALIDATION_ERROR');

    $forced = ($this->gpPost)(['hauptzutat' => 'Kabeljau', 'force' => true]);
    expect($forced->success)->toBeTrue();
});

it('gps.POST derivat_von_gp_id cross-tenant → NOT_FOUND', function () {
    $fremd = FoodAlchemistGp::create([
        'team_id' => $this->childA->id, 'gp_key' => 'fremd_mutter', 'name' => 'Fremd Mutter',
        'main_ingredient_slug' => 'fremd', 'status' => 'approved', 'requires_la' => true,
    ]);
    $res = ($this->gpPost)(['hauptzutat' => 'Karkasse', 'is_derivat' => true, 'derivat_von_gp_id' => $fremd->id]);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('gps.PUT bearbeitet eigenes GP (Name neu gerendert)', function () {
    $id = ($this->gpPost)(['hauptzutat' => 'Lachs'])->data['id'];
    $res = $this->registry->get('foodalchemist.gps.PUT')->execute(['id' => $id, 'hauptzutat' => 'Lachs', 'condition' => 'TK'], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['name'])->toBe('Lachs: TK')
        ->and($res->data['condition'])->toBe('TK');
});

it('gps.PUT auf globales GP → ACCESS_DENIED (nur eigene editierbar)', function () {
    $global = FoodAlchemistGp::create([
        'team_id' => null, 'gp_key' => 'global_gp', 'name' => 'Global GP',
        'main_ingredient_slug' => 'global', 'status' => 'approved', 'requires_la' => true,
    ]);
    $res = $this->registry->get('foodalchemist.gps.PUT')->execute(['id' => $global->id, 'name' => 'Hack'], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('ACCESS_DENIED');
});

it('gps.PUT/STATUS/DELETE auf unbekannte Id → NOT_FOUND', function () {
    expect($this->registry->get('foodalchemist.gps.PUT')->execute(['id' => 999999, 'name' => 'X'], $this->kontext)->errorCode)->toBe('NOT_FOUND');
    expect($this->registry->get('foodalchemist.gps.STATUS')->execute(['id' => 999999, 'status' => 'approved'], $this->kontext)->errorCode)->toBe('NOT_FOUND');
    expect($this->registry->get('foodalchemist.gps.DELETE')->execute(['id' => 999999, 'confirm' => true], $this->kontext)->errorCode)->toBe('NOT_FOUND');
});

it('gps.STATUS setzt approved; merged wird abgewiesen', function () {
    $id = ($this->gpPost)(['hauptzutat' => 'Forelle'])->data['id'];
    $ok = $this->registry->get('foodalchemist.gps.STATUS')->execute(['id' => $id, 'status' => 'approved'], $this->kontext);
    expect($ok->success)->toBeTrue()->and($ok->data['status'])->toBe('approved');

    $merged = $this->registry->get('foodalchemist.gps.STATUS')->execute(['id' => $id, 'status' => 'merged'], $this->kontext);
    expect($merged->success)->toBeFalse()->and($merged->errorCode)->toBe('VALIDATION_ERROR');
});

it('gps.DELETE erfordert confirm; löscht dann eigenes unreferenziertes GP', function () {
    $id = ($this->gpPost)(['hauptzutat' => 'Wolfsbarsch'])->data['id'];

    $ohne = $this->registry->get('foodalchemist.gps.DELETE')->execute(['id' => $id], $this->kontext);
    expect($ohne->success)->toBeFalse()->and($ohne->errorCode)->toBe('CONFIRM_REQUIRED');

    $mit = $this->registry->get('foodalchemist.gps.DELETE')->execute(['id' => $id, 'confirm' => true], $this->kontext);
    expect($mit->success)->toBeTrue()->and($mit->data['deleted'])->toBeTrue();
    expect(FoodAlchemistGp::find($id))->toBeNull();   // Soft-Delete → aus Default-Scope
});

it('gps.GET modernisiert: Derivat-Trio + Taxonomie + Tags im Payload', function () {
    $id = ($this->gpPost)(['hauptzutat' => 'Zander', 'commodity_group_code' => '05', 'sub_category' => 'Suesswasserfisch'])->data['id'];
    $res = $this->registry->get('foodalchemist.gps.GET')->execute(['id' => $id], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data)->toHaveKeys(['is_derivat', 'derivat_von_gp_id', 'requires_la', 'commodity_group_code', 'sub_category', 'tags'])
        ->and($res->data['commodity_group_code'])->toBe('05')
        ->and($res->data['tags'])->toHaveKey('is_vegan');
});
