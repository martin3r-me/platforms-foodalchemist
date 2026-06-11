<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Feedback 2026-06-11: „+ Neuer Lieferant" — Anlage je Team (D1) + Lebenszyklus.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SupplierService::class);
});

it('legt Lieferanten für das eigene Team an — Kind-eigene bleiben Eltern/Geschwistern verborgen', function () {
    $eigen = $this->svc->create($this->childA, ['name' => 'Hofladen Müller', 'city' => 'Köln']);

    expect($eigen->team_id)->toBe($this->childA->id)
        ->and(FoodAlchemistSupplier::visibleToTeam($this->childA)->pluck('id'))->toContain($eigen->id)
        ->and(FoodAlchemistSupplier::visibleToTeam($this->rootTeam)->pluck('id'))->not->toContain($eigen->id)
        ->and(FoodAlchemistSupplier::visibleToTeam($this->childB)->pluck('id'))->not->toContain($eigen->id);
});

it('Pflichtname + Duplikat-Guard in der Team-Kette (V-06)', function () {
    $this->svc->create($this->rootTeam, ['name' => 'BOS Food']);

    expect(fn () => $this->svc->create($this->rootTeam, ['name' => '  ']))
        ->toThrow(RuntimeException::class, 'Pflicht');
    expect(fn () => $this->svc->create($this->childA, ['name' => 'bos food'])) // geerbt + case-insensitiv
        ->toThrow(RuntimeException::class, 'existiert bereits');
});

it('setInactive: soft, nur Besitzer-Team', function () {
    $bos = $this->svc->create($this->rootTeam, ['name' => 'BOS Food']);
    $this->svc->setInactive($this->rootTeam, $bos->id, true);

    expect($bos->fresh()->is_inactive)->toBeTrue()
        ->and($this->svc->listWithCounts($this->rootTeam)->pluck('id'))->not->toContain($bos->id);

    expect(fn () => $this->svc->setInactive($this->childA, $bos->id, false))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});
