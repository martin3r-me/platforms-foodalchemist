<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierRebateConfig;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierRebateTier;
use Platform\FoodAlchemist\Services\RebateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einkauf E1 · Vererbung der Rückvergütung (Entscheidung Dominique, 2026-08-01).
 *
 * Vorher galt: Konditionen sind strikt team-eigen („pro Betrieb verhandelt"). Jetzt gilt:
 * zentral verhandelt, die Betriebe erben. Die Regel ist bewusst grob —
 *
 *   Eine EIGENE Kondition überschreibt die geerbte GANZ; Config und Staffel kommen
 *   immer vom selben Team.
 *
 * — und genau das prüfen die Tests hier, samt der beiden Wege, auf denen es still
 * schiefgehen könnte: ein Kind, das die Kondition des Eltern-Teams überschreibt, und ein
 * Kind, das sie beim Speichern versehentlich VERÄNDERT.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->rebate = app(RebateService::class);

    $this->supplier = FoodAlchemistSupplier::create([
        'team_id' => $this->rootTeam->id,
        'name' => 'Chefs Culinar',
        'status' => 'aktiv',
        'rebate_pct' => null,
    ]);

    // Zentral verhandelt: Root führt die Staffel, 250k Umsatz → 3 %.
    $this->rebate->saveTiers($this->rootTeam, $this->supplier->id, [
        ['threshold_eur' => 0, 'percent' => 2.0],
        ['threshold_eur' => 200000, 'percent' => 3.0],
    ]);
    $this->rebate->saveConfig($this->rootTeam, $this->supplier->id, [
        'active' => true,
        'assumed_annual_revenue' => 250000,
    ]);
});

it('ein Betrieb ohne eigene Kondition rechnet mit der des Eltern-Teams', function () {
    expect($this->rebate->effektiverProzent($this->childA, $this->supplier->id))->toBe(3.0);

    $info = $this->rebate->stufenInfo($this->childA, $this->supplier->id);
    expect($info['geerbt'])->toBeTrue();
    expect($info['quelle_team_id'])->toBe($this->rootTeam->id);
    expect($info['tiers'])->toHaveCount(2);
});

it('eine eigene Kondition überschreibt die geerbte vollständig', function () {
    $this->rebate->saveTiers($this->childA, $this->supplier->id, [
        ['threshold_eur' => 0, 'percent' => 5.0],
    ]);
    $this->rebate->saveConfig($this->childA, $this->supplier->id, [
        'active' => true,
        'assumed_annual_revenue' => 250000,
    ]);

    // Kind rechnet mit SEINEN 5 % — nicht mit den geerbten 3 %, und auch nicht mit beiden
    // Staffeln übereinander (eine gemischte Liste ergäbe hier 3,0).
    expect($this->rebate->effektiverProzent($this->childA, $this->supplier->id))->toBe(5.0);
    expect($this->rebate->tiersFor($this->childA, $this->supplier->id))->toHaveCount(1);

    $info = $this->rebate->stufenInfo($this->childA, $this->supplier->id);
    expect($info['geerbt'])->toBeFalse();

    // Das Eltern-Team bleibt davon unberührt.
    expect($this->rebate->effektiverProzent($this->rootTeam, $this->supplier->id))->toBe(3.0);
});

it('das Speichern im Kind-Team fasst die Kondition des Eltern-Teams nicht an', function () {
    $rootConfig = FoodAlchemistSupplierRebateConfig::where('team_id', $this->rootTeam->id)->firstOrFail();
    $vorher = [$rootConfig->selected_tier_id, $rootConfig->assumed_annual_revenue];

    // Genau der Weg, auf dem es kippen konnte: saveTiers las früher die GEERBTE Config und
    // hätte deren selected_tier_id umgehängt — ein Schreibzugriff über die Team-Grenze.
    $this->rebate->saveTiers($this->childA, $this->supplier->id, [
        ['threshold_eur' => 0, 'percent' => 9.0],
    ]);

    $rootConfig->refresh();
    expect([$rootConfig->selected_tier_id, $rootConfig->assumed_annual_revenue])->toBe($vorher);
    // Und die Staffel des Eltern-Teams steht noch vollständig da.
    expect(FoodAlchemistSupplierRebateTier::where('team_id', $this->rootTeam->id)->count())->toBe(2);
});

it('ein Geschwister-Team erbt vom Eltern-Team, nicht voneinander', function () {
    $this->rebate->saveTiers($this->childA, $this->supplier->id, [['threshold_eur' => 0, 'percent' => 5.0]]);
    $this->rebate->saveConfig($this->childA, $this->supplier->id, ['active' => true, 'assumed_annual_revenue' => 250000]);

    // B sieht A nicht — B erbt weiter von Root.
    expect($this->rebate->effektiverProzent($this->childB, $this->supplier->id))->toBe(3.0);
    expect($this->rebate->stufenInfo($this->childB, $this->supplier->id)['quelle_team_id'])->toBe($this->rootTeam->id);
});

it('eine manuell gewählte Stufe muss dem eigenen Team gehören', function () {
    $fremdeStufe = FoodAlchemistSupplierRebateTier::where('team_id', $this->rootTeam->id)
        ->orderByDesc('percent')->firstOrFail();

    $config = $this->rebate->saveConfig($this->childA, $this->supplier->id, [
        'active' => true,
        'selected_tier_id' => $fremdeStufe->id,
    ]);

    // Verworfen statt übernommen: sonst zeigte die Wahl in eine fremde Staffel und liefe beim
    // nächsten Staffel-Ersatz des Eltern-Teams still ins Leere.
    expect($config->selected_tier_id)->toBeNull();
});

it('ohne Kondition irgendwo bleibt es beim flachen Legacy-Satz', function () {
    $anderer = FoodAlchemistSupplier::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Ohne Staffel', 'status' => 'aktiv', 'rebate_pct' => 1.5,
    ]);

    expect($this->rebate->effektiverProzent($this->childA, $anderer->id))->toBe(1.5);
    expect($this->rebate->stufenInfo($this->childA, $anderer->id)['geerbt'])->toBeFalse();
});
