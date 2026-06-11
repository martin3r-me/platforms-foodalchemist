<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\Browser;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M4-04/05: Basisrezept-Browser + DetailPanel — Baum-Counts == SQL, Filter,
 * basis()-Scope, Klick = Event (Kontext-Erhalt), Panel-Werte == Aggregate.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->hg = FoodAlchemistRecipeMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'SAU', 'bezeichnung' => 'Saucen']);
    $this->kat = FoodAlchemistRecipeCategory::create(['team_id' => $this->rootTeam->id, 'main_group_id' => $this->hg->id, 'code' => 'BBQ', 'bezeichnung' => 'BBQ-Saucen']);

    $this->mkRezept = fn (string $name, array $extra = []) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => str_replace(' ', '_', mb_strtolower($name)),
        'name' => $name, 'status' => 'approved', 'kategorie_id' => $this->kat->id, ...$extra,
    ]);
    $this->bbq = ($this->mkRezept)('BBQ Texas', ['yield_kg' => 0.387, 'ek_total_eur' => 2.17, 'ek_per_kg_eur' => 5.61, 'geschmacksrichtung' => 'herzhaft', 'spec_is_vegan' => false]);
    ($this->mkRezept)('Chimichurri', ['geschmacksrichtung' => 'herzhaft']);
    ($this->mkRezept)('VK Bowl', ['ist_verkaufsrezept' => true]);                 // D-6: NICHT in der Basis-Sicht
});

it('Hauptgruppen-Counts == SQL und basis()-Scope filtert VK-Rezepte raus (DoD M4-04)', function () {
    $svc = app(RecipeService::class);

    expect($svc->hauptgruppenCounts($this->rootTeam))->toBe([$this->hg->id => 2])  // VK Bowl fehlt
        ->and($svc->kategorieCounts($this->rootTeam, $this->hg->id))->toBe([$this->kat->id => 2])
        ->and($svc->paginateBrowser([], $this->rootTeam)->total())->toBe(2)
        ->and($svc->paginateBrowser(['geschmack' => 'herzhaft'], $this->rootTeam)->total())->toBe(2)
        ->and($svc->paginateBrowser(['search' => 'texas'], $this->rootTeam)->total())->toBe(1);
});

it('Browser: Zeilen-Klick setzt ?rezept= und dispatcht recipe-selected (Kontext-Erhalt)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(Browser::class)
        ->assertSee('BBQ Texas')
        ->assertDontSee('VK Bowl')
        ->call('waehleRezept', $this->bbq->id)
        ->assertSet('recipeId', $this->bbq->id)
        ->assertDispatched('recipe-selected', id: $this->bbq->id);
});

it('DetailPanel: KPI-Karte zeigt die GL-02-Aggregate, Diät-Sektion die spec_*-Flags (DoD M4-05)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(DetailPanel::class)
        ->dispatch('recipe-selected', id: $this->bbq->id)
        ->assertSee('BBQ Texas')
        ->assertSee('5,61 €')
        ->assertSee('2,17 €')
        ->assertSee('0,387 kg')
        ->assertSee('✕ Vegan');                                  // spec_is_vegan = false
});

it('DetailPanel respektiert D1: fremdes Team-Rezept bleibt unsichtbar', function () {
    $this->actingAs($this->makeUser($this->childB, 'Kind B User'));
    $kindA = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'kind_a_spezial', 'name' => 'Kind-A-Spezial', 'status' => 'draft',
    ]);

    Livewire::test(DetailPanel::class)
        ->dispatch('recipe-selected', id: $kindA->id)
        ->assertDontSee('Kind-A-Spezial');
});
