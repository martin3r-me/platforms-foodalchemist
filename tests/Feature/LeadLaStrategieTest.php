<?php

use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Services\LeadLaStrategieResolver;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M1-05 (DoD): Die Team-Einstellung ändert die Lead-Wahl NACHWEISBAR —
 * gleiche Kandidaten, drei Strategien, drei verschiedene Leads.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->settings = app(TeamSettingsService::class);
    $this->resolver = app(LeadLaStrategieResolver::class);

    // 3 Kandidaten: billig (Lieferant 10) · Stamm (Lieferant 20, teurer) · Priorität (Lieferant 30, am teuersten)
    $this->kandidaten = collect([
        (object) ['supplier_item_id' => 101, 'supplier_id' => 10, 'vergleichspreis' => 1.99],
        (object) ['supplier_item_id' => 102, 'supplier_id' => 20, 'vergleichspreis' => 2.49],
        (object) ['supplier_item_id' => 103, 'supplier_id' => 30, 'vergleichspreis' => 3.99],
        (object) ['supplier_item_id' => 104, 'supplier_id' => 40, 'vergleichspreis' => null], // qty-NULL-Falle
    ]);
});

it('guenstigster_preis: billigster gewinnt, NULL-Preis IMMER zuletzt (GL-03 A-2)', function () {
    $this->settings->update($this->childA, ['lead_la_strategie' => LeadLaStrategie::GuenstigsterPreis]);

    $sortiert = $this->resolver->sortiere($this->childA, $this->kandidaten);

    expect($sortiert->first()->supplier_item_id)->toBe(101)
        ->and($sortiert->last()->supplier_item_id)->toBe(104);
});

it('stamm_lieferant: Stamm gewinnt trotz höherem Preis', function () {
    $this->settings->update($this->childA, ['lead_la_strategie' => LeadLaStrategie::StammLieferant]);

    $sortiert = $this->resolver->sortiere($this->childA, $this->kandidaten, stammSupplierIds: [20]);

    expect($sortiert->first()->supplier_item_id)->toBe(102)
        ->and($sortiert->get(1)->supplier_item_id)->toBe(101); // danach regulär nach Preis
});

it('prioritaets_kette: Ketten-Position schlägt Preis, Rest folgt nach Preis', function () {
    $this->settings->update($this->childA, [
        'lead_la_strategie' => LeadLaStrategie::PrioritaetsKette,
        'lead_la_prioritaeten' => [30, 20],
    ]);

    $sortiert = $this->resolver->sortiere($this->childA, $this->kandidaten);

    expect($sortiert->pluck('supplier_item_id')->all())->toBe([103, 102, 101, 104]);
});

it('DoD: dieselben Kandidaten, drei Strategien, drei verschiedene Leads', function () {
    $leads = [];
    foreach ([
        [LeadLaStrategie::GuenstigsterPreis, []],
        [LeadLaStrategie::StammLieferant, [20]],
        [LeadLaStrategie::PrioritaetsKette, []],
    ] as [$strategie, $stamm]) {
        $this->settings->update($this->childA, ['lead_la_strategie' => $strategie, 'lead_la_prioritaeten' => [30, 20]]);
        $leads[$strategie->value] = $this->resolver->sortiere($this->childA, $this->kandidaten, $stamm)->first()->supplier_item_id;
    }

    expect($leads)->toBe(['guenstigster_preis' => 101, 'stamm_lieferant' => 102, 'prioritaets_kette' => 103]);
});

it('Einstellungen persistieren je Team — Geschwister bleiben beim Default', function () {
    $this->settings->update($this->childA, ['lead_la_strategie' => LeadLaStrategie::GuenstigsterPreis, 'ausweich_kette_anzeigen' => true]);

    expect($this->settings->leadLaStrategie($this->childA))->toBe(LeadLaStrategie::GuenstigsterPreis)
        ->and($this->settings->ausweichKetteAnzeigen($this->childA))->toBeTrue()
        // V-27-Default = stamm_lieferant (Ist-Verhalten, GL-03 §6 — seit M3-06)
        ->and($this->settings->leadLaStrategie($this->childB))->toBe(LeadLaStrategie::StammLieferant)
        ->and($this->settings->ausweichKetteAnzeigen($this->childB))->toBeFalse();
});
