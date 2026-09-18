<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\StepEditor;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53), Restliste — dieselbe Umstellung wie im Recipe-/VK-Modal
 * ({@see \tests\Feature\KiFeedbackRecipeModalTest}), fürs Schritt-Editor-Panel.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Schritt-Editor-Rezept', ['preparation' => null]);
});

it('Schritt-Editor: KI-Schritte-Knopf hat data-ki-action + wire:target', function () {
    $html = Livewire::test(StepEditor::class, ['recipeId' => $this->rezept->id])->html();

    expect($html)->toContain('data-ki-action="kiSchritte"')
        ->toContain('wire:target="kiSchritte"');
});

it('Schritt-Editor: KI-Fotos-Knopf hat data-ki-action + wire:target', function () {
    $html = Livewire::test(StepEditor::class, ['recipeId' => $this->rezept->id])->html();

    expect($html)->toContain('data-ki-action="kiFotos"')
        ->toContain('wire:target="kiFotos"');
});
