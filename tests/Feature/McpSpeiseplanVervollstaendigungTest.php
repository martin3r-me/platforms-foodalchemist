<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D9: Speiseplan-Vervollständigung — Read-Lücke (GET/LIST) + Stamm/Status/Branding/
 * CRM + Linien-CRUD + Eintrag-Bausteine + Ausrollen. Kein Plan-Delete via MCP (Status archiviert).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->svc = app(SpeiseplanService::class);
    $this->plan = $this->svc->create($this->rootTeam, ['name' => 'Kantine KW', 'start_date' => '2027-01-04', 'cycle_weeks' => 1]);
    $this->linie = $this->svc->addLinie($this->rootTeam, $this->plan->id, ['name' => 'Menü 1']);
    $this->eintrag = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2027-01-04', 'mahlzeit' => 'mittag', 'line_id' => $this->linie->id]);
});

it('Registry-Smoke: alle 13 D9-Tools registriert mit type=object', function () {
    $namen = [
        'speiseplaene.GET', 'speiseplaene.LIST', 'speiseplaene.PUT', 'speiseplaene.STATUS',
        'speiseplan.BRANDING', 'speiseplan.CUSTOMER_LINK',
        'speiseplan_linien.POST', 'speiseplan_linien.PUT', 'speiseplan_linien.DELETE', 'speiseplan_linien.MOVE',
        'speiseplan_eintraege.DELETE', 'speiseplan_eintraege.PAX', 'speiseplan.AUSROLLEN',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('GET (Read-Lücke) / LIST / PUT / STATUS', function () {
    $get = ($this->run)('foodalchemist.speiseplaene.GET', ['id' => $this->plan->id]);
    expect($get->success)->toBeTrue('get: ' . ($get->error ?? ''))
        ->and($get->data['speiseplan']['name'])->toBe('Kantine KW')
        ->and(count($get->data['speiseplan']['linien']))->toBeGreaterThanOrEqual(1);

    $list = ($this->run)('foodalchemist.speiseplaene.LIST', []);
    expect($list->success)->toBeTrue()->and($list->data['total'])->toBeGreaterThanOrEqual(1);

    $put = ($this->run)('foodalchemist.speiseplaene.PUT', ['id' => $this->plan->id, 'felder' => ['default_pax' => 120, 'cycle_weeks' => 2]]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));
    expect((int) $this->plan->fresh()->default_pax)->toBe(120);

    $st = ($this->run)('foodalchemist.speiseplaene.STATUS', ['id' => $this->plan->id, 'status' => 'aktiv']);
    expect($st->success)->toBeTrue('status: ' . ($st->error ?? ''));
    expect($this->plan->fresh()->statusWert()->value)->toBe('aktiv');
});

it('BRANDING / CUSTOMER_LINK', function () {
    $br = ($this->run)('foodalchemist.speiseplan.BRANDING', ['id' => $this->plan->id, 'brand_color' => '#00ff88']);
    expect($br->success)->toBeTrue('branding: ' . ($br->error ?? ''));
    expect($this->plan->fresh()->brand_color)->toBe('#00ff88');

    $cl = ($this->run)('foodalchemist.speiseplan.CUSTOMER_LINK', ['id' => $this->plan->id, 'company_id' => 42]);
    expect($cl->success)->toBeTrue('link: ' . ($cl->error ?? ''))->and($cl->data['crm_company_id'])->toBe(42);
});

it('Linien: POST / PUT / MOVE / DELETE', function () {
    $post = ($this->run)('foodalchemist.speiseplan_linien.POST', ['plan_id' => $this->plan->id, 'name' => 'Vegetarisch', 'is_vegetarian' => true]);
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''));
    $l2 = $post->data['linie_id'];

    $put = ($this->run)('foodalchemist.speiseplan_linien.PUT', ['linie_id' => $l2, 'felder' => ['name' => 'Vegan']]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));
    expect(FoodAlchemistSpeiseplanLinie::find($l2)->name)->toBe('Vegan');

    $mv = ($this->run)('foodalchemist.speiseplan_linien.MOVE', ['linie_id' => $l2, 'direction' => 'up']);
    expect($mv->success)->toBeTrue('move: ' . ($mv->error ?? ''));

    $del = ($this->run)('foodalchemist.speiseplan_linien.DELETE', ['linie_id' => $l2, 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
    expect(FoodAlchemistSpeiseplanLinie::find($l2))->toBeNull();
});

it('Einträge: PAX / DELETE + AUSROLLEN', function () {
    $pax = ($this->run)('foodalchemist.speiseplan_eintraege.PAX', ['eintrag_id' => $this->eintrag->id, 'pax' => 90]);
    expect($pax->success)->toBeTrue('pax: ' . ($pax->error ?? ''));

    $roll = ($this->run)('foodalchemist.speiseplan.AUSROLLEN', ['plan_id' => $this->plan->id, 'bis_datum' => '2027-02-01', 'confirm' => true]);
    expect($roll->success)->toBeTrue('roll: ' . ($roll->error ?? ''))->and($roll->data)->toHaveKey('created_entries');

    $del = ($this->run)('foodalchemist.speiseplan_eintraege.DELETE', ['eintrag_id' => $this->eintrag->id, 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
    expect(FoodAlchemistSpeiseplanEintrag::find($this->eintrag->id))->toBeNull();
});

it('Guards: confirm-gate, unbekannt NOT_FOUND, fremd ACCESS_DENIED', function () {
    expect(($this->run)('foodalchemist.speiseplan_linien.DELETE', ['linie_id' => $this->linie->id])->errorCode)->toBe('CONFIRM_REQUIRED');
    expect(($this->run)('foodalchemist.speiseplaene.GET', ['id' => 999999])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.speiseplaene.PUT', ['id' => $this->plan->id, 'felder' => ['default_pax' => 1]], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->run)('foodalchemist.speiseplan_linien.PUT', ['linie_id' => $this->linie->id, 'felder' => ['name' => 'x']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});
