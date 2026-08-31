<?php

use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\KalkulationService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Paket-VK folgt der Betriebsbrille (on-the-fly, ohne Persistenz).
 * Auto-Paket: Σ Gericht-Outlet-VK; manueller Fix-Preis + outlet=null bleiben Team-Baseline.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->pakete = app(PaketService::class);
    $this->kalk = app(KalkulationService::class);
    $this->settings = app(TeamSettingsService::class);
    $this->outletSvc = app(OutletSettingsService::class);
    $this->team = $this->rootTeam;

    // Team-Basis: Ziel-WE 25 % → Basissatz 4,0. Klasse/MwSt/Rundung fix für exakte VK.
    $this->settings->update($this->team, [
        'target_food_cost_pct' => 25,
        'vat_defaults' => ['regulaer' => 19, 'ermaessigt' => 7, 'default_satz' => 'regulaer'],
        'rundungsregeln' => ['nachkommastellen' => 2, 'mode' => 'kaufmaennisch'],
    ]);

    $this->form = FoodAlchemistServierform::create([
        'team_id' => $this->team->id, 'code' => 'portion', 'label' => 'Portion',
    ]);
    // Gericht mit Standard-Darreichung: EK/Portion 2 €, price_mode auto, Baseline-VK 8,00 (= 2 × 4,0).
    $this->recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->team->id, 'recipe_key' => 'paket-dish', 'name' => 'Mini-Quiche',
        'status' => 'approved', 'is_sales_recipe' => true,
        'sales_net' => 8.0, 'ek_total_eur' => 2.0, 'sales_unit_count' => 1,
    ]);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->team->id, 'recipe_id' => $this->recipe->id,
        'serving_form_id' => $this->form->id, 'is_standard' => true,
        'ek_portion' => 2, 'price_mode' => 'auto', 'sales_net' => 8.0,
    ]);
});

it('outletPreisMap ohne Betrieb ist leer (Liste zeigt Team-Baseline)', function () {
    $p = $this->pakete->create($this->team, ['name' => 'Fingerfood', 'price_mode' => 'auto']);
    expect($this->pakete->outletPreisMap($this->team, collect([$p]), null))->toBe([]);
});

it('manueller Fix-Preis bleibt im Betriebs-Kontext unverändert', function () {
    $betrieb = FoodAlchemistOutlet::create(['team_id' => $this->team->id, 'name' => 'Premium']);
    $this->outletSvc->update($this->team, $betrieb, ['margin_pct' => 80]);

    $p = $this->pakete->create($this->team, [
        'name' => 'Buffet fix', 'price_mode' => 'fixed',
        'price_per_person' => 25, 'price_override_reason' => 'Buffet-Pauschale',
    ]);

    expect($this->pakete->paketPreisProPerson($p->refresh(), $betrieb))->toBe(25.0);
});

it('Auto-Paket: VK/Person folgt dem Betrieb (Σ Gericht-Outlet-VK), Team-Baseline unverändert', function () {
    $guenstig = FoodAlchemistOutlet::create(['team_id' => $this->team->id, 'name' => 'Kantine']);
    $this->outletSvc->update($this->team, $guenstig, ['target_food_cost_pct' => 20]);   // Basissatz 5,0 → Dish-VK 10,00

    $p = $this->pakete->create($this->team, ['name' => 'Snack-Paket', 'price_mode' => 'auto']);
    $this->pakete->syncGerichte($this->team, $p->id, [['sales_recipe_id' => $this->recipe->id]]);
    $p->refresh();

    // Team-Baseline: 8,00 (1 Portion × Baseline-VK).
    expect($this->pakete->paketPreisProPerson($p, null))->toBe(8.0);
    // Betrieb mit strengerer Ziel-WE → höherer VK.
    $vkBetrieb = $this->pakete->paketPreisProPerson($p, $guenstig);
    expect($vkBetrieb)->toBe(10.0);

    // paketHk reicht denselben Betriebs-VK durch.
    expect($this->kalk->paketHk($this->team, $p, $guenstig)['vk_pro_person'])->toBe(10.0)
        ->and($this->kalk->paketHk($this->team, $p, null)['vk_pro_person'])->toBe(8.0);

    // Listen-Map trägt den Betriebs-VK.
    expect($this->pakete->outletPreisMap($this->team, collect([$p]), $guenstig))->toBe([(int) $p->id => 10.0]);
});
