<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Slice E (MCP): Betrieb-Steuerung per Tool. outlets.POST/GET + outlet_settings.PUT
 * + kalkulation.GET(outlet_id). Der komplette Self-Test-Flow (2. Betrieb anlegen, Override,
 * VK gegenprüfen) läuft damit rein über MCP.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('Registry-Smoke: outlets.GET/POST + outlet_settings.PUT registriert, Schema=object', function () {
    foreach (['foodalchemist.outlets.GET', 'foodalchemist.outlets.POST', 'foodalchemist.outlet_settings.PUT'] as $name) {
        $tool = $this->registry->get($name);
        expect($tool)->not->toBeNull()
            ->and($tool->getSchema()['type'] ?? null)->toBe('object');
    }
});

it('Flow: Betrieb anlegen → Override → outlets.GET spiegelt → kalkulation.GET(outlet) weicht ab', function () {
    // Rezept mit HK1 = 10 €; Team-Marge (Default 15 %) ⇒ VK-Vorschlag 11,50.
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht', ['ek_total_eur' => 10, 'sales_unit_count' => 1]);

    $post = $this->registry->get('foodalchemist.outlets.POST')->execute(['name' => 'Kantine'], $this->kontext);
    expect($post->success)->toBeTrue();
    $outletId = $post->data['id'];

    $put = $this->registry->get('foodalchemist.outlet_settings.PUT')->execute(
        ['outlet_id' => $outletId, 'settings' => ['margin_pct' => 50]], $this->kontext);
    expect($put->success)->toBeTrue()
        ->and($put->data['updated'])->toContain('margin_pct');

    $get = $this->registry->get('foodalchemist.outlets.GET')->execute([], $this->kontext);
    expect($get->success)->toBeTrue()
        ->and($get->data['betriebe'])->toHaveCount(1)
        ->and($get->data['betriebe'][0]['name'])->toBe('Kantine')
        ->and((float) $get->data['betriebe'][0]['overrides']['margin_pct'])->toBe(50.0);

    $base = $this->registry->get('foodalchemist.kalkulation.GET')->execute(['recipe_id' => $gericht->id], $this->kontext);
    $mit = $this->registry->get('foodalchemist.kalkulation.GET')->execute(['recipe_id' => $gericht->id, 'outlet_id' => $outletId], $this->kontext);

    // Team-Marge 15 % ⇒ 11,50; Betriebs-Marge 50 % ⇒ 15,00.
    expect($base->data['kalkulation']['vk_vorschlag'])->toBe(11.5)
        ->and($mit->data['kalkulation']['vk_vorschlag'])->toBe(15.0)
        ->and($mit->data['outlet_id'])->toBe($outletId);
});

it('cross-tenant: outlet_settings.PUT auf einen fremden Betrieb → NOT_FOUND, kein Write', function () {
    $fremd = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);

    $res = $this->registry->get('foodalchemist.outlet_settings.PUT')->execute(
        ['outlet_id' => $fremd->id, 'settings' => ['margin_pct' => 10]], $this->kontext);

    expect($res->success)->toBeFalse();
    // Kein Override am fremden Betrieb angelegt.
    expect(app(OutletSettingsService::class)->for($fremd)->exists)->toBeFalse();
});
