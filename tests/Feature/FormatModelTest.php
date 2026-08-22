<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatImage;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

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

it('editions: Concepts mit format_id in format_position-Reihenfolge', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    $c1 = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FUTURE FLAVORS', 'format_id' => $f->id, 'format_position' => 1]);
    $c0 = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FARM TO TABLE', 'format_id' => $f->id, 'format_position' => 0]);
    // freistehendes Concept ohne Format zählt nicht mit
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Standalone']);

    $namen = $f->editions()->pluck('name')->all();
    expect($namen)->toBe(['FARM TO TABLE', 'FUTURE FLAVORS']);
});

it('priceRange: read-only Min–Max über price_per_person_cache der Editionen (0/null ignoriert)', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'A', 'format_id' => $f->id, 'price_per_person_cache' => 42.50]);
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'B', 'format_id' => $f->id, 'price_per_person_cache' => 49.50]);
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'C', 'format_id' => $f->id, 'price_per_person_cache' => null]);
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'D', 'format_id' => $f->id, 'price_per_person_cache' => 0]);

    $range = $f->load('editions')->priceRange();
    expect($range['min'])->toBe(42.50)->and($range['max'])->toBe(49.50);
});

it('priceRange: leer wenn keine Editionen Preise haben', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'Leer']);
    expect($f->load('editions')->priceRange())->toBe(['min' => null, 'max' => null]);
});

it('images: Galerie sortiert + genau ein Hero über heroImage()', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    FoodAlchemistFormatImage::create(['team_id' => $this->rootTeam->id, 'format_id' => $f->id, 'caption' => 'Zweitbild', 'is_hero' => false, 'sort_order' => 20]);
    $hero = FoodAlchemistFormatImage::create(['team_id' => $this->rootTeam->id, 'format_id' => $f->id, 'caption' => 'Hero', 'is_hero' => true, 'sort_order' => 10]);

    expect($f->images()->pluck('caption')->all())->toBe(['Hero', 'Zweitbild'])
        ->and($f->heroImage?->id)->toBe($hero->id);
});
