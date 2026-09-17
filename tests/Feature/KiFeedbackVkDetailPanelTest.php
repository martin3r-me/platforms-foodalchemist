<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53) — dieselbe Umstellung wie im Recipe-/VK-/GP-Modal
 * ({@see \tests\Feature\KiFeedbackRecipeModalTest}), fürs Verkauf-Detail-Panel.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'kifeedback-vkpanel1', 'name' => 'FIN: KI-Feedback-Test',
        'status' => 'draft', 'is_sales_recipe' => true,
    ]);
});

it('„Klassifizieren" (ai_klassifizieren) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])->html();

    expect($html)->toContain('data-ki-action="ai_klassifizieren"')
        ->toContain('wire:target="ai_klassifizieren"');
});

it('„Eignung" (kiEignung) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])->html();

    expect($html)->toContain('data-ki-action="kiEignung"')
        ->toContain('wire:target="kiEignung"');
});
