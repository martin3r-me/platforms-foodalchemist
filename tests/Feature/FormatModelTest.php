<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatImage;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/** F2e-Helfer: ein Concept als Format-Slot referenzieren (Referenz-Modell statt format_id-Besitz). */
function slotConcept(FoodAlchemistFormat $f, FoodAlchemistConcept $c, int $position = 0): FoodAlchemistFormatSlot
{
    return FoodAlchemistFormatSlot::create([
        'team_id' => $f->team_id, 'format_id' => $f->id, 'type' => 'concept',
        'concept_id' => $c->id, 'position' => $position,
    ]);
}

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Modul (Phase A) — Datenmodell + Tenancy (D1).
 * Ein Format ist ein team-eigener Marken-Container über den Concepts: sichtbar die
 * Kette aufwärts, editierbar nur beim Besitzer; Editionen sind Ownership-FK; die
 * Preis-Range ist eine reine Reduktion (kein Recompute).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
});

it('D1: ein Format ist sichtbar für Besitzer + Kind (Kette aufwärts), nicht für Geschwister', function () {
    // Format am Root → Kinder erben lesend.
    $rootFormat = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    expect(FoodAlchemistFormat::visibleToTeam($this->rootTeam)->whereKey($rootFormat->id)->exists())->toBeTrue()
        ->and(FoodAlchemistFormat::visibleToTeam($this->childA)->whereKey($rootFormat->id)->exists())->toBeTrue()
        ->and(FoodAlchemistFormat::visibleToTeam($this->childB)->whereKey($rootFormat->id)->exists())->toBeTrue();

    // Format an Kind A → NICHT sichtbar für Geschwister B und NICHT für den Root.
    $childFormat = FoodAlchemistFormat::create(['team_id' => $this->childA->id, 'name' => 'EAT<PLAY/LOVE']);
    expect(FoodAlchemistFormat::visibleToTeam($this->childA)->whereKey($childFormat->id)->exists())->toBeTrue()
        ->and(FoodAlchemistFormat::visibleToTeam($this->childB)->whereKey($childFormat->id)->exists())->toBeFalse()
        ->and(FoodAlchemistFormat::visibleToTeam($this->rootTeam)->whereKey($childFormat->id)->exists())->toBeFalse();
});

it('D1: isOwnedBy nur beim Besitzer-Team', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    expect($f->isOwnedBy($this->rootTeam))->toBeTrue()
        ->and($f->isOwnedBy($this->childA))->toBeFalse();
});

it('priceRange: read-only Min–Max über price_per_person_cache der Referenz-Concepts (0/null ignoriert)', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    slotConcept($f, FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'A', 'price_per_person_cache' => 42.50]), 0);
    slotConcept($f, FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'B', 'price_per_person_cache' => 49.50]), 1);
    slotConcept($f, FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'C', 'price_per_person_cache' => null]), 2);
    slotConcept($f, FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'D', 'price_per_person_cache' => 0]), 3);

    $range = $f->load('slots.concept')->priceRange();
    expect($range['min'])->toBe(42.50)->and($range['max'])->toBe(49.50);
});

it('priceRange: leer wenn keine Referenz-Concepts Preise haben', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'Leer']);
    expect($f->load('slots.concept')->priceRange())->toBe(['min' => null, 'max' => null]);
});

it('images: Galerie sortiert + genau ein Hero über heroImage()', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    FoodAlchemistFormatImage::create(['team_id' => $this->rootTeam->id, 'format_id' => $f->id, 'caption' => 'Zweitbild', 'is_hero' => false, 'sort_order' => 20]);
    $hero = FoodAlchemistFormatImage::create(['team_id' => $this->rootTeam->id, 'format_id' => $f->id, 'caption' => 'Hero', 'is_hero' => true, 'sort_order' => 10]);

    expect($f->images()->pluck('caption')->all())->toBe(['Hero', 'Zweitbild'])
        ->and($f->heroImage?->id)->toBe($hero->id);
});
