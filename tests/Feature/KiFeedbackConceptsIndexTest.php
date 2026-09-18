<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepts\Index as ConceptsIndex;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53), Restliste — dieselbe Umstellung wie im Recipe-/VK-Modal
 * ({@see \tests\Feature\KiFeedbackRecipeModalTest}), für den Concept-
 * Generator ("Konzept aus Brief"). generatorStart läuft SYNCHRON im
 * Livewire-Request (kein Job) und ruft echtes KI-Wording — echter
 * Sofort-Feedback-Kandidat, anders als der Job-Knopf im VK-/Basisrezept-
 * Generator.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Concepts-Index: „Konzept generieren" (generatorStart) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(ConceptsIndex::class)->call('generatorOeffnen')->html();

    expect($html)->toContain('data-ki-action="generatorStart"')
        ->toContain('wire:target="generatorStart"');
});
