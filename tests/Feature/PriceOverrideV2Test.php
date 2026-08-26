<?php

use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
});

it('verlangt für neue Fixpreise auf Paket, Concept und Angebot eine Begründung', function () {
    $packages = app(PaketService::class);
    $concepts = app(ConceptService::class);
    $offers = app(AngebotService::class);
    $package = $packages->create($this->rootTeam, ['name' => 'Paket', 'price_mode' => 'auto']);
    $concept = $concepts->create($this->rootTeam, ['name' => 'Concept']);
    $offer = $offers->create($this->rootTeam, ['name' => 'Angebot']);

    expect(fn () => $packages->update($this->rootTeam, $package->id, [
        'price_mode' => 'fixed', 'price_per_person' => 20,
    ]))->toThrow(RuntimeException::class)
        ->and(fn () => $concepts->update($this->rootTeam, $concept->id, [
            'price_mode' => 'fixed', 'price_per_person_manual' => 30,
        ]))->toThrow(RuntimeException::class)
        ->and(fn () => $offers->update($this->rootTeam, $offer->id, [
            'price_mode' => 'fixed', 'total_price' => 3000,
        ]))->toThrow(RuntimeException::class);
});

it('hält Fixpreise stabil und führt den Auto-Vergleich weiter', function () {
    $packages = app(PaketService::class);
    $concepts = app(ConceptService::class);
    $offers = app(AngebotService::class);
    $package = $packages->create($this->rootTeam, ['name' => 'Paket', 'price_mode' => 'auto']);
    $packages->update($this->rootTeam, $package->id, [
        'price_mode' => 'fixed', 'price_per_person' => 20,
        'price_override_reason' => 'Rahmenvertrag',
    ]);
    $packages->recomputePrice($package->fresh());

    $concept = $concepts->create($this->rootTeam, ['name' => 'Concept']);
    $concepts->update($this->rootTeam, $concept->id, [
        'price_mode' => 'fixed', 'price_per_person_manual' => 30,
        'price_override_reason' => 'Vertragspreis',
    ]);
    $concepts->recomputeCache($concept->fresh());

    $offer = $offers->create($this->rootTeam, ['name' => 'Angebot', 'personen' => 100]);
    $offers->update($this->rootTeam, $offer->id, [
        'price_mode' => 'fixed', 'total_price' => 3000,
        'price_override_reason' => 'Kundenfreigabe',
    ]);
    $offers->aktualisiereAutoPreis($this->rootTeam, $offer->fresh());

    expect($package->fresh()->price_mode)->toBe('fixed')
        ->and((float) $package->fresh()->price_per_person)->toBe(20.0)
        ->and($package->fresh()->calculated_price_per_person)->toBeNull()
        ->and($concept->fresh()->price_mode)->toBe('fixed')
        ->and((float) $concept->fresh()->price_per_person_cache)->toBe(30.0)
        ->and((float) $concept->fresh()->calculated_price_per_person)->toBe(0.0)
        ->and($offer->fresh()->price_mode)->toBe('fixed')
        ->and((float) $offer->fresh()->total_price)->toBe(3000.0)
        ->and((float) $offer->fresh()->calculated_total_price)->toBe(0.0);
});
