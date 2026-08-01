<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Speisekarte Stufe E — MCP-Read-Lockstep: SEARCH, GET, Leitstelle.GET (team-sichtbar). */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->karten = app(SpeisekarteService::class);

    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'mr1', 'name' => 'Zander', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 22.00,
    ]);
    $g->forceFill(['allergens_confidence' => 'high'])->save();

    $this->karte = $this->karten->create($this->rootTeam, ['name' => 'Fischkarte', 'karten_typ' => 'alacarte']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $this->karte->id, ['title' => 'Fisch']);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g->id]);
});

it('Stufe E MCP: SEARCH findet die Karte', function () {
    $r = $this->registry->get('foodalchemist.speisekarten.SEARCH')->execute(['q' => 'Fisch'], $this->ctx);
    expect($r->success)->toBeTrue();
    expect(collect($r->data['speisekarten'])->pluck('name'))->toContain('Fischkarte');
});

it('Stufe E MCP: GET liefert Rubriken + Positionen + Preis', function () {
    $r = $this->registry->get('foodalchemist.speisekarte.GET')->execute(['speisekarte_id' => $this->karte->id], $this->ctx);
    expect($r->success)->toBeTrue();
    $rubrik = $r->data['speisekarte']['rubriken'][0];
    expect($rubrik['title'])->toBe('Fisch')
        ->and($rubrik['positionen'][0]['name'])->toBe('Zander')
        ->and($rubrik['positionen'][0]['vk_netto'])->toBe(22.0);
});

it('Stufe E MCP: Leitstelle.GET liefert Checkliste + bereit', function () {
    $r = $this->registry->get('foodalchemist.speisekarte_leitstelle.GET')->execute(['speisekarte_id' => $this->karte->id], $this->ctx);
    expect($r->success)->toBeTrue()
        ->and($r->data['bereit'])->toBeTrue()
        ->and(collect($r->data['punkte'])->pluck('key'))->toContain('preise');
});
