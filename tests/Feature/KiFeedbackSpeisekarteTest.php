<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53), Restliste — dieselbe Umstellung wie im Recipe-/VK-Modal
 * ({@see \tests\Feature\KiFeedbackRecipeModalTest}), für die Speisekarte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->karten = app(SpeisekarteService::class);
    $this->karte = $this->karten->create($this->rootTeam, ['name' => 'KI-Feedback-Karte']);
});

it('Speisekarte: „Wording" (speisekarteWordingGenerieren) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(Index::class)->call('waehle', $this->karte->id)->html();

    expect($html)->toContain('data-ki-action="speisekarteWordingGenerieren"')
        ->toContain('wire:target="speisekarteWordingGenerieren"');
});

it('Speisekarte: „KI-Einleitung" (kiKartenText) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(Index::class)->call('waehle', $this->karte->id)->html();

    expect($html)->toContain('data-ki-action="kiKartenText"')
        ->toContain('wire:target="kiKartenText"');
});
