<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabRolle;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10-01: Concepter-Fundament — Schema + Model-Relationen + Team-Hierarchie.
 * Baut das DOEC-„Grill-Buffet"-Beispiel aus Doc 15 §M10 als Fixture.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // Zwei Gerichte (VK-Rezepte) für den Vorspeisen-Paket
    $this->greenPower = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'gp1', 'name' => 'Salat: Green Power',
        'status' => 'approved', 'ist_verkaufsrezept' => true,
    ]);
    $this->sunnyKick = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sk1', 'name' => 'Salat: Sunny Kick',
        'status' => 'approved', 'ist_verkaufsrezept' => true,
    ]);
});

it('legt einen Paket als bepreistes Bündel mehrerer Gerichte an (Salad Wall)', function () {
    $paket = FoodAlchemistPaket::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Salad Wall', 'rolle' => 'Vorspeise',
        'preis_pro_person' => 4.50, 'ek_pro_person' => 1.41, 'wareneinsatz_prozent' => 31.3,
        'preis_modus' => 'manuell',
    ]);
    $paket->gerichte()->create(['team_id' => $this->rootTeam->id, 'vk_recipe_id' => $this->greenPower->id, 'position' => 0]);
    $paket->gerichte()->create(['team_id' => $this->rootTeam->id, 'vk_recipe_id' => $this->sunnyKick->id, 'position' => 1]);

    expect($paket->uuid)->not->toBeNull()                          // HasUuidV7
        ->and($paket->gerichte()->count())->toBe(2)
        ->and((float) $paket->preis_pro_person)->toBe(4.50)
        ->and($paket->gerichte->first()->gericht->name)->toBe('Salat: Green Power');
});

it('baut ein Concept mit Slots: Paket ODER festes Gericht je Slot (Grill-Buffet)', function () {
    $saladWall = FoodAlchemistPaket::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_pro_person' => 4.50,
    ]);

    $concept = FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Grill-Buffet', 'anlass' => 'Sommerfest', 'status' => 'draft',
    ]);
    // Slot A: gefüllt mit Paket (austauschbar)
    $concept->slots()->create([
        'team_id' => $this->rootTeam->id, 'rolle' => 'Vorspeise', 'titel' => 'Vorspeise',
        'position' => 0, 'paket_id' => $saladWall->id,
    ]);
    // Slot B: festes Gericht
    $concept->slots()->create([
        'team_id' => $this->rootTeam->id, 'rolle' => 'Dessert', 'titel' => 'Dessert',
        'position' => 1, 'vk_recipe_id' => $this->greenPower->id, 'menge' => 1,
    ]);

    $slots = $concept->slots()->get();
    expect($slots)->toHaveCount(2)
        ->and($slots[0]->istPaket())->toBeTrue()
        ->and($slots[0]->paket->name)->toBe('Salad Wall')
        ->and($slots[1]->istPaket())->toBeFalse()
        ->and($slots[1]->gericht->name)->toBe('Salat: Green Power');
});

it('Team-Hierarchie: Kind sieht Eltern-Pakete, Besitzer-Check greift (D1)', function () {
    $rootPaket = FoodAlchemistPaket::create(['team_id' => $this->rootTeam->id, 'name' => 'Root-Paket', 'rolle' => 'Vorspeise']);
    $childPaket = FoodAlchemistPaket::create(['team_id' => $this->childA->id, 'name' => 'Kind-A-Paket', 'rolle' => 'Vorspeise']);

    // Kind A sieht eigenen + geerbten (Root); NICHT den von Geschwister-Kind B
    $sichtbarFuerA = FoodAlchemistPaket::visibleToTeam($this->childA)->pluck('name')->all();
    expect($sichtbarFuerA)->toContain('Root-Paket')->toContain('Kind-A-Paket');

    // Root sieht nur eigene (Kinder werden NICHT nach oben sichtbar)
    $sichtbarFuerRoot = FoodAlchemistPaket::visibleToTeam($this->rootTeam)->pluck('name')->all();
    expect($sichtbarFuerRoot)->toContain('Root-Paket')->not->toContain('Kind-A-Paket');

    expect($rootPaket->isOwnedBy($this->rootTeam))->toBeTrue()
        ->and($rootPaket->isOwnedBy($this->childA))->toBeFalse()
        ->and($childPaket->isOwnedBy($this->childA))->toBeTrue();
});

it('Vorlage-Scopes + Rollen-Vokabular', function () {
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => '3-Gang-Vorlage', 'is_vorlage' => true]);
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Grill-Buffet', 'is_vorlage' => false]);

    expect(FoodAlchemistConcept::vorlagen()->count())->toBe(1)
        ->and(FoodAlchemistConcept::echte()->count())->toBe(1);

    $rolle = FoodAlchemistVocabRolle::create(['team_id' => $this->rootTeam->id, 'slug' => 'vorspeise', 'name' => 'Vorspeise']);
    expect($rolle->uuid)->not->toBeNull();
});
