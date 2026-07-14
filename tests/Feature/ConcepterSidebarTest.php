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
 * M10R-5 / Doc 15 §10.2 (Sidebar-Verdichtung 2026-07-14): der vereinheitlichte Concepter-
 * Screen erscheint GENAU EINMAL in der Sidebar (jetzt Item der Gruppe „Rezepte & Konzepte",
 * keine eigene Ein-Item-Gruppe mehr), kein separater „Pakete"-Eintrag.
 * Plus End-to-End-Smoke: anlegen → Slot → Paket → Tabelle zeigt beides.
 */
it('Concepter erscheint genau einmal in der Sidebar (Pakete-Eintrag entfällt)', function () {
    $alleItems = collect(config('foodalchemist.sidebar'))->flatMap(fn ($g) => $g['items'] ?? []);

    expect($alleItems->where('route', 'foodalchemist.concepter.index'))->toHaveCount(1);

    // Kein Sidebar-Eintrag zeigt mehr auf die alten Einzel-Screens.
    $alleRouten = $alleItems->pluck('route');
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
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 2.00, 'ek_total_eur' => 0.60,
    ]);
    $pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise', 'class' => 'Buffet']);
    $concepts->create($this->rootTeam, ['name' => 'Grill-Buffet', 'class' => 'Buffet']);

    Livewire::test(Browser::class)
        ->assertSee('Grill-Buffet')                                   // Concepts-Tab
        ->call('wechselTab', 'pakete')
        ->assertSee('Salad Wall');                                    // Pakete-Tab
});
