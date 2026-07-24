<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E4.4: `wareneinsatzAmpel()` — IST (Food-Cost-% aus kapitelAggregat) gegen
 * SOLL-Kaskade (Kapitel → Eltern → Foodbook → Team-Setting/30) + Toleranz + Partiell-Hinweis.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(FoodbookService::class);

    // VK-Gericht: 2,50 € VK / 0,75 € EK → Food-Cost = 30,0 %
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'we1', 'name' => 'WE: Amuse', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 2.50, 'ek_total_eur' => 0.75,
    ]);
});

it('grün: IST (30 %) ≤ Kapitel-Ziel (35 %); Quelle kapitel, nicht partiell', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Gruen']);
    $kap = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Haupt']);
    $this->svc->updateKapitel($this->rootTeam, $kap->id, ['target_food_cost_pct' => 35.0]);
    $this->svc->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);

    $a = $this->svc->wareneinsatzAmpel($this->rootTeam, $fb->refresh(), $kap->refresh());
    expect($a['status'])->toBe('gruen')
        ->and($a['ist_pct'])->toBe(30.0)
        ->and($a['ziel_pct'])->toBe(35.0)
        ->and($a['toleranz_pp'])->toBe(5.0)         // Foodbook-Default (kein food_cost_tolerance_pp gesetzt)
        ->and($a['quelle'])->toBe('kapitel')
        ->and($a['partiell'])->toBeFalse();
});

it('gelb: IST (30 %) > Ziel (25 %) aber ≤ Ziel+Toleranz (30 %)', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Gelb']);
    $this->svc->update($this->rootTeam, $fb->id, ['target_food_cost_pct' => 25.0, 'food_cost_tolerance_pp' => 5.0]);
    $kap = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Haupt']);
    $this->svc->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);

    $a = $this->svc->wareneinsatzAmpel($this->rootTeam, $fb->refresh(), $kap->refresh());
    expect($a['status'])->toBe('gelb')
        ->and($a['ziel_pct'])->toBe(25.0)
        ->and($a['quelle'])->toBe('foodbook');       // Kapitel leer → Foodbook-Default
});

it('rot: IST (30 %) > Ziel+Toleranz (20+5=25 %)', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Rot']);
    $this->svc->update($this->rootTeam, $fb->id, ['target_food_cost_pct' => 20.0, 'food_cost_tolerance_pp' => 5.0]);
    $kap = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Haupt']);
    $this->svc->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);

    $a = $this->svc->wareneinsatzAmpel($this->rootTeam, $fb->refresh(), $kap->refresh());
    expect($a['status'])->toBe('rot');
});

it('unbekannt + partiell: nur Pauschal-Block → kein Per-Person-VK, EK ungezählt', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Pauschal']);
    $kap = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Service']);
    // recipe_ref pauschal: VK fließt in flachen Anteil, EK ungezählt → food_cost null
    $this->svc->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'price_basis' => 'pauschal', 'quantity' => 3]);

    $a = $this->svc->wareneinsatzAmpel($this->rootTeam, $fb->refresh(), $kap->refresh());
    expect($a['status'])->toBe('unbekannt')
        ->and($a['ist_pct'])->toBeNull()
        ->and($a['partiell'])->toBeTrue();
});

it('partiell trotz grün: Per-Person-Gericht + Pauschal-Block nebeneinander', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Mix']);
    $this->svc->update($this->rootTeam, $fb->id, ['target_food_cost_pct' => 35.0]);
    $kap = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Menü']);
    $this->svc->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);
    $this->svc->addBlock($this->rootTeam, $kap->id, ['type' => 'header_frei_preis', 'price_basis' => 'pauschal', 'price_value' => 200, 'label' => 'Servicepauschale']);

    $a = $this->svc->wareneinsatzAmpel($this->rootTeam, $fb->refresh(), $kap->refresh());
    expect($a['status'])->toBe('gruen')      // IST 30 % nur aus Per-Person-Anteil
        ->and($a['partiell'])->toBeTrue();   // Pauschal-Anteil vorhanden → Vorbehalt
});

it('Ziel-Default: ohne jedes Ziel greift zielWareneinsatzPct (30 %), Quelle settings', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Default']);
    $kap = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Haupt']);
    $this->svc->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);

    $a = $this->svc->wareneinsatzAmpel($this->rootTeam, $fb->refresh(), $kap->refresh());
    expect($a['ziel_pct'])->toBe(TeamSettingsService::ZIEL_WARENEINSATZ_DEFAULT)
        ->and($a['quelle'])->toBe('settings')
        ->and($a['status'])->toBe('gruen');   // 30 ≤ 30
});
