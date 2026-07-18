<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * 07·M3 — LA-First-GP-Mint über MCP: neues Tool gps.MINT_FROM_LA + mint_if_missing
 * an gps.MATCH. Löst den Ruby-Schokolade-Fall (#76) agentisch, statt in der
 * Staging-Sackgasse zu enden. Doktrin: kein GP ohne LA; Mint = tentative + LA-verknüpft.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->mkLa = fn (string $designation) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'designation' => $designation, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
});

it('Registry-Smoke: gps.MINT_FROM_LA registriert + Schema object', function () {
    $tool = $this->registry->get('foodalchemist.gps.MINT_FROM_LA');
    expect($tool)->not->toBeNull()
        ->and($tool->getSchema()['type'] ?? null)->toBe('object')
        ->and($tool->getMetadata()['read_only'])->toBeFalse();   // Schreib-Tool (Lockstep)
});

it('gps.MINT_FROM_LA mintet ein tentatives, LA-verknüpftes GP für eine Lücke mit LA', function () {
    $la = ($this->mkLa)('Ruby-Schokolade');

    $res = $this->registry->get('foodalchemist.gps.MINT_FROM_LA')
        ->execute(['zutat' => 'Ruby-Schokolade'], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['minted'])->toBeTrue()
        ->and($res->data['gp']['status'])->toBe('tentative')
        ->and($res->data['gp']['requires_la'])->toBeTrue();

    // Tenancy + LA-Verknüpfung: GP im acting-Team, Struktur LA→GP gesetzt.
    $gp = FoodAlchemistGp::find($res->data['gp']['id']);
    expect($gp->team_id)->toBe($this->rootTeam->id);
    $struktur = FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->first();
    expect($struktur?->gp_id)->toBe($gp->id);
});

it('gps.MINT_FROM_LA ohne passende LA → KEIN GP, sondern Beschaffungs-Wunsch-Hinweis', function () {
    $vorher = FoodAlchemistGp::count();

    $res = $this->registry->get('foodalchemist.gps.MINT_FROM_LA')
        ->execute(['zutat' => 'Marsianische Nichtzutat'], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['minted'])->toBeFalse()
        ->and($res->data['gp'])->toBeNull()
        ->and($res->data['note'])->toContain('gp_proposals.POST')
        ->and(FoodAlchemistGp::count())->toBe($vorher);   // Doktrin: keine LA → kein GP
});

it('gps.MATCH mint_if_missing=true mintet bei target=none mit passender LA', function () {
    ($this->mkLa)('Tahin');

    $res = $this->registry->get('foodalchemist.gps.MATCH')
        ->execute(['zutat' => 'Tahin', 'mint_if_missing' => true], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['minted'])->toBeTrue()
        ->and($res->data['best_match']['target'])->toBe('gp')
        ->and($res->data['best_match']['gp_id'])->not->toBeNull();

    expect(FoodAlchemistGp::find($res->data['best_match']['gp_id'])->status->value)->toBe('tentative');
});

it('gps.MATCH OHNE Flag mintet nicht — bleibt none (Read-Default)', function () {
    ($this->mkLa)('Tahin');
    $vorher = FoodAlchemistGp::count();

    $res = $this->registry->get('foodalchemist.gps.MATCH')
        ->execute(['zutat' => 'Tahin'], $this->kontext);

    expect($res->data['minted'])->toBeFalse()
        ->and($res->data['best_match']['target'])->toBe('none')
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});

it('07·M4: gp_proposals.POST erfasst einen Beschaffungs-Wunsch (kein GP)', function () {
    $vorher = FoodAlchemistGp::count();

    $res = $this->registry->get('foodalchemist.gp_proposals.POST')->execute([
        'name' => 'Ruby-Schokolade', 'reasoning' => 'Kein GP, keine LA — Artikel muss beschafft werden.',
    ], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['created'])->toBeTrue()
        ->and($res->data['sourcing_request']['name'])->toBe('Ruby-Schokolade')   // reframed key
        ->and($res->data['sourcing_request']['status'])->toBe('offen')
        ->and($res->data['note'])->toContain('Beschaffungs-Wunsch')
        ->and(FoodAlchemistGp::count())->toBe($vorher);   // Doktrin: Wunsch ≠ GP

    // Idempotenz: gleicher Name → bestehender Wunsch, kein Dublett.
    $wieder = $this->registry->get('foodalchemist.gp_proposals.POST')->execute([
        'name' => 'Ruby-Schokolade', 'reasoning' => 'nochmal',
    ], $this->kontext);
    expect($wieder->data['created'])->toBeFalse();
});
