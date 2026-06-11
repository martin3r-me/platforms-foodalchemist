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

it('M2-14: update pflegt Stammdaten — nur Besitzer-Team', function () {
    $bos = $this->svc->create($this->rootTeam, ['name' => 'BOS Food']);

    $this->svc->update($this->rootTeam, $bos->id, ['name' => 'BOS Food GmbH', 'city' => 'Meerbusch']);
    expect($bos->fresh()->name)->toBe('BOS Food GmbH')
        ->and($bos->fresh()->city)->toBe('Meerbusch');

    expect(fn () => $this->svc->update($this->childA, $bos->id, ['name' => 'Gekapert']))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});

it('M2-14: lokale Artikel-Suche filtert nur den gewählten Lieferanten', function () {
    $bos = $this->svc->create($this->rootTeam, ['name' => 'BOS Food']);
    $edna = $this->svc->create($this->rootTeam, ['name' => 'Edna']);
    $items = app(\Platform\FoodAlchemist\Services\SupplierItemService::class);
    $items->create($this->rootTeam, $bos->id, ['designation' => 'Limettensaft 1l']);
    $items->create($this->rootTeam, $bos->id, ['designation' => 'Zander TK']);
    $items->create($this->rootTeam, $edna->id, ['designation' => 'Limettensaft Edna']);

    $treffer = $items->paginateForSupplier($this->rootTeam, $bos->id, ['q' => 'limette']);
    expect($treffer->total())->toBe(1)
        ->and($treffer->getCollection()->first()->designation)->toBe('Limettensaft 1l');
});
