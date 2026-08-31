<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Herstellkosten;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Herstellkosten als eigenständige Betriebs-Kalkulation (VOLLE KOPIE, KEINE Vererbung).
 * Ein gewählter Betrieb wird mit den Team-Werten vorbefüllt; Speichern schreibt ALLE als eigene
 * (nie null) und übernimmt die Team-Fixkosten. Team-Pfad bleibt unverändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->childA));
    $this->team = $this->childA;
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->team->id, 'name' => 'Kantine Süd']);
    $this->settings = app(TeamSettingsService::class);
    $this->outletSvc = app(OutletSettingsService::class);
    $this->fix = app(FixkostenService::class);
});

it('Team-Scope speichert weiter in die Team-Settings (unveränderter Pfad)', function () {
    Livewire::test(Herstellkosten::class)
        ->set('marge', '25')
        ->call('speichern')
        ->assertHasNoErrors();

    expect($this->settings->margePct($this->team))->toBe(25.0)
        ->and($this->outletSvc->for($this->betrieb->fresh())->margin_pct)->toBeNull();
});

it('Betrieb-Scope: Speichern schreibt ALLE Felder als eigene (nie null)', function () {
    Livewire::test(Herstellkosten::class)
        ->set('outletId', $this->betrieb->id)
        ->set('marge', '60')
        ->call('speichern')
        ->assertHasNoErrors();

    $s = $this->outletSvc->for($this->betrieb->fresh());
    // Marge eigener Wert; die anderen als volle Kopie der Team-Werte (nicht null).
    expect((float) $s->margin_pct)->toBe(60.0)
        ->and($s->target_food_cost_pct)->not->toBeNull()
        ->and($s->stundensatz_eur)->not->toBeNull()
        ->and($s->labor_cost_source)->not->toBeNull()
        ->and($s->calculation_schema)->toBeArray()->not->toBeEmpty();

    expect($this->settings->margePct($this->team, $this->betrieb->fresh()))->toBe(60.0)
        ->and($this->settings->margePct($this->team))->not->toBe(60.0);
});

it('Betrieb-Scope: Speichern übernimmt beim ersten Mal die Team-Fixkosten als eigene', function () {
    $this->fix->create($this->team, ['label' => 'Miete', 'amount' => 1000, 'periode' => 'monatlich', 'block_key' => 'gemeinkosten']);

    Livewire::test(Herstellkosten::class)
        ->set('outletId', $this->betrieb->id)
        ->call('speichern')
        ->assertHasNoErrors();

    // Betrieb hat jetzt eine eigene Kopie der Team-Fixkosten (keine Vererbung, sondern echte Zeile).
    expect($this->fix->listeFuerOutlet($this->team, $this->betrieb->fresh()))->toHaveCount(1)
        ->and($this->fix->summeJeBlock($this->team, $this->betrieb->fresh())['gemeinkosten'])->toBe(1000.0);
});

it('teamFixkostenUebernehmen kopiert die Team-Zeilen; idempotent', function () {
    $this->fix->create($this->team, ['label' => 'Miete', 'amount' => 1000, 'periode' => 'monatlich', 'block_key' => 'gemeinkosten']);

    $c = Livewire::test(Herstellkosten::class)->set('outletId', $this->betrieb->id)
        ->call('teamFixkostenUebernehmen');
    expect($this->fix->listeFuerOutlet($this->team, $this->betrieb->fresh()))->toHaveCount(1);

    // Zweiter Aufruf mischt nicht dazu (idempotent).
    $c->call('teamFixkostenUebernehmen');
    expect($this->fix->listeFuerOutlet($this->team, $this->betrieb->fresh()))->toHaveCount(1);
});

it('aufTeamZuruecksetzen nullt Settings UND löscht die eigenen Fixkosten', function () {
    $this->outletSvc->update($this->team, $this->betrieb, ['margin_pct' => 60]);
    $this->fix->create($this->team, ['label' => 'Eigen', 'amount' => 200, 'periode' => 'monatlich', 'block_key' => 'gemeinkosten'], $this->betrieb);

    Livewire::test(Herstellkosten::class)
        ->set('outletId', $this->betrieb->id)
        ->call('aufTeamZuruecksetzen')
        ->assertHasNoErrors();

    $s = $this->outletSvc->for($this->betrieb->fresh());
    expect($s->margin_pct)->toBeNull()
        ->and($s->calculation_schema)->toBeNull()
        ->and($this->fix->listeFuerOutlet($this->team, $this->betrieb->fresh()))->toHaveCount(0);
});

it('fremder Betrieb wird nicht als Scope akzeptiert → Team-Speichern', function () {
    $fremd = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);

    Livewire::test(Herstellkosten::class)
        ->set('outletId', $fremd->id)
        ->set('marge', '77')
        ->call('speichern')
        ->assertHasNoErrors();

    expect($this->outletSvc->for($fremd->fresh())->margin_pct)->toBeNull()
        ->and($this->settings->margePct($this->team))->toBe(77.0);
});
