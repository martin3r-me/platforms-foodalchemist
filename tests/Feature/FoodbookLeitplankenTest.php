<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kreative Leitplanken: Foodbook-Guideline (Kundentyp + Default-Niveau + Default-Convenience)
 * + Auflösungs-Kaskade Kapitel/Konzept (concept.level) → Foodbook-Default → Segment.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->fb = app(FoodbookService::class);
    $this->settings = app(TeamSettingsService::class);
});

it('normNiveau kanonisiert haute → haute_cuisine, lässt Kanon durch, null bleibt null', function () {
    expect(TeamSettingsService::normNiveau('haute'))->toBe('haute_cuisine')
        ->and(TeamSettingsService::normNiveau('gehoben'))->toBe('gehoben')
        ->and(TeamSettingsService::normNiveau(''))->toBeNull()
        ->and(TeamSettingsService::normNiveau(null))->toBeNull();
});

it('Kaskade: Segment-Default greift, wenn Foodbook + Kapitel leer sind', function () {
    $this->settings->update($this->rootTeam, ['kitchen_type' => 'catering']); // → niveau gehoben, convenience teil
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook);
    expect($lp['niveau'])->toBe('gehoben')
        ->and($lp['convenience'])->toBe('teil_convenience')
        ->and($lp['niveau_quelle'])->toBe('segment');
});

it('Kaskade: Foodbook-Default überschreibt das Segment', function () {
    $this->settings->update($this->rootTeam, ['kitchen_type' => 'catering']); // Segment = gehoben
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $this->fb->update($this->rootTeam, $foodbook->id, [
        'kundentyp' => 'hotellerie',
        'default_niveau' => 'haute_cuisine',
        'default_convenience' => 'from_scratch',
    ]);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook->refresh());
    expect($lp['kundentyp'])->toBe('hotellerie')
        ->and($lp['niveau'])->toBe('haute_cuisine')
        ->and($lp['convenience'])->toBe('from_scratch')
        ->and($lp['niveau_quelle'])->toBe('foodbook');
});

it('Kaskade: Kapitel-Niveau (concept.level, haute) führt über den Foodbook-Default — normalisiert', function () {
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $this->fb->update($this->rootTeam, $foodbook->id, ['default_niveau' => 'klassisch']);
    $concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Premium-Menü', 'level' => 'haute']);

    $lp = $this->fb->leitplanken($this->rootTeam, $foodbook->refresh(), $concept);
    expect($lp['niveau'])->toBe('haute_cuisine')      // haute → kanonisch, gewinnt über klassisch
        ->and($lp['niveau_quelle'])->toBe('kapitel');
});

it('Livewire: leitplankeSetzen persistiert + leer setzt zurück auf Erben', function () {
    Livewire::test(FoodbooksIndex::class)->call('neu');
    $foodbook = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::first();

    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $foodbook->id)
        ->call('leitplankeSetzen', 'default_niveau', 'haute_cuisine')
        ->call('leitplankeSetzen', 'kundentyp', 'enterprise_kette');

    $foodbook->refresh();
    expect($foodbook->default_niveau)->toBe('haute_cuisine')
        ->and($foodbook->kundentyp)->toBe('enterprise_kette');

    // Leer → zurück auf NULL (erbt wieder)
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $foodbook->id)
        ->call('leitplankeSetzen', 'default_niveau', '');
    expect($foodbook->refresh()->default_niveau)->toBeNull();
});
