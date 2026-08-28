<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · Phase 0: team_settings.PUT — sichere Skalar-Config schreiben.
 * Deckt Registry-Smoke, Happy-Path + Read-back, Allow-List-Härte (unbekannte Keys),
 * Enum-/Zahl-Validierung und die einzige FK (default_markup_class_id) cross-tenant.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('Registry-Smoke: team_settings.PUT registriert, Schema=object', function () {
    $tool = $this->registry->get('foodalchemist.team_settings.PUT');
    expect($tool)->not->toBeNull();
    expect($tool->getSchema()['type'] ?? null)->toBe('object');
});

it('schreibt Skalar-Config (Allow-List) und settings.GET/Service spiegeln sie', function () {
    $res = $this->registry->get('foodalchemist.team_settings.PUT')->execute([
        'settings' => [
            'ai_active' => false,
            'kitchen_type' => 'catering',
            'target_food_cost_pct' => 30,
            'default_batch_max_kg' => 8.5,
            'cooking_loss_defaults' => ['*' => 12.5],
        ],
    ], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['updated'])->toContain('ai_active', 'kitchen_type', 'target_food_cost_pct');

    $svc = app(TeamSettingsService::class);
    expect($svc->kiAktiv($this->rootTeam))->toBeFalse()
        ->and($svc->kuechenTyp($this->rootTeam))->toBe('catering')
        ->and($svc->zielWareneinsatzPct($this->rootTeam))->toBe(30.0)
        ->and($svc->defaultTopfDeckelKg($this->rootTeam))->toBe(8.5)
        ->and($svc->garverlustDefault($this->rootTeam))->toBe(12.5);

    $get = $this->registry->get('foodalchemist.settings.GET')->execute([], $this->kontext);
    expect($get->data['ai_active'])->toBeFalse()
        ->and($get->data['kitchen_type'])->toBe('catering');
});

it('weist unbekannte/nicht erlaubte Keys ab (VALIDATION_ERROR, nicht still geschrieben)', function () {
    $res = $this->registry->get('foodalchemist.team_settings.PUT')->execute([
        'settings' => ['team_id' => 999, 'ai_active' => false],
    ], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

it('weist ungültigen kitchen_type und negative Zahlen ab', function () {
    $bad1 = $this->registry->get('foodalchemist.team_settings.PUT')->execute(['settings' => ['kitchen_type' => 'foobar']], $this->kontext);
    expect($bad1->success)->toBeFalse()->and($bad1->errorCode)->toBe('VALIDATION_ERROR');

    $bad2 = $this->registry->get('foodalchemist.team_settings.PUT')->execute(['settings' => ['margin_pct' => -5]], $this->kontext);
    expect($bad2->success)->toBeFalse()->and($bad2->errorCode)->toBe('VALIDATION_ERROR');
});

it('leeres settings-Objekt → VALIDATION_ERROR', function () {
    $res = $this->registry->get('foodalchemist.team_settings.PUT')->execute(['settings' => []], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

it('Tenancy: fremd-Team-Preisklasse als default_markup_class_id blockt (NOT_FOUND, nichts geschrieben)', function () {
    // Preisklasse gehört Kind A — für Root (Sichtbarkeit nur aufwärts/global) NICHT sichtbar.
    $fremd = FoodAlchemistMarkupClass::create([
        'team_id' => $this->childA->id, 'code' => 'FRD', 'label' => 'Fremd', 'is_inactive' => false,
    ]);

    $res = $this->registry->get('foodalchemist.team_settings.PUT')->execute([
        'settings' => ['default_markup_class_id' => $fremd->id],
    ], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');

    expect(app(TeamSettingsService::class)->defaultMarkupClassId($this->rootTeam))->toBeNull();
});

it('akzeptiert eigene Preisklasse als default_markup_class_id', function () {
    $eigen = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id, 'code' => 'STD', 'label' => 'Standard', 'is_inactive' => false,
    ]);

    $res = $this->registry->get('foodalchemist.team_settings.PUT')->execute([
        'settings' => ['default_markup_class_id' => $eigen->id],
    ], $this->kontext);
    expect($res->success)->toBeTrue();
    expect(app(TeamSettingsService::class)->defaultMarkupClassId($this->rootTeam))->toBe($eigen->id);
});
