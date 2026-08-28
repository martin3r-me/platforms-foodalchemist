<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D1c: gp_la.PUT (Guards/Autorisierung), component_equivalents.POST/DELETE,
 * platzhalter.POST/PUT/DELETE, gps.REPLACE (destruktiv/confirm). Fokus: Tenancy + Validierung.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->neuGp = fn (string $hz) => $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => $hz], $this->kontext)->data['id'];
    $this->run = fn (string $name, array $a) => $this->registry->get($name)->execute($a, $this->kontext);
    $this->globalGp = fn () => FoodAlchemistGp::create([
        'team_id' => null, 'gp_key' => 'glob_' . bin2hex(random_bytes(3)), 'name' => 'Global',
        'main_ingredient_slug' => 'global', 'status' => 'approved', 'requires_la' => true,
    ]);
});

it('Registry-Smoke: D1c-Tools registriert', function () {
    foreach ([
        'foodalchemist.gp_la.PUT', 'foodalchemist.component_equivalents.POST', 'foodalchemist.component_equivalents.DELETE',
        'foodalchemist.platzhalter.POST', 'foodalchemist.platzhalter.PUT', 'foodalchemist.platzhalter.DELETE',
        'foodalchemist.gps.REPLACE',
    ] as $name) {
        $tool = $this->registry->get($name);
        expect($tool)->not->toBeNull($name);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('gp_la.PUT: Guards — link auf global=ACCESS_DENIED, fehlende la_item_id/action=VALIDATION_ERROR, unbekanntes GP=NOT_FOUND', function () {
    $global = ($this->globalGp)();
    expect(($this->run)('foodalchemist.gp_la.PUT', ['gp_id' => $global->id, 'action' => 'link', 'la_item_id' => 4711])->errorCode)->toBe('ACCESS_DENIED');

    $id = ($this->neuGp)('Rind');
    expect(($this->run)('foodalchemist.gp_la.PUT', ['gp_id' => $id, 'action' => 'link'])->errorCode)->toBe('VALIDATION_ERROR');   // la_item_id fehlt
    expect(($this->run)('foodalchemist.gp_la.PUT', ['gp_id' => $id, 'action' => 'quatsch'])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.gp_la.PUT', ['gp_id' => 999999, 'action' => 'lock', 'la_item_id' => 1])->errorCode)->toBe('NOT_FOUND');
});

it('gp_la.PUT: unlink eines nicht-verknüpften LA → VALIDATION_ERROR (Service-Guard)', function () {
    $id = ($this->neuGp)('Huhn');
    $res = ($this->run)('foodalchemist.gp_la.PUT', ['gp_id' => $id, 'action' => 'unlink', 'la_item_id' => 4711]);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

it('component_equivalents.POST legt Äquivalenz an, DELETE löst sie', function () {
    $a = ($this->neuGp)('Butter');
    $b = ($this->neuGp)('Margarine');
    $post = ($this->run)('foodalchemist.component_equivalents.POST', ['source_kind' => 'gp', 'source_id' => $a, 'alt_kind' => 'gp', 'alt_id' => $b, 'umrechnungsfaktor' => 1.0]);
    expect($post->success)->toBeTrue()->and($post->data['source_id'])->toBe($a)->and($post->data['alt_id'])->toBe($b);

    $del = ($this->run)('foodalchemist.component_equivalents.DELETE', ['id' => $post->data['id']]);
    expect($del->success)->toBeTrue()->and($del->data['deleted'])->toBeTrue();
});

it('component_equivalents.POST: Selbst-Äquivalenz + fremde Seite abgewiesen', function () {
    $a = ($this->neuGp)('Sahne');
    $selbst = ($this->run)('foodalchemist.component_equivalents.POST', ['source_kind' => 'gp', 'source_id' => $a, 'alt_kind' => 'gp', 'alt_id' => $a]);
    expect($selbst->success)->toBeFalse()->and($selbst->errorCode)->toBe('VALIDATION_ERROR');

    $fremd = FoodAlchemistGp::create(['team_id' => $this->childA->id, 'gp_key' => 'fremd_eq', 'name' => 'Fremd', 'main_ingredient_slug' => 'fremd', 'status' => 'approved', 'requires_la' => true]);
    $res = ($this->run)('foodalchemist.component_equivalents.POST', ['source_kind' => 'gp', 'source_id' => $a, 'alt_kind' => 'gp', 'alt_id' => $fremd->id]);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('platzhalter.POST/PUT/DELETE Roundtrip; unbekannt → NOT_FOUND; leerer Name → VALIDATION_ERROR', function () {
    $post = ($this->run)('foodalchemist.platzhalter.POST', ['name' => 'Bindemittel']);
    expect($post->success)->toBeTrue()->and($post->data['is_platzhalter'])->toBeTrue();
    $id = $post->data['id'];

    expect(($this->run)('foodalchemist.platzhalter.PUT', ['id' => $id, 'name' => 'Verdicker'])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.platzhalter.DELETE', ['id' => $id])->data['deleted'])->toBeTrue();

    expect(($this->run)('foodalchemist.platzhalter.PUT', ['id' => 999999, 'name' => 'X'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.platzhalter.POST', ['name' => '  '])->errorCode)->toBe('VALIDATION_ERROR');
});

it('gps.REPLACE: confirm-Pflicht, Owner-Guard, from==to; Happy-Path rechnet (0 Zeilen)', function () {
    $from = ($this->neuGp)('Speisestaerke');
    $to = ($this->neuGp)('Kartoffelstaerke');

    expect(($this->run)('foodalchemist.gps.REPLACE', ['from_id' => $from, 'to_id' => $to])->errorCode)->toBe('CONFIRM_REQUIRED');
    expect(($this->run)('foodalchemist.gps.REPLACE', ['from_id' => $from, 'to_id' => $from, 'confirm' => true])->errorCode)->toBe('VALIDATION_ERROR');

    $global = ($this->globalGp)();
    expect(($this->run)('foodalchemist.gps.REPLACE', ['from_id' => $global->id, 'to_id' => $to, 'confirm' => true])->errorCode)->toBe('ACCESS_DENIED');

    $ok = ($this->run)('foodalchemist.gps.REPLACE', ['from_id' => $from, 'to_id' => $to, 'confirm' => true]);
    expect($ok->success)->toBeTrue()->and($ok->data['zeilen'])->toBe(0);
});
