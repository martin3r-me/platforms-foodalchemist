<?php

use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\LeitstelleService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — die Betriebsbrille treibt die Foodbook-Kalkulation: Board (Kapitel/Positionen),
 * Speisen-Baum, Matrix und Portfolio-Gesamt rechnen den VK gegen den aktiven Betrieb, nicht
 * mehr fix gegen die Team-Baseline. Ohne Betrieb bleibt die Baseline (sales_net 99); mit Betrieb
 * der on-the-fly-VK (ek_portion 10 × Basissatz 5,0 = 50).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    app(TeamSettingsService::class)->update($this->childA, ['target_food_cost_pct' => 25]);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Betrieb Nord']);
    app(OutletSettingsService::class)->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 20]);   // Basissatz 5,0

    $this->gericht = $this->makeRecipe($this->childA, 'HG Zander', ['is_sales_recipe' => true, 'sales_net' => 99.0, 'ek_total_eur' => 10.0]);
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10, 'sales_net' => 99.0,
    ]);
    $this->fb = $this->makeFoodbook($this->childA, 'Testbuch', ['personen' => 10]);
    $k = $this->makeChapter($this->fb, ['title' => 'Hauptgang']);
    $this->makeFoodbookBlock($k, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);
});

it('kapitelBoard: Positions-VK folgt der Brille (Baseline 99 vs Betrieb 50)', function () {
    $leit = app(LeitstelleService::class);

    $baseline = $leit->kapitelBoard($this->childA, $this->fb);
    $mitBetrieb = $leit->kapitelBoard($this->childA, $this->fb, $this->betrieb);

    expect((float) $baseline[0]['positionen'][0]['vk'])->toBe(99.0)
        ->and((float) $mitBetrieb[0]['positionen'][0]['vk'])->toBe(50.0);
});

it('Portfolio-Gesamt + WE-Ampel folgen der Brille', function () {
    $svc = app(FoodbookService::class);

    $baseline = $svc->gesamt($this->childA, $this->fb);
    $mitBetrieb = $svc->gesamt($this->childA, $this->fb, $this->betrieb);
    expect($baseline['vk_pro_person'])->toBe(99.0)
        ->and($mitBetrieb['vk_pro_person'])->toBe(50.0);

    // WE-Ampel: EK 10 / VK 50 = 20 % beim Betrieb (Ziel 20 %) vs EK 10 / VK 99 ≈ 10 % Baseline.
    $weBetrieb = $svc->foodbookWareneinsatzAmpel($this->childA, $this->fb, $this->betrieb);
    expect($weBetrieb['ist_pct'])->toBe(20.0);
});

it('speisenBaum + kapitelMatrix akzeptieren die Brille ohne Fehler', function () {
    $leit = app(LeitstelleService::class);
    expect($leit->speisenBaum($this->childA, $this->fb, $this->betrieb)[0]['positionen'][0]['preis'])->toBe(50.0)
        ->and($leit->kapitelMatrix($this->childA, $this->fb, $this->betrieb)[0]['bepreist'])->toBeTrue();
});
