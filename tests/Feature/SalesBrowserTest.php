<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\Browser;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M6-03: VK-Browser/Panel + Scope-Härte (D-6 §7.8): die VK-Sicht liefert nie
 * Basisrezepte und umgekehrt; ein VK-Rezept ist nicht als Sub-Rezept
 * verknüpfbar (GL-04-Pool-Filter ->basis()).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);

    $hg = FoodAlchemistDishMainGroup::create(['code' => 'HG', 'label' => 'Hauptgang']);
    $this->class = FoodAlchemistDishClass::create(['dish_main_group_id' => $hg->id, 'code' => 'HG_FLEISCH', 'label' => 'Fleisch', 'diet_form' => 'fleisch']);
    $this->alc = FoodAlchemistMarkupClass::create(['code' => 'ALC', 'label' => 'A la Carte', 'raw_markup_pct' => 420, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);

    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk1', 'name' => 'HG: Filet | Jus', 'status' => 'draft',
        'is_sales_recipe' => true, 'dish_class_id' => $this->class->id, 'markup_class_id' => $this->alc->id,
        'ek_total_eur' => 1.30, 'ek_per_kg_eur' => 5.20, 'yield_kg' => 0.5, 'sales_unit_count' => 2,
    ]);
    $this->basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'b1', 'name' => 'Sauce: Jus', 'status' => 'draft',
    ]);
});

it('Scope-Härte: VK-Liste liefert nie Basisrezepte, Basis-Liste nie VK, Detail kreuzweise null', function () {
    $sales = app(SalesRecipeService::class);
    $recipes = app(\Platform\FoodAlchemist\Services\RecipeService::class);

    expect($sales->paginateBrowser([], $this->rootTeam)->pluck('id'))->toEqual(collect([$this->vk->id]))
        ->and($recipes->paginateBrowser([], $this->rootTeam)->pluck('id'))->not->toContain($this->vk->id)
        ->and($sales->detail($this->rootTeam, $this->basis->id))->toBeNull()
        ->and($recipes->detail($this->rootTeam, $this->vk->id))->toBeNull();
});

it('GL-04-Pool-Filter: VK-Rezept ist nicht als Sub-Rezept verknüpfbar', function () {
    $treffer = collect(app(\Platform\FoodAlchemist\Services\RecipeService::class)
        ->sucheZutatenZiel($this->rootTeam, 'Filet', 0));

    expect($treffer->where('type', 'sub')->pluck('id'))->not->toContain($this->vk->id)
        ->and($treffer->where('type', 'sub'))->toBeEmpty();            // einziger Namens-Treffer wäre das VK-Rezept
});

it('Cockpit: g/Einheit aus Yield/Anzahl abgeleitet, VK-Vorschlag aus Klasse, Wareneinsatz konsistent', function () {
    $cockpit = app(SalesRecipeService::class)->cockpit(app(SalesRecipeService::class)->detail($this->rootTeam, $this->vk->id));

    expect($cockpit['verkauft_als']['g_pro_einheit'])->toBe(250.0)   // 0,5 kg / 2 Einheiten
        ->and($cockpit['vk']['source'])->toBe('class')
        ->and($cockpit['vk']['sales_net'])->toBe(6.76)                // GT-8: 5,20 €/kg × 250 g × 5,2
        ->and($cockpit['sales_gross'])->toBe(8.04)
        ->and($cockpit['marge']['wareneinsatz_pct'] + $cockpit['marge']['marge_pct'])->toBe(100.0)
        ->and($cockpit['pro_einheit']['vk_netto_pro_einheit'])->toBe(3.38);
});

it('Browser listet VK-Zeile; Klick wählt Rezept (URL-Kontext); HG-Baum live-verprobt', function () {
    // Sidebar-Slot rendert im Test-Harness nicht (x-ui-page) — HG-Baum/Codes
    // sind im Live-Check verprobt (16 [Codes] gegen Sandbox-Daten, Roadmap).
    $this->actingAs($this->user);

    Livewire::test(Browser::class)
        ->assertSeeHtml('data-vk-zeile="' . $this->vk->id . '"')
        ->call('waehleRezept', $this->vk->id)
        ->assertDispatched('vk-recipe-selected', id: $this->vk->id)
        ->assertSet('recipeId', $this->vk->id);
});

it('Panel zeigt VERKAUFT-ALS + KPI-Karten; W-1-Klasse kennzeichnet statt crasht', function () {
    $this->actingAs($this->user);

    Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])
        ->assertSeeHtml('data-verkauft-als')
        ->assertSeeHtml('data-vk-brutto')
        ->assertSeeHtml('data-formel-klartext');

    $this->vk->update(['markup_class_id' => FoodAlchemistMarkupClass::create([
        'code' => 'PAUS', 'label' => 'Pauschal', 'formula_type' => 'deckungsbeitrag',
    ])->id]);

    Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])
        ->assertSeeHtml('data-formel-fehlt')                          // W-1: Hinweis statt Exception im UI
        ->assertSee('W-1');
});
