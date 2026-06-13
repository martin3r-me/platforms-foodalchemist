<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Browser;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10R-5 / Doc 15 §10.2: Sidebar zusammengeführt — die „Concepter"-Gruppe hat genau
 * EINEN Eintrag (der vereinheitlichte Screen), kein separater „Pakete"-Eintrag mehr.
 * Plus End-to-End-Smoke: anlegen → Slot → Paket → Tabelle zeigt beides.
 */
it('Sidebar-Gruppe „Concepter" hat genau einen Eintrag (Pakete-Eintrag entfällt)', function () {
    $gruppe = collect(config('foodalchemist.sidebar'))->firstWhere('group', 'Concepter');

    expect($gruppe)->not->toBeNull()
        ->and($gruppe['items'])->toHaveCount(1)
        ->and($gruppe['items'][0]['route'])->toBe('foodalchemist.concepter.index');

    // Kein anderer Sidebar-Eintrag zeigt mehr auf die alten Einzel-Screens.
    $alleRouten = collect(config('foodalchemist.sidebar'))->flatMap(fn ($g) => collect($g['items'] ?? [])->pluck('route'));
    expect($alleRouten)->not->toContain('foodalchemist.pakete.index')
        ->and($alleRouten)->not->toContain('foodalchemist.concepts.index');
});

it('End-to-End: Concept + Paket im einen Screen sichtbar, Tab-Wechsel zeigt beide', function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $pakete = app(PaketService::class);
    $concepts = app(ConceptService::class);

    FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'Green Power',
        'status' => 'approved', 'ist_verkaufsrezept' => true, 'vk_netto' => 2.00, 'ek_total_eur' => 0.60,
    ]);
    $pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'klasse' => 'Buffet']);
    $concepts->create($this->rootTeam, ['name' => 'Grill-Buffet', 'klasse' => 'Buffet']);

    Livewire::test(Browser::class)
        ->assertSee('Grill-Buffet')                                   // Concepts-Tab
        ->call('wechselTab', 'pakete')
        ->assertSee('Salad Wall');                                    // Pakete-Tab
});
