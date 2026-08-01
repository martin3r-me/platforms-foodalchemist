<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Speisekarte Stufe D — Fix-Menü (menue_ref → Concept): Preis aus Concept-€/Person,
 * Gänge im Dokument via WordingResolver::gerichtZeilen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->karten = app(SpeisekarteService::class);
    $this->concepts = app(ConceptService::class);

    $vorspeise = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sm-vor', 'name' => 'Carpaccio', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 12.00, 'ek_total_eur' => 4.00,
    ]);
    $haupt = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sm-hpt', 'name' => 'Rinderfilet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 28.00, 'ek_total_eur' => 10.00,
    ]);

    $this->menu = $this->concepts->create($this->rootTeam, ['name' => '3-Gang-Menü']);
    $s1 = $this->concepts->addSlot($this->rootTeam, $this->menu->id, ['role' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $s1->id, ['sales_recipe_id' => $vorspeise->id]);
    $s2 = $this->concepts->addSlot($this->rootTeam, $this->menu->id, ['role' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $s2->id, ['sales_recipe_id' => $haupt->id]);
});

it('Stufe D: menue_ref-Position — Preis aus Concept, Gänge im Dokument', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'Menükarte']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Menüs', 'art' => 'menue']);
    $pos = $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'menue_ref', 'concept_id' => $this->menu->id]);

    $preis = $this->karten->positionPreis($pos->refresh());
    expect($preis['quelle'])->toBe('concept')->and($preis['vk'])->toBeGreaterThan(0.0);

    $dok = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh());
    $menuPos = $dok['rubriken'][0]['positionen'][0];
    expect($menuPos['typ'])->toBe('menue_ref')
        ->and($menuPos['gaenge'])->not->toBeEmpty();
    $texte = collect($menuPos['gaenge'])->pluck('text');
    expect($texte->contains('Carpaccio'))->toBeTrue()
        ->and($texte->contains('Rinderfilet'))->toBeTrue();
});

it('Stufe D: menue_ref-Guard weist Nicht-Concept ab', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    expect(fn () => $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'menue_ref', 'concept_id' => 999999]))
        ->toThrow(\RuntimeException::class);
});
