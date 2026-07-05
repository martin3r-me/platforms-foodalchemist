<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M11-01: Foodbook-Schema + Model-Relationen — Mappe → Kapitel-Baum → Blöcke
 * (concept_ref / recipe_ref / header). Kunde + Pax leben am Foodbook (D-CON-5, F-12).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->concept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Grill-Buffet']);
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g1', 'name' => 'Gruß aus der Küche',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 2.50,
    ]);
});

it('Foodbook: Mappe mit Kunde + Pax, Kapitel-Baum, Blöcke (concept_ref + recipe_ref + header)', function () {
    $fb = FoodAlchemistFoodbook::create([
        'team_id' => $this->rootTeam->id, 'code' => 'FB2027', 'label' => 'Angebot Hotel Adler',
        'jahr' => 2027, 'customer' => 'Hotel Adler', 'personen' => 120, 'status' => 'draft',
    ]);

    $top = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'title' => 'Abendveranstaltung', 'position' => 0]);
    $sub = $fb->kapitel()->create(['team_id' => $this->rootTeam->id, 'parent_id' => $top->id, 'title' => 'Hauptgang', 'position' => 0]);

    $top->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'header', 'label' => 'Willkommen', 'position' => 0]);
    $sub->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'concept_ref', 'concept_id' => $this->concept->id, 'position' => 0]);
    $sub->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'position' => 1]);

    expect($fb->customer)->toBe('Hotel Adler')
        ->and($fb->personen)->toBe(120)
        ->and($fb->kapitel()->count())->toBe(2)
        ->and($top->children()->count())->toBe(1)
        ->and($sub->blocks()->count())->toBe(2)
        ->and($sub->blocks()->where('type', 'concept_ref')->first()->concept->name)->toBe('Grill-Buffet')
        ->and($sub->blocks()->where('type', 'recipe_ref')->first()->gericht->name)->toBe('Gruß aus der Küche');
});

it('Team-Hierarchie: Kind sieht Eltern-Foodbooks; Besitzer-Check (D1)', function () {
    $rootFb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Root-FB']);
    $childFb = FoodAlchemistFoodbook::create(['team_id' => $this->childA->id, 'label' => 'Kind-FB']);

    expect(FoodAlchemistFoodbook::visibleToTeam($this->childA)->pluck('label')->all())
        ->toContain('Root-FB')->toContain('Kind-FB')
        ->and(FoodAlchemistFoodbook::visibleToTeam($this->rootTeam)->pluck('label')->all())
        ->toContain('Root-FB')->not->toContain('Kind-FB')
        ->and($rootFb->isOwnedBy($this->rootTeam))->toBeTrue()
        ->and($rootFb->isOwnedBy($this->childA))->toBeFalse();
});
