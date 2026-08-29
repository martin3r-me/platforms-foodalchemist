<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D10: Angebote-Vervollständigung — Put/Delete(confirm)/Status/Customer-Link/
 * Recompute + Menü-CRUD + Concept-Referenzen. angebote.DELETE bleibt (frühe Entwürfe).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->svc = app(AngebotService::class);
    $this->angebot = $this->svc->create($this->rootTeam, ['name' => 'Gala 2027', 'personen' => 50]);
});

it('Registry-Smoke: alle 10 D10-Tools registriert mit type=object', function () {
    $namen = [
        'angebote.PUT', 'angebote.DELETE', 'angebote.STATUS', 'angebote.CUSTOMER_LINK', 'angebote.RECOMPUTE',
        'angebot_menue.POST', 'angebot_menue.PROMOTE', 'angebot_menue.DELETE',
        'angebot_concept_ref.POST', 'angebot_concept_ref.DELETE',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('PUT / STATUS / CUSTOMER_LINK / RECOMPUTE', function () {
    $put = ($this->run)('foodalchemist.angebote.PUT', ['id' => $this->angebot->id, 'felder' => ['occasion' => 'Sommerfest', 'personen' => 80]]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));
    expect((int) $this->angebot->fresh()->personen)->toBe(80);

    $st = ($this->run)('foodalchemist.angebote.STATUS', ['id' => $this->angebot->id, 'status' => 'versendet']);
    expect($st->success)->toBeTrue('status: ' . ($st->error ?? ''));
    expect($this->angebot->fresh()->status instanceof \BackedEnum ? $this->angebot->fresh()->status->value : $this->angebot->fresh()->status)->toBe('versendet');

    $cl = ($this->run)('foodalchemist.angebote.CUSTOMER_LINK', ['id' => $this->angebot->id, 'company_id' => 42]);
    expect($cl->success)->toBeTrue('link: ' . ($cl->error ?? ''))->and($cl->data['crm_company_id'])->toBe(42);

    $rc = ($this->run)('foodalchemist.angebote.RECOMPUTE', ['id' => $this->angebot->id]);
    expect($rc->success)->toBeTrue('rc: ' . ($rc->error ?? ''));
});

it('angebot_menue: POST / PROMOTE / DELETE', function () {
    $post = ($this->run)('foodalchemist.angebot_menue.POST', ['angebot_id' => $this->angebot->id, 'name' => 'Galadinner']);
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''));
    $menu1 = $post->data['concept_id'];

    $prom = ($this->run)('foodalchemist.angebot_menue.PROMOTE', ['concept_id' => $menu1]);
    expect($prom->success)->toBeTrue('promote: ' . ($prom->error ?? ''));

    $post2 = ($this->run)('foodalchemist.angebot_menue.POST', ['angebot_id' => $this->angebot->id]);
    $menu2 = $post2->data['concept_id'];
    $del = ($this->run)('foodalchemist.angebot_menue.DELETE', ['concept_id' => $menu2, 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
});

it('angebot_concept_ref: POST / DELETE + GET-Modernisierung', function () {
    $concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Katalog-Menü']);

    $ref = ($this->run)('foodalchemist.angebot_concept_ref.POST', ['angebot_id' => $this->angebot->id, 'concept_id' => $concept->id]);
    expect($ref->success)->toBeTrue('ref: ' . ($ref->error ?? ''));

    $get = ($this->run)('foodalchemist.angebote.GET', ['offer_id' => $this->angebot->id]);
    expect($get->success)->toBeTrue()
        ->and($get->data['angebot'])->toHaveKeys(['crm_company_id', 'menue']);

    $unref = ($this->run)('foodalchemist.angebot_concept_ref.DELETE', ['angebot_id' => $this->angebot->id, 'concept_id' => $concept->id]);
    expect($unref->success)->toBeTrue('unref: ' . ($unref->error ?? ''));
});

it('DELETE (confirm) + Guards', function () {
    expect(($this->run)('foodalchemist.angebote.DELETE', ['id' => $this->angebot->id])->errorCode)->toBe('CONFIRM_REQUIRED');
    expect(($this->run)('foodalchemist.angebote.PUT', ['id' => 999999, 'felder' => ['occasion' => 'x']])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.angebote.PUT', ['id' => $this->angebot->id, 'felder' => ['occasion' => 'x']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');

    $del = ($this->run)('foodalchemist.angebote.DELETE', ['id' => $this->angebot->id, 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
    expect(FoodAlchemistAngebot::find($this->angebot->id))->toBeNull();
});
