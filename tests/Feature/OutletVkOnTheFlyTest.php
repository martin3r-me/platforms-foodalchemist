<?php

use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\CatalogPricingService;
use Platform\FoodAlchemist\Services\KalkulationService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Slice B: der Betriebs-Kontext wird in den Rechnern WIRKSAM (on-the-fly, Strategie A).
 * Outlet-Override schlägt im VK-Vorschlag / base_factor durch; outlet=null bleibt der
 * gespeicherte Team-Baseline-VK (kein Neu-Rechnen).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->settings = app(TeamSettingsService::class);
    $this->outletSettings = app(OutletSettingsService::class);
    $this->kalk = app(KalkulationService::class);
    $this->catalog = app(CatalogPricingService::class);

    $this->betrieb = function (string $name, array $override = []) {
        $o = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => $name]);
        if ($override !== []) {
            $this->outletSettings->update($this->childA, $o, $override);
        }

        return $o;
    };
});

it('KalkulationService::berechne — Outlet-Marge überschreibt Team-Marge im VK-Vorschlag', function () {
    $this->settings->update($this->childA, ['margin_pct' => 20]);       // Team: WE 10 → VK 12,00
    $premium = ($this->betrieb)('Premium', ['margin_pct' => 50]);       // Betrieb: → VK 15,00

    expect($this->kalk->berechne($this->childA, 10.0)['vk_vorschlag'])->toBe(12.0)
        ->and($this->kalk->berechne($this->childA, 10.0, 0.0, 0.0, $premium)['vk_vorschlag'])->toBe(15.0);
});

it('enterpriseBaseRate — Outlet-Ziel-Wareneinsatz überschreibt den Basissatz', function () {
    $this->settings->update($this->childA, ['target_food_cost_pct' => 25]);   // 100/25 = 4,0
    $guenstig = ($this->betrieb)('Kantine', ['target_food_cost_pct' => 20]);  // 100/20 = 5,0

    expect($this->catalog->enterpriseBaseRate($this->childA)['factor'])->toBe(4.0)
        ->and($this->catalog->enterpriseBaseRate($this->childA, $guenstig)['factor'])->toBe(5.0);
});

it('salesNetFor — outlet=null gibt die gespeicherte Baseline; fixer VK bleibt fix', function () {
    $betrieb = ($this->betrieb)('Egal', ['margin_pct' => 99]);

    // outlet=null ⇒ gespeicherter Baseline-Wert, ohne Neu-Rechnen
    $auto = new FoodAlchemistRecipeDarreichung(['sales_net' => 12.34, 'price_mode' => 'auto']);
    expect($this->catalog->salesNetFor($this->childA, $auto, null))->toBe(12.34);

    // fixer/manueller VK bleibt fix, auch im Betriebs-Kontext
    $fix = new FoodAlchemistRecipeDarreichung(['sales_net' => 9.99, 'price_mode' => 'fixed']);
    expect($this->catalog->salesNetFor($this->childA, $fix, $betrieb))->toBe(9.99);
});
