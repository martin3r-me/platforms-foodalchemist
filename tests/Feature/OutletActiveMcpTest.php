<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — outlets.SET_ACTIVE: die Betriebs-„Brille" per MCP setzen (durabel je User+Team),
 * sodass Reads ohne explizites outlet_id automatisch gegen den Betrieb rechnen. Deckt die
 * Lücke, dass MCP die Web-Session des Sidebar-Dropdowns nicht teilt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    // Rezept HK1 10 € ⇒ Team-Marge 15 % = VK 11,50; Betrieb-Marge 50 % = VK 15,00.
    $this->gericht = $this->makeRecipe($this->rootTeam, 'Gericht', ['ek_total_eur' => 10, 'sales_unit_count' => 1]);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Testbetrieb Nord']);
    app(OutletSettingsService::class)->update($this->rootTeam, $this->betrieb, ['margin_pct' => 50]);
});

it('Registry-Smoke: outlets.SET_ACTIVE registriert, Schema=object', function () {
    $tool = $this->registry->get('foodalchemist.outlets.SET_ACTIVE');
    expect($tool)->not->toBeNull()->and($tool->getSchema()['type'] ?? null)->toBe('object');
});

it('SET_ACTIVE setzt die Brille → outlets.GET zeigt aktiv → kalkulation.GET ohne outlet_id folgt dem Betrieb', function () {
    // Vorher: ohne Brille rechnet kalkulation.GET Team-Baseline.
    $base = $this->registry->get('foodalchemist.kalkulation.GET')->execute(['recipe_id' => $this->gericht->id], $this->kontext);
    expect($base->data['kalkulation']['vk_vorschlag'])->toBe(11.5)
        ->and($base->data['outlet_id'] ?? null)->toBeNull();

    $set = $this->registry->get('foodalchemist.outlets.SET_ACTIVE')->execute(['outlet_id' => $this->betrieb->id], $this->kontext);
    expect($set->success)->toBeTrue()
        ->and($set->data['active_outlet_id'])->toBe($this->betrieb->id)
        ->and($set->data['team_baseline'])->toBeFalse();

    // outlets.GET spiegelt die aktive Brille.
    $get = $this->registry->get('foodalchemist.outlets.GET')->execute([], $this->kontext);
    expect($get->data['active_outlet_id'])->toBe($this->betrieb->id)
        ->and($get->data['active_outlet_name'])->toBe('Testbetrieb Nord');

    // kalkulation.GET OHNE outlet_id rechnet jetzt gegen den Betrieb (Brille wirkt).
    $mit = $this->registry->get('foodalchemist.kalkulation.GET')->execute(['recipe_id' => $this->gericht->id], $this->kontext);
    expect($mit->data['kalkulation']['vk_vorschlag'])->toBe(15.0)
        ->and($mit->data['outlet_id'])->toBe($this->betrieb->id);

    // Zurück auf Team-Baseline.
    $reset = $this->registry->get('foodalchemist.outlets.SET_ACTIVE')->execute([], $this->kontext);
    expect($reset->data['team_baseline'])->toBeTrue();
    $wieder = $this->registry->get('foodalchemist.kalkulation.GET')->execute(['recipe_id' => $this->gericht->id], $this->kontext);
    expect($wieder->data['kalkulation']['vk_vorschlag'])->toBe(11.5);
});

it('durabel: die Brille überlebt ohne Session (MCP-Realität) — kalkulation.GET liest den durablen Speicher', function () {
    $this->registry->get('foodalchemist.outlets.SET_ACTIVE')->execute(['outlet_id' => $this->betrieb->id], $this->kontext);

    // MCP teilt die Web-Session NICHT — simulieren, indem wir sie leeren: der durable Store muss tragen.
    session()->flush();

    $mit = $this->registry->get('foodalchemist.kalkulation.GET')->execute(['recipe_id' => $this->gericht->id], $this->kontext);
    expect($mit->data['kalkulation']['vk_vorschlag'])->toBe(15.0)
        ->and($mit->data['outlet_id'])->toBe($this->betrieb->id);
});

it('SET_ACTIVE mit fremdem/inaktivem Betrieb → NOT_FOUND (kein stiller Reset)', function () {
    $fremd = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);
    $res = $this->registry->get('foodalchemist.outlets.SET_ACTIVE')->execute(['outlet_id' => $fremd->id], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});
