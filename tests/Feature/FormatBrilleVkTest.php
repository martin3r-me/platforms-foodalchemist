<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Formate\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\ActiveOutletContext;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — die Format-Kalkulation (Editionen = Concepts) rechnet den €/Gast je Edition gegen
 * die Betriebsbrille. Vorher las der Editor/DetailPanel stur `price_per_person_cache`; jetzt mit
 * aktivem Betrieb live via ConceptService::preisCockpit(outlet).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);

    app(TeamSettingsService::class)->update($this->childA, ['target_food_cost_pct' => 25]);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Betrieb Nord']);
    app(OutletSettingsService::class)->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 20]);   // Basissatz 5,0

    $recipe = $this->makeRecipe($this->childA, 'HG Zander', ['is_sales_recipe' => true, 'sales_net' => 99.0, 'ek_total_eur' => 10.0]);
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $recipe->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10, 'sales_net' => 99.0,
    ]);

    // Concept mit einem Gericht-Slot → preisCockpit ist outlet-sensitiv.
    $this->concept = $this->makeConcept($this->childA, 'Edition A');
    $this->makeConceptSlot($this->concept, ['sales_recipe_id' => $recipe->id]);

    // Format mit dieser Edition.
    $this->format = app(FormatService::class)->create($this->childA, ['name' => 'Menü-Format']);
    FoodAlchemistFormatSlot::create([
        'team_id' => $this->childA->id, 'format_id' => $this->format->id,
        'type' => 'concept', 'concept_id' => $this->concept->id, 'position' => 1,
    ]);
});

it('Format-Kalkulations-Tab: €/Gast je Edition folgt der Brille', function () {
    $svc = app(ConceptService::class);
    $baseline = (float) $svc->preisCockpit($this->concept)['price_per_person'];
    $betriebsPreis = (float) $svc->preisCockpit($this->concept, $this->betrieb)['price_per_person'];
    // Sanity: die Edition ist überhaupt outlet-sensitiv (99 Baseline vs 50 Betrieb).
    expect($betriebsPreis)->not->toBe($baseline)
        ->and($betriebsPreis)->toBe(50.0);

    // Ohne Brille: Editor nimmt den Cache (hier ungerechnet → null, nicht der Betriebspreis).
    app(ActiveOutletContext::class)->set($this->childA, $this->betrieb->id);
    $ed = Livewire::test(Editor::class)->set('id', $this->format->id)->set('tab', 'kalkulation');
    $zeilen = $ed->viewData('kalkZeilen');
    expect(round((float) $zeilen[0]['vk'], 2))->toBe(50.0)
        ->and($ed->viewData('kalkSumme')['min'])->toBe(50.0);
});
