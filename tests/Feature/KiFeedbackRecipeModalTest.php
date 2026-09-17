<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53) — Anlass Dominique 22:50: „wenn ich in den Editoren den KI-Knopf
 * drücke, sieht man nicht, dass er arbeitet." Alle KI-Knöpfe im Recipe-Modal sind auf
 * <x-foodalchemist::ki-action> umgestellt (byte-gleiches wire:target zum Wire-Ausdruck,
 * Alpine pending/ok/err VOR der Server-Antwort). Muster: PlanungLeitstelleTest
 * „ki-action-Komponente: data-ki-action + wire:target …".
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'kifeedback1', 'name' => 'Sauce: Test',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 10,
    ]);
});

it('Stammdaten-Tab (Screenshot-Fall): „Name putzen" hat data-ki-action + wire:target', function () {
    $html = Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('tabLaden', 'eigenschaften')
        ->html();

    expect($html)->toContain('data-ki-action="namePutzen"')
        ->toContain('wire:target="namePutzen"');
});

it('Stammdaten-Tab: „Fertigung" hat data-ki-action + wire:target', function () {
    $html = Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('tabLaden', 'eigenschaften')
        ->html();

    expect($html)->toContain('data-ki-action="kiFertigung"')
        ->toContain('wire:target="kiFertigung"');
});

it('Eigenschaften-Sektion: „Eigenschaften" und „Beschreibung" haben data-ki-action + wire:target', function () {
    $html = Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('tabLaden', 'eigenschaften')
        ->html();

    expect($html)->toContain('data-ki-action="kiEigenschaften"')
        ->toContain('wire:target="kiEigenschaften"')
        ->toContain('data-ki-action="ai_beschreibung"')
        ->toContain('wire:target="ai_beschreibung"');
});

it('„Alles anreichern" hat data-ki-action + wire:target (Kopfzeile, immer sichtbar)', function () {
    $html = Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->html();

    expect($html)->toContain('data-ki-action="allesAnreichern"')
        ->toContain('wire:target="allesAnreichern"');
});
