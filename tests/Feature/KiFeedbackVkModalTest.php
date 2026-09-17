<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53) — dieselbe Umstellung wie im Recipe-Modal
 * ({@see \tests\Feature\KiFeedbackRecipeModalTest}), fürs VK-Modal.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $klasse = FoodAlchemistDishClass::create([
        'dish_main_group_id' => FoodAlchemistDishMainGroup::create(['code' => 'KIG', 'label' => 'KI-Feedback-Test'])->id,
        'code' => 'KIG-A', 'label' => 'Test-Klasse',
    ]);
    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'kifeedback-vk1', 'name' => 'VK: Test',
        'status' => 'draft', 'is_sales_recipe' => true, 'dish_class_id' => $klasse->id,
    ]);
});

it('„Alles anreichern" hat data-ki-action + wire:target', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();

    expect($html)->toContain('data-ki-action="allesAnreichern"')
        ->toContain('wire:target="allesAnreichern"');
});

it('„Rollen verteilen" (ai_rollen) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();

    expect($html)->toContain('data-ki-action="ai_rollen"')
        ->toContain('wire:target="ai_rollen"');
});

it('„Regeneration" (kiRegeneration) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();

    expect($html)->toContain('data-ki-action="kiRegeneration"')
        ->toContain('wire:target="kiRegeneration"');
});

it('„Sensorik neu bewerten" hat data-ki-action + wire:target', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();

    expect($html)->toContain('data-ki-action="sensorikBewerten"')
        ->toContain('wire:target="sensorikBewerten"');
});
