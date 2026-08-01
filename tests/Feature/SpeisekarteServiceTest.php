<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Speisekarte (Gastro-à-la-carte, Stufe A): Karte + Rubrik-Baum + Positionen,
 * Preis-Auflösung pro Position, Referenz-Guards, Owner-Guard (D1).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->karten = app(SpeisekarteService::class);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sk1', 'name' => 'Rinderfilet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 24.00, 'ek_total_eur' => 8.00,
    ]);
    // Kein VK-Rezept (nur Produktionsrezept) → darf nicht pickbar sein.
    $this->prodRezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sk2', 'name' => 'Fond', 'status' => 'approved',
        'is_sales_recipe' => false,
    ]);
});

it('Stufe A: Karte + Rubrik + gericht_ref-Position; Preis flach aus Rezept', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'Abendkarte', 'karten_typ' => 'alacarte']);
    expect($karte->status)->toBe('entwurf')->and($karte->karten_typ)->toBe('alacarte');

    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge', 'art' => 'speisen']);
    $pos = $this->karten->addPosition($this->rootTeam, $rubrik->id, [
        'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id,
    ]);
    expect($pos->type)->toBe('gericht_ref')->and($pos->position)->toBe(1);

    $preis = $this->karten->positionPreis($pos->refresh());
    expect($preis['vk'])->toBe(24.0)->and($preis['quelle'])->toBe('legacy'); // ohne Darreichung → recipes.sales_net
});

it('Stufe A: Manueller Preis übersteuert', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    $pos = $this->karten->addPosition($this->rootTeam, $rubrik->id, [
        'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id,
        'price_mode' => 'manuell', 'price_value' => 29.50,
    ]);
    $preis = $this->karten->positionPreis($pos->refresh());
    expect($preis['vk'])->toBe(29.5)->and($preis['quelle'])->toBe('manuell');
});

it('Stufe A: gericht_ref-Guard weist Nicht-VK-Rezept ab', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    expect(fn () => $this->karten->addPosition($this->rootTeam, $rubrik->id, [
        'type' => 'gericht_ref', 'sales_recipe_id' => $this->prodRezept->id,
    ]))->toThrow(\RuntimeException::class);
});

it('Stufe A: Rubrik-Baum verschachtelt; Zyklus-Schutz beim Verschieben', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $haupt = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $fleisch = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Fleisch'], $haupt->id);

    $tree = $this->karten->rubrikTree($this->rootTeam, $karte->id);
    expect($tree)->toHaveCount(2)
        ->and($tree[0]['depth'])->toBe(0)
        ->and($tree[1]['depth'])->toBe(1)
        ->and($tree[1]['parent_id'])->toBe($haupt->id);

    // Elternteil unter das eigene Kind schieben → Zyklus → Fehler
    expect(fn () => $this->karten->moveRubrik($this->rootTeam, $haupt->id, $fleisch->id))
        ->toThrow(\RuntimeException::class);
});

it('Stufe A: Owner-Guard — Kind-Team kann geerbte Karte nicht pflegen', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'Root-Karte']);
    expect(fn () => $this->karten->update($this->childA, $karte->id, ['name' => 'Hack']))->toThrow(\RuntimeException::class)
        ->and(fn () => $this->karten->addRubrik($this->childA, $karte->id, ['title' => 'X']))->toThrow(\RuntimeException::class);
});
