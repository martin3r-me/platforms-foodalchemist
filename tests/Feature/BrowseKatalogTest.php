<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R18: Drei-Spalten-Browser — browseKatalog liefert beide Listen serverseitig
 * gefiltert (stapelbare Filter + zentrales q auf BEIDE), inkl. Auto-Fill-Einheit
 * und Niveau-Slugs für die Farbpunkte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'wrap', 'name' => 'HG: Wrap', 'status' => 'draft',
    ]);
    $this->gpTomate = $this->makeGp($this->rootTeam, 'Tomatenmark');
    $this->gpTomate->update(['commodity_group_code' => '10', 'sub_category' => '10.1 Pasten', 'condition' => 'konserviert']);
    $this->gpBier = $this->makeGp($this->rootTeam, 'Veltins Bier: fluessig');
    $this->gpBier->update(['commodity_group_code' => '15', 'condition' => 'frisch']);

    // #4 (2026-08-27): der Picker zeigt nur reife/freigegebene Basisrezepte → Sub muss review/approved sein.
    $this->sub = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond_tomate', 'name' => 'Fond: Tomate', 'status' => 'review',
    ]);
    app(\Platform\FoodAlchemist\Services\RecipeService::class)
        ->setzeEignung($this->rootTeam, $this->sub->id, 'level', 'gehoben');
});

it('filtert GPs nach Warengruppe/Zustand und liefert die Auto-Fill-Einheit (fluessig → ml)', function () {
    $komponente = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->instance();

    $alle = $komponente->browseKatalog();
    expect($alle['gps']['total'])->toBe(2)
        ->and(collect($alle['gps']['items'])->firstWhere('id', $this->gpBier->id)['einheit_slug'])->toBe('ml')
        ->and(collect($alle['gps']['items'])->firstWhere('id', $this->gpTomate->id)['einheit_slug'])->toBe('g');

    $nurWg10 = $komponente->browseKatalog(['wg' => '10']);
    expect($nurWg10['gps']['total'])->toBe(1)
        ->and($nurWg10['gps']['items'][0]['name'])->toBe('Tomatenmark');

    $zustand = $komponente->browseKatalog(['condition' => 'frisch']);
    expect(collect($zustand['gps']['items'])->pluck('id'))->toContain($this->gpBier->id)->not->toContain($this->gpTomate->id);
});

it('filtert Rezepte nach Niveau, schließt das eigene Rezept aus und trägt Niveau-Slugs', function () {
    $komponente = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->instance();

    $alle = $komponente->browseKatalog();
    expect(collect($alle['rezepte']['items'])->pluck('id'))->toContain($this->sub->id)->not->toContain($this->rezept->id)
        ->and(collect($alle['rezepte']['items'])->firstWhere('id', $this->sub->id)['niveaus'])->toBe(['gehoben']);

    $gehoben = $komponente->browseKatalog([], ['level' => 'gehoben']);
    expect($gehoben['rezepte']['total'])->toBe(1);
    $haute = $komponente->browseKatalog([], ['level' => 'haute_cuisine']);
    expect($haute['rezepte']['total'])->toBe(0);
});

it('q wirkt als Textfilter auf BEIDE Listen gleichzeitig', function () {
    $komponente = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->instance();

    $r = $komponente->browseKatalog([], [], 'tomate');
    expect($r['gps']['total'])->toBe(1)
        ->and($r['gps']['items'][0]['name'])->toBe('Tomatenmark')
        ->and($r['rezepte']['total'])->toBe(1)
        ->and($r['rezepte']['items'][0]['name'])->toBe('↳ Fond: Tomate');
});

it('#4: Picker zeigt nur reife GPs + Basisrezepte — Entwurf/Stub/Veraltet + abgelehnt/merged raus', function () {
    // Nicht-verwendbare GPs (abgelehnt/merged) — dürfen NICHT im Picker erscheinen.
    $this->makeGp($this->rootTeam, 'Abgelehnt-GP')->update(['status' => 'rejected']);
    $this->makeGp($this->rootTeam, 'Merged-GP')->update(['status' => 'merged']);
    // Nicht-reife Rezepte — Entwurf/Stub/Veraltet raus, Freigegeben bleibt.
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'draft_r', 'name' => 'Fond: Entwurf', 'status' => 'draft']);
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'stub_r', 'name' => 'Fond: Stub', 'status' => 'stub']);
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'depr_r', 'name' => 'Fond: Alt', 'status' => 'deprecated']);
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'appr_r', 'name' => 'Fond: Freigegeben', 'status' => 'approved']);

    $komponente = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->instance();
    $alle = $komponente->browseKatalog();

    $gpNamen = collect($alle['gps']['items'])->pluck('name');
    expect($gpNamen)->not->toContain('Abgelehnt-GP')->not->toContain('Merged-GP')
        ->and($gpNamen)->toContain('Tomatenmark');   // tentative bleibt sichtbar

    $rezNamen = collect($alle['rezepte']['items'])->pluck('name')->map(fn ($n) => ltrim($n, '↳ '));
    expect($rezNamen)->toContain('Fond: Tomate')          // review
        ->toContain('Fond: Freigegeben')                  // approved
        ->not->toContain('Fond: Entwurf')                 // draft
        ->not->toContain('Fond: Stub')                    // stub
        ->not->toContain('Fond: Alt');                    // deprecated
});

it('lädt den Drei-Spalten-Browser erst bei Fokus oder Filterinteraktion', function () {
    $modulRoot = dirname((new ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2);
    $editor = file_get_contents($modulRoot . '/resources/views/livewire/recipes/ingredient-editor.blade.php');
    $kern = file_get_contents($modulRoot . '/resources/views/livewire/recipes/partials/zutaten-kern.blade.php');

    expect($editor)->toContain('browserGeladen: false')
        ->and($editor)->toContain('browseOnce()')
        ->and($editor)->not->toContain('this.browse();                                       // R18: Seitenspalten initial füllen')
        ->and($kern)->toContain('@focus="browseOnce()"');
});
