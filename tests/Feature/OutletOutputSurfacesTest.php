<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DarreichungResolver;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Slice C.2: die Ausgabe-Flächen (Speisekarte/Foodbook/Speiseplan/Concept) lösen den
 * Gericht-VK über den geteilten DarreichungResolver auf. Wird der outlet-aware, folgt der VK auf
 * ALLEN Flächen dem Betrieb. Dieser Test riegelt den Hebel (vkNettoMitQuelle); outlet=null = heute.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->resolver = app(DarreichungResolver::class);
    $settings = app(TeamSettingsService::class);
    $outletSettings = app(OutletSettingsService::class);

    $settings->update($this->childA, ['target_food_cost_pct' => 25]);   // Basissatz 4,0
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Kantine']);
    $outletSettings->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 20]);  // Basissatz 5,0

    $this->gericht = $this->makeRecipe($this->childA, 'Gericht');
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10, 'sales_net' => 99.0,
    ]);
});

it('vkNettoMitQuelle: ohne Betrieb die gespeicherte Baseline, mit Betrieb on-the-fly', function () {
    // ohne Betrieb ⇒ Standard-Darreichung sales_net = 99,00 (Baseline, byte-identisch heute)
    $ohne = $this->resolver->vkNettoMitQuelle($this->gericht->fresh());
    expect($ohne['vk'])->toBe(99.0)
        ->and($ohne['quelle'])->toBe('darreichung');

    // mit Betrieb ⇒ MEK 10 × Basissatz 5,0 = 50,00 (nicht die 99er-Baseline)
    $mit = $this->resolver->vkNettoMitQuelle($this->gericht->fresh(), $this->betrieb);
    expect($mit['vk'])->toBe(50.0)
        ->and($mit['quelle'])->toBe('darreichung');
});
