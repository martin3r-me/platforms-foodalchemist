<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Herstellkosten;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Herstellkosten & Zuschläge als Voll-Editor je Betrieb.
 * Seiten-Wähler (outletId) scopet die ganze Seite: Skalare erben pro Feld (leer = null),
 * Zuschlagsschema/Bezugsbasen nur bei „eigenes Schema". Team-Pfad bleibt unverändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->childA));
    $this->team = $this->childA;
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->team->id, 'name' => 'Kantine Süd']);
    $this->settings = app(TeamSettingsService::class);
    $this->outletSvc = app(OutletSettingsService::class);
});

it('Team-Scope speichert weiter in die Team-Settings (unveränderter Pfad)', function () {
    Livewire::test(Herstellkosten::class)
        ->set('marge', '25')
        ->call('speichern')
        ->assertHasNoErrors();

    expect($this->settings->margePct($this->team))->toBe(25.0);
    // Betrieb blieb ohne eigenen Override.
    expect($this->outletSvc->for($this->betrieb->fresh())->margin_pct)->toBeNull();
});

it('Betrieb-Scope: Skalare pro Feld — gesetzt schreibt, leer = erbt (null)', function () {
    Livewire::test(Herstellkosten::class)
        ->set('outletId', $this->betrieb->id)
        ->assertSet('eigenesSchema', false)      // noch kein eigenes Schema
        ->set('marge', '60')
        ->set('zielWe', '')                       // leer = erbt
        ->call('speichern')
        ->assertHasNoErrors();

    $s = $this->outletSvc->for($this->betrieb->fresh());
    expect((float) $s->margin_pct)->toBe(60.0)
        ->and($s->target_food_cost_pct)->toBeNull()
        ->and($s->calculation_schema)->toBeNull();   // eigenesSchema aus → Schema erbt

    // Kaskade: Betriebs-Marge weicht ab, Team-Marge unverändert.
    expect($this->settings->margePct($this->team, $this->betrieb->fresh()))->toBe(60.0)
        ->and($this->settings->margePct($this->team))->not->toBe(60.0);
});

it('Betrieb-Scope: „eigenes Schema" an → calculation_schema + Bezugsbasen werden geschrieben', function () {
    Livewire::test(Herstellkosten::class)
        ->set('outletId', $this->betrieb->id)
        ->set('eigenesSchema', true)
        ->set('bezugsbasen.mek', '30000')
        ->call('speichern')
        ->assertHasNoErrors();

    $s = $this->outletSvc->for($this->betrieb->fresh());
    expect($s->calculation_schema)->toBeArray()->not->toBeEmpty()
        ->and((float) $s->calculation_reference_bases['mek'])->toBe(30000.0);
});

it('Reset: aufTeamZuruecksetzen nullt alle Override-Spalten', function () {
    $this->outletSvc->update($this->team, $this->betrieb, [
        'margin_pct' => 60, 'calculation_schema' => [['key' => 'x', 'label' => 'X', 'type' => 'pct_mek', 'value' => 5, 'active' => true, 'mode' => 'manuell', 'sort' => 10]],
    ]);

    Livewire::test(Herstellkosten::class)
        ->set('outletId', $this->betrieb->id)
        ->assertSet('eigenesSchema', true)        // Override vorhanden
        ->call('aufTeamZuruecksetzen')
        ->assertHasNoErrors();

    $s = $this->outletSvc->for($this->betrieb->fresh());
    expect($s->margin_pct)->toBeNull()
        ->and($s->calculation_schema)->toBeNull()
        ->and($s->calculation_reference_bases)->toBeNull();
});

it('fremder Betrieb wird nicht als Scope akzeptiert → Team-Speichern', function () {
    $fremd = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);

    Livewire::test(Herstellkosten::class)
        ->set('outletId', $fremd->id)
        ->set('marge', '77')
        ->call('speichern')
        ->assertHasNoErrors();

    // Kein Override auf dem fremden Betrieb; stattdessen lief der Team-Pfad.
    expect($this->outletSvc->for($fremd->fresh())->margin_pct)->toBeNull()
        ->and($this->settings->margePct($this->team))->toBe(77.0);
});
