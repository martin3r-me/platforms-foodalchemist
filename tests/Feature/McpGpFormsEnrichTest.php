<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D1b: Naturaleinheit-Formen (gp_forms.PUT/DELETE/ESTIMATE) + KI-Anreicherung
 * (gps.ENRICH → gp_enrich.RESOLVE). Deckt Registry-Smoke, Happy-Paths, Form-Validierung, Owner-Guard
 * (globale GPs → ACCESS_DENIED) und die accept_all/accept/reject-Validierung.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->neuGp = fn (string $hz) => $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => $hz], $this->kontext)->data['id'];
    $this->globalGp = fn () => FoodAlchemistGp::create([
        'team_id' => null, 'gp_key' => 'glob_' . bin2hex(random_bytes(3)), 'name' => 'Global',
        'main_ingredient_slug' => 'global', 'status' => 'approved', 'requires_la' => true,
    ]);
});

it('Registry-Smoke: gp_forms.* + gps.ENRICH + gp_enrich.RESOLVE registriert', function () {
    foreach ([
        'foodalchemist.gp_forms.PUT', 'foodalchemist.gp_forms.DELETE', 'foodalchemist.gp_forms.ESTIMATE',
        'foodalchemist.gps.ENRICH', 'foodalchemist.gp_enrich.RESOLVE',
    ] as $name) {
        $tool = $this->registry->get($name);
        expect($tool)->not->toBeNull($name);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('gp_forms.PUT setzt Form + Gramm (persistiert), DELETE entfernt sie', function () {
    $id = ($this->neuGp)('Salami');

    $put = $this->registry->get('foodalchemist.gp_forms.PUT')->execute(['gp_id' => $id, 'form_slug' => 'scheibe', 'gramm' => 25], $this->kontext);
    expect($put->success)->toBeTrue()
        ->and($put->data['form_slug'])->toBe('scheibe')
        ->and($put->data['gramm'])->toBe(25.0)
        ->and($put->data['source'])->toBe('manual');
    expect(FoodAlchemistGpForm::where('gp_id', $id)->where('form_slug', 'scheibe')->exists())->toBeTrue();

    $del = $this->registry->get('foodalchemist.gp_forms.DELETE')->execute(['gp_id' => $id, 'form_slug' => 'scheibe'], $this->kontext);
    expect($del->success)->toBeTrue()->and($del->data['removed'])->toBeTrue();
    expect(FoodAlchemistGpForm::where('gp_id', $id)->where('form_slug', 'scheibe')->exists())->toBeFalse();
});

it('gp_forms.PUT: unbekannte Form + gramm≤0 → VALIDATION_ERROR', function () {
    $id = ($this->neuGp)('Gurke');
    $bad1 = $this->registry->get('foodalchemist.gp_forms.PUT')->execute(['gp_id' => $id, 'form_slug' => 'kugel', 'gramm' => 10], $this->kontext);
    expect($bad1->success)->toBeFalse()->and($bad1->errorCode)->toBe('VALIDATION_ERROR');
    $bad2 = $this->registry->get('foodalchemist.gp_forms.PUT')->execute(['gp_id' => $id, 'form_slug' => 'scheibe', 'gramm' => 0], $this->kontext);
    expect($bad2->success)->toBeFalse()->and($bad2->errorCode)->toBe('VALIDATION_ERROR');
});

it('gp_forms.PUT/ESTIMATE auf globales GP → ACCESS_DENIED', function () {
    $global = ($this->globalGp)();
    $put = $this->registry->get('foodalchemist.gp_forms.PUT')->execute(['gp_id' => $global->id, 'form_slug' => 'stk', 'gramm' => 50], $this->kontext);
    expect($put->errorCode)->toBe('ACCESS_DENIED');
    $est = $this->registry->get('foodalchemist.gp_forms.ESTIMATE')->execute(['gp_id' => $global->id], $this->kontext);
    expect($est->errorCode)->toBe('ACCESS_DENIED');
});

it('gp_forms.ESTIMATE läuft (KI, source=ki) und liefert eine Zahl', function () {
    $id = ($this->neuGp)('Ei');
    $res = $this->registry->get('foodalchemist.gp_forms.ESTIMATE')->execute(['gp_id' => $id], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['formen_geschrieben'])->toBeInt();
});

it('gps.ENRICH startet Lauf (run_id) für eigenes GP; global → ACCESS_DENIED; unbekannt → NOT_FOUND', function () {
    $id = ($this->neuGp)('Tomate');
    $ok = $this->registry->get('foodalchemist.gps.ENRICH')->execute(['gp_id' => $id, 'felder' => ['condition', 'tags']], $this->kontext);
    expect($ok->success)->toBeTrue()
        ->and($ok->data['run_id'])->toBeInt()
        ->and($ok->data['status'])->toBe('queued');

    $global = ($this->globalGp)();
    expect($this->registry->get('foodalchemist.gps.ENRICH')->execute(['gp_id' => $global->id], $this->kontext)->errorCode)->toBe('ACCESS_DENIED');
    expect($this->registry->get('foodalchemist.gps.ENRICH')->execute(['gp_id' => 999999], $this->kontext)->errorCode)->toBe('NOT_FOUND');
});

it('gps.ENRICH: ungültige felder → VALIDATION_ERROR', function () {
    $id = ($this->neuGp)('Paprika');
    $res = $this->registry->get('foodalchemist.gps.ENRICH')->execute(['gp_id' => $id, 'felder' => ['farbe']], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

it('gp_enrich.RESOLVE: accept_all mit run_id übernimmt; fehlende Argumente/Action → VALIDATION_ERROR', function () {
    $id = ($this->neuGp)('Zwiebel');
    $runId = $this->registry->get('foodalchemist.gps.ENRICH')->execute(['gp_id' => $id], $this->kontext)->data['run_id'];

    $all = $this->registry->get('foodalchemist.gp_enrich.RESOLVE')->execute(['action' => 'accept_all', 'run_id' => $runId], $this->kontext);
    expect($all->success)->toBeTrue()->and($all->data['uebernommen'])->toBeInt();

    expect($this->registry->get('foodalchemist.gp_enrich.RESOLVE')->execute(['action' => 'accept_all'], $this->kontext)->errorCode)->toBe('VALIDATION_ERROR');
    expect($this->registry->get('foodalchemist.gp_enrich.RESOLVE')->execute(['action' => 'accept'], $this->kontext)->errorCode)->toBe('VALIDATION_ERROR');
    expect($this->registry->get('foodalchemist.gp_enrich.RESOLVE')->execute(['action' => 'quatsch'], $this->kontext)->errorCode)->toBe('VALIDATION_ERROR');
});
