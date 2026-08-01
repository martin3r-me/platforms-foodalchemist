<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Speisekarte Stufe D — Duplizieren (Wechsel-/Saisonkarte): tiefer Kopier von Baum + Positionen. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->karten = app(SpeisekarteService::class);
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sd1', 'name' => 'Schnitzel', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 18.00, 'ek_total_eur' => 6.00,
    ]);
});

it('Stufe D: dupliziere kopiert Rubrik-Baum + Positionen als neuen Entwurf', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'Standardkarte', 'status' => 'aktiv']);
    $haupt = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $fleisch = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Fleisch'], $haupt->id);
    $this->karten->addPosition($this->rootTeam, $fleisch->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);

    $kopie = $this->karten->dupliziere($this->rootTeam, $karte->id, ['name' => 'Sommerkarte', 'karten_typ' => 'saisonkarte']);

    expect($kopie->name)->toBe('Sommerkarte')
        ->and($kopie->status)->toBe('entwurf')
        ->and($kopie->karten_typ)->toBe('saisonkarte')
        ->and($kopie->id)->not->toBe($karte->id);

    $kopie->load('sections.items');
    expect($kopie->sections)->toHaveCount(2);
    // Baum-Struktur erhalten: das Kind zeigt auf die kopierte Eltern-Rubrik.
    $kHaupt = $kopie->sections->firstWhere('title', 'Hauptgänge');
    $kFleisch = $kopie->sections->firstWhere('title', 'Fleisch');
    expect($kFleisch->parent_id)->toBe($kHaupt->id)
        ->and($kFleisch->items)->toHaveCount(1)
        ->and($kFleisch->items->first()->sales_recipe_id)->toBe($this->gericht->id);
});

it('Stufe D MCP: speisekarten.DUPLICATE + speisekarte_rubrik.PUT', function () {
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);

    $karte = $this->karten->create($this->rootTeam, ['name' => 'Basis']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Vorspeisen']);

    $put = $registry->get('foodalchemist.speisekarte_rubrik.PUT')->execute([
        'rubrik_id' => $rubrik->id, 'consumer_title' => 'Zum Auftakt',
    ], $ctx);
    expect($put->success)->toBeTrue()->and($put->data['rubrik']['consumer_title'])->toBe('Zum Auftakt');

    $dup = $registry->get('foodalchemist.speisekarten.DUPLICATE')->execute([
        'speisekarte_id' => $karte->id, 'name' => 'Kopie X',
    ], $ctx);
    expect($dup->success)->toBeTrue()
        ->and($dup->data['speisekarte']['name'])->toBe('Kopie X')
        ->and($dup->data['speisekarte']['rubriken'])->toBe(1);
});
