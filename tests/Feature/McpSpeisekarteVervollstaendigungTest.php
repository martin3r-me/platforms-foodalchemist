<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D8: Speisekarte-Vervollständigung — List/Status/Branding/Customer-Link +
 * Rubrik-Bausteine (Delete/Move) + Position-Edit + Wording. GET auf `speisekarten.GET` vereinheitlicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->svc = app(SpeisekarteService::class);
    $this->karte = $this->svc->create($this->rootTeam, ['name' => 'Sommerkarte']);
    $this->rubrik = $this->svc->addRubrik($this->rootTeam, $this->karte->id, ['title' => 'Vorspeisen']);
    $this->pos = $this->svc->addPosition($this->rootTeam, $this->rubrik->id, ['label' => 'Suppe']);
});

it('Registry-Smoke: alle 8 D8-Tools + GET-Rename registriert', function () {
    $namen = [
        'speisekarten.LIST', 'speisekarten.STATUS', 'speisekarte.BRANDING', 'speisekarte.CUSTOMER_LINK',
        'speisekarte_rubrik.DELETE', 'speisekarte_rubrik.MOVE', 'speisekarte_positionen.PUT',
        'speisekarte_wording.GENERATE', 'speisekarten.GET',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
    // Alt-Singular ist bewusst weg
    expect($this->registry->get('foodalchemist.speisekarte.GET'))->toBeNull();
});

it('LIST / STATUS / BRANDING / CUSTOMER_LINK', function () {
    $list = ($this->run)('foodalchemist.speisekarten.LIST', []);
    expect($list->success)->toBeTrue()->and($list->data['total'])->toBeGreaterThanOrEqual(1);

    $st = ($this->run)('foodalchemist.speisekarten.STATUS', ['id' => $this->karte->id, 'status' => 'aktiv']);
    expect($st->success)->toBeTrue('status: ' . ($st->error ?? ''));
    expect($this->karte->fresh()->statusWert()->value)->toBe('aktiv');

    $br = ($this->run)('foodalchemist.speisekarte.BRANDING', ['id' => $this->karte->id, 'brand_color' => '#abcdef']);
    expect($br->success)->toBeTrue('branding: ' . ($br->error ?? ''));
    expect($this->karte->fresh()->brand_color)->toBe('#abcdef');

    $cl = ($this->run)('foodalchemist.speisekarte.CUSTOMER_LINK', ['id' => $this->karte->id, 'company_id' => 42]);
    expect($cl->success)->toBeTrue('link: ' . ($cl->error ?? ''))->and($cl->data['crm_company_id'])->toBe(42);
});

it('speisekarte_rubrik: MOVE / DELETE + speisekarte_positionen.PUT', function () {
    $rub2 = $this->svc->addRubrik($this->rootTeam, $this->karte->id, ['title' => 'Hauptgänge']);

    $mv = ($this->run)('foodalchemist.speisekarte_rubrik.MOVE', ['rubrik_id' => $rub2->id, 'new_parent_id' => $this->rubrik->id]);
    expect($mv->success)->toBeTrue('move: ' . ($mv->error ?? ''));
    expect((int) FoodAlchemistSpeisekarteRubrik::find($rub2->id)->parent_id)->toBe($this->rubrik->id);

    $put = ($this->run)('foodalchemist.speisekarte_positionen.PUT', ['position_id' => $this->pos->id, 'felder' => ['consumer_text' => 'Hausgemacht']]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));
    expect(FoodAlchemistSpeisekartePosition::find($this->pos->id)->consumer_text)->toBe('Hausgemacht');

    $del = ($this->run)('foodalchemist.speisekarte_rubrik.DELETE', ['rubrik_id' => $rub2->id, 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
    expect(FoodAlchemistSpeisekarteRubrik::find($rub2->id))->toBeNull();
});

it('speisekarte_wording.GENERATE + GET-Modernisierung', function () {
    $w = ($this->run)('foodalchemist.speisekarte_wording.GENERATE', ['speisekarte_id' => $this->karte->id]);
    expect($w->success)->toBeTrue('wording: ' . ($w->error ?? ''))->and($w->data)->toHaveKey('updated_positions');

    $get = ($this->run)('foodalchemist.speisekarten.GET', ['speisekarte_id' => $this->karte->id]);
    expect($get->success)->toBeTrue()
        ->and($get->data['speisekarte'])->toHaveKeys(['kontext', 'branding', 'kunde', 'rubriken']);
});

it('Guards: confirm-gate, unbekannt NOT_FOUND, fremd ACCESS_DENIED', function () {
    expect(($this->run)('foodalchemist.speisekarte_rubrik.DELETE', ['rubrik_id' => $this->rubrik->id])->errorCode)->toBe('CONFIRM_REQUIRED');
    expect(($this->run)('foodalchemist.speisekarten.STATUS', ['id' => 999999, 'status' => 'aktiv'])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.speisekarten.STATUS', ['id' => $this->karte->id, 'status' => 'aktiv'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->run)('foodalchemist.speisekarte_positionen.PUT', ['position_id' => $this->pos->id, 'felder' => ['label' => 'x']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});
