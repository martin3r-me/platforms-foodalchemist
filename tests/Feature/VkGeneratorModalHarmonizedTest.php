<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkGeneratorModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #512 (2026-07-20) — VK-„Gericht"-Generator auf Parität zum Basisrezept-
 * Generator: strukturierte Pills statt Freitext (Dominique-Fund
 * „hier sind sogar noch Freitexte"). Sichert die Umstellung ab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('rendert strukturierte Richtungs-Pills statt Freitext', function () {
    Livewire::test(VkGeneratorModal::class)
        ->assertOk()
        // strukturierte Vokabular-Werte sind sichtbar (Pills/Dropdown)
        ->assertSee('Haute Cuisine')
        ->assertSee('Betriebsgastronomie')
        ->assertSee('Low Carb')
        // die alten Freitext-Platzhalter sind weg
        ->assertDontSee('z. B. fine_dining')
        ->assertDontSee('z. B. catering');
});

it('Niveau-Pill setzt parameter.level', function () {
    Livewire::test(VkGeneratorModal::class)
        ->assertSet('parameter.level', '')
        ->call('togglePill', 'level', 'haute_cuisine')
        ->assertSet('parameter.level', 'haute_cuisine');
});

it('Diät ist Multi-Select (hart erzwungen): mehrere Werte, toggle entfernt', function () {
    Livewire::test(VkGeneratorModal::class)
        ->assertSet('parameter.diaet_hart', [])
        ->call('togglePill', 'diaet_hart', 'vegan')
        ->call('togglePill', 'diaet_hart', 'glutenfrei')
        ->assertSet('parameter.diaet_hart', ['vegan', 'glutenfrei'])
        ->call('togglePill', 'diaet_hart', 'vegan')       // erneutes Klicken entfernt
        ->assertSet('parameter.diaet_hart', ['glutenfrei']);
});

it('Bio-Präferenz ist dreiwertig (Default konventionell)', function () {
    Livewire::test(VkGeneratorModal::class)
        ->assertSet('parameter.bio_praeferenz', 'konventionell')
        ->call('togglePill', 'bio_praeferenz', 'bio')
        ->assertSet('parameter.bio_praeferenz', 'bio');
});
