<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G, Restliste — die beiden "Garverluste"-Knöpfe (recipe-modal + vk-modal) dispatchen
 * nur ein Fenster-Event ins eingebettete ingredient-editor-Alpine (anderer x-data-Scope,
 * kein direkter $wire-Call von hier aus) — bisher OHNE jedes Klick-Feedback. Statt
 * <x-foodalchemist::ki-action> (das $wire.<action> direkt ruft) eine dokumentierte
 * Ausnahme: eigener Alpine-Zustand, der auf garverluste-fertig/-fehler hört
 * (ingredient-editor.blade.php dispatcht die jetzt nach dem $wire-Call), visuell
 * identisch zur Komponente (Spinner/Haken/Fehler).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Recipe-Modal: Garverluste-Knopf hat eigenen pending-Zustand + hört auf garverluste-fertig/-fehler', function () {
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'garverlust_rm', 'name' => 'Garverlust-Test', 'status' => 'draft',
    ]);
    $html = Livewire::test(RecipeModal::class)->call('oeffnen', $r->id)->html();

    expect($html)->toContain('data-garverlust-ki')
        ->toContain('garverluste-fertig.window')
        ->toContain('garverluste-fehler.window')
        ->toContain("\$dispatch('garverluste-vorschlagen')");
});

it('VK-Modal: Garverluste-Knopf hat eigenen pending-Zustand + hört auf garverluste-fertig/-fehler', function () {
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'garverlust_vk', 'name' => 'Garverlust-VK-Test', 'status' => 'draft',
        'is_sales_recipe' => true,
    ]);
    $html = Livewire::test(VkModal::class)->call('oeffnen', $r->id)->html();

    expect($html)->toContain('data-vk-garverlust-ki')
        ->toContain('garverluste-fertig.window')
        ->toContain('garverluste-fehler.window')
        ->toContain("\$dispatch('garverluste-vorschlagen')");
});

it('ingredient-editor: garverluste() im Alpine-JS dispatcht garverluste-fertig nach Erfolg', function () {
    $js = file_get_contents(__DIR__ . '/../../resources/views/livewire/recipes/ingredient-editor.blade.php');

    expect($js)->toContain("window.dispatchEvent(new CustomEvent('garverluste-fertig'))")
        ->toContain("window.dispatchEvent(new CustomEvent('garverluste-fehler'")
        ->toContain('try {')
        ->toContain('catch (e)');
});
