<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Speisekarte Stufe D — Wording-Override gewinnt über den Gericht-Namen; Getränke/Wein-
 * Metadaten (Jahrgang/Region/Rebsorte) + manueller Glaspreis.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->karten = app(SpeisekarteService::class);
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sw1', 'name' => 'Rinderfilet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 28.00, 'ek_total_eur' => 10.00,
    ]);
});

it('Stufe D: Wording-Override ersetzt den Standard-Namen im Dokument', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    $pos = $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);
    $this->karten->updatePosition($this->rootTeam, $pos->id, ['wording' => 'Dry Aged Filet vom Weiderind']);

    $dok = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh());
    expect($dok['rubriken'][0]['positionen'][0]['name'])->toBe('Dry Aged Filet vom Weiderind');
});

it('Stufe D: Weinkarte — Metadaten aus payload_json + manueller Glaspreis', function () {
    $wein = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sw-wein', 'name' => 'Riesling', 'status' => 'approved',
        'is_sales_recipe' => true,
    ]);
    $karte = $this->karten->create($this->rootTeam, ['name' => 'Weinkarte', 'karten_typ' => 'weinkarte']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Weißweine', 'art' => 'getraenke']);
    $pos = $this->karten->addPosition($this->rootTeam, $rubrik->id, [
        'type' => 'gericht_ref', 'sales_recipe_id' => $wein->id,
        'price_mode' => 'manuell', 'price_value' => 7.50,   // 0,1 l Glas
        'payload_json' => ['wein' => ['jahrgang' => '2022', 'region' => 'Rheingau', 'rebsorte' => 'Riesling']],
    ]);

    $preis = $this->karten->positionPreis($pos->refresh());
    expect($preis['vk'])->toBe(7.5)->and($preis['quelle'])->toBe('manuell');

    $dok = $this->karten->dokumentDaten($this->rootTeam, $karte->refresh());
    $wp = $dok['rubriken'][0]['positionen'][0];
    expect($wp['wein'])->toMatchArray(['jahrgang' => '2022', 'region' => 'Rheingau', 'rebsorte' => 'Riesling']);
});
