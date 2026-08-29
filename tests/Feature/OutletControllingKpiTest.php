<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Controlling\Cockpit;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Kennzahlen;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\ActiveOutletContext;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Controlling folgt dem aktiven Betrieb: die kostenstruktur-KPIs (Fixkosten/Monat,
 * Ziel-WE, Break-even, Zuschlag) rechnen mit den Werten des ambient gewählten Betriebs; ohne
 * Betrieb bleibt es Team-Baseline. Die gemessenen Ist-Werte (avg_w_pct etc.) sind team-weit.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);

    // Team: Ziel-WE 25 %, Fixkosten 1000 €/Monat. Betrieb: Ziel-WE 20 % + eigene Fixkosten 2000.
    app(TeamSettingsService::class)->update($this->childA, ['target_food_cost_pct' => 25]);
    app(FixkostenService::class)->create($this->childA, ['label' => 'Miete', 'amount' => 1000, 'periode' => 'monatlich', 'block_key' => 'gemeinkosten']);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Betrieb Nord']);
    app(OutletSettingsService::class)->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 20]);
    app(FixkostenService::class)->create($this->childA, ['label' => 'Miete Nord', 'amount' => 2000, 'periode' => 'monatlich', 'block_key' => 'gemeinkosten'], $this->betrieb);
});

it('Kennzahlen-Panel: ohne Betrieb Team-Baseline, mit aktivem Betrieb dessen Werte', function () {
    // (Ziel-WE steckt in einer public-string-Prop des Inline-Editors → hier über den Break-even
    //  geprüft, der die outlet-aware Ziel-WE verrechnet: Fixkosten ÷ (1 − Ziel-WE).)
    $ohne = Livewire::test(Kennzahlen::class);
    expect($ohne->viewData('fixkostenMonat'))->toBe(1000.0)
        ->and(round((float) $ohne->viewData('breakEven')))->toBe(1333.0)   // 1000 ÷ 0,75
        ->and($ohne->viewData('betriebName'))->toBeNull();

    app(ActiveOutletContext::class)->set($this->childA, $this->betrieb->id);
    $mit = Livewire::test(Kennzahlen::class);
    expect($mit->viewData('fixkostenMonat'))->toBe(2000.0)                 // Betriebs-Fixkosten (Per-Block-Replace)
        ->and(round((float) $mit->viewData('breakEven')))->toBe(2500.0)    // 2000 ÷ 0,80 (Betriebs-Ziel-WE)
        ->and($mit->viewData('betriebName'))->toBe('Betrieb Nord');
    $mit->assertSee('Betrieb Nord');                                       // Badge sichtbar
});

it('Controlling-Cockpit-KPI-Kopf: Break-even + Fixkosten folgen dem aktiven Betrieb', function () {
    $ohne = Livewire::test(Cockpit::class)->viewData('kpi');
    expect($ohne['fixkosten_monat'])->toBe(1000.0)
        ->and($ohne['ziel_we_pct'])->toBe(25.0)
        ->and($ohne['betrieb_name'])->toBeNull();

    app(ActiveOutletContext::class)->set($this->childA, $this->betrieb->id);
    $mit = Livewire::test(Cockpit::class)->viewData('kpi');
    expect($mit['fixkosten_monat'])->toBe(2000.0)
        ->and($mit['ziel_we_pct'])->toBe(20.0)
        ->and($mit['betrieb_name'])->toBe('Betrieb Nord');
    // Break-even = Fixkosten ÷ (1 − Ziel-WE): mit Betrieb 2000 ÷ 0,8 = 2500; Team 1000 ÷ 0,75 ≈ 1333.
    expect(round($mit['break_even']))->toBe(2500.0);
});

it('fremder Betrieb wird ignoriert (Team-Baseline; ActiveOutletContext team-gescopt)', function () {
    $betriebB = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);
    // set() gegen einen fremden Betrieb greift nicht (team-scope) → current() bleibt null.
    app(ActiveOutletContext::class)->set($this->childA, $betriebB->id);
    $c = Livewire::test(Kennzahlen::class);
    expect($c->viewData('fixkostenMonat'))->toBe(1000.0)
        ->and($c->viewData('betriebName'))->toBeNull();
});
