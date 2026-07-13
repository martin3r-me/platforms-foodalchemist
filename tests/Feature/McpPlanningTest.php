<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R4.1 — MCP im Lockstep: planning.GET/PUT (Brief → Gerüst in einem Call,
 * Lineage created_via=mcp_tool, Freigabe bleibt menschlich, team-scoped).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->foodbook = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Root-FB']);
});

it('planning.GET/PUT sind registriert und schema-valide', function () {
    foreach (['foodalchemist.planning.GET', 'foodalchemist.planning.PUT'] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name)
            ->and($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('PUT übersetzt ein Brief in ein Gerüst (ein Call: head + slots + rules) — Lineage mcp_tool', function () {
    $ergebnis = $this->registry->get('foodalchemist.planning.PUT')->execute([
        'owner_type' => 'foodbook', 'owner_id' => $this->foodbook->id,
        'head' => ['target_price_pp' => 45, 'note' => 'Sommerfest 120 Pax'],
        'slots' => [
            ['label' => 'Vorspeisen', 'slot_type' => 'gang', 'target_count' => 3,
                'rules' => [['rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 1, 'unit' => 'count']]],
            ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 4, 'is_pflicht' => true],
        ],
        'rules' => [['rule_type' => 'nogo_allergen', 'ref_key' => 'peanuts']],
    ], $this->kontext);

    expect($ergebnis->success)->toBeTrue()
        ->and($ergebnis->data['geruest']['slots'])->toHaveCount(2)
        ->and($ergebnis->data['geruest']['slots'][0]['rules'])->toHaveCount(1)
        ->and($ergebnis->data['geruest']['rules'][0]['ref_key'])->toBe('peanuts')
        ->and($ergebnis->data['prompt_kontext'])->toContain('Zielpreis 45,00');

    $frame = FoodAlchemistPlanningFrame::first();
    expect($frame->created_via)->toBe('mcp_tool')
        ->and($frame->status)->toBe('draft');

    $get = $this->registry->get('foodalchemist.planning.GET')->execute([
        'owner_type' => 'foodbook', 'owner_id' => $this->foodbook->id,
    ], $this->kontext);
    expect($get->success)->toBeTrue()
        ->and($get->data['existiert'])->toBeTrue()
        ->and($get->data['geruest']['target_price_pp'])->toBe(45.0);
});

it('PUT blockt status-Setzen (Freigabe bleibt menschlich) und validiert Regeln typisiert', function () {
    $mitStatus = $this->registry->get('foodalchemist.planning.PUT')->execute([
        'owner_type' => 'foodbook', 'owner_id' => $this->foodbook->id,
        'head' => ['status' => 'aktiv'],
    ], $this->kontext);
    expect($mitStatus->success)->toBeFalse();

    $kaputteRegel = $this->registry->get('foodalchemist.planning.PUT')->execute([
        'owner_type' => 'foodbook', 'owner_id' => $this->foodbook->id,
        'rules' => [['rule_type' => 'diet_quota', 'ref_key' => 'flexitarisch', 'value_num' => 1, 'unit' => 'count']],
    ], $this->kontext);
    expect($kaputteRegel->success)->toBeFalse();
});

it('GET: fremdes Konzept (Geschwister-Team) ist NOT_FOUND — kein Cross-Team-Leak', function () {
    $conceptB = FoodAlchemistConcept::create(['team_id' => $this->childB->id, 'name' => 'Fremd-Konzept']);
    $kindUser = $this->makeUser($this->childA, 'Kind A');
    $kindKontext = new ToolContext($kindUser, $this->childA);

    $ergebnis = $this->registry->get('foodalchemist.planning.GET')->execute([
        'owner_type' => 'concept', 'owner_id' => $conceptB->id,
    ], $kindKontext);
    expect($ergebnis->success)->toBeFalse();
});
