<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M2-01/02/03: Lieferanten-Browser-Services — P-7-Zähler, Artikel-Listen, globale Suche.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->bos = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS Food']);
    $this->edna = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Edna', 'is_inactive' => true]);

    $gp = $this->makeGp($this->rootTeam, 'Limettensaft');
    $mk = fn (string $bez, array $extra = []) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->bos->id, 'designation' => $bez, ...$extra,
    ]);
    $this->limette = $mk('Limettensaft konserviert 1l', ['qty' => 1, 'unit_code' => 'l']);
    $mk('Zanderfilet TK');
    $mk('Altes Sortiment', ['is_discontinued' => true]);

    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->limette->id, 'gp_id' => $gp->id]);
});

it('P-7-Zähler stimmen mit SQL überein (n Artikel · m gemapped)', function () {
    $liste = app(SupplierService::class)->listWithCounts($this->rootTeam);

    $bos = $liste->firstWhere('id', $this->bos->id);
    expect($bos->item_count)->toBe(3)
        ->and($bos->mapped_count)->toBe(1)
        ->and($liste->pluck('id'))->not->toContain($this->edna->id); // inaktiv ausgeblendet

    expect(app(SupplierService::class)->listWithCounts($this->rootTeam, includeInactive: true)->pluck('id'))
        ->toContain($this->edna->id);
});

it('Artikel-Liste: Nur-aktive-Filter + GP-Mapping geladen', function () {
    $svc = app(SupplierItemService::class);

    expect($svc->paginateForSupplier($this->rootTeam, $this->bos->id)->total())->toBe(2) // onlyActive default
        ->and($svc->paginateForSupplier($this->rootTeam, $this->bos->id, ['onlyActive' => false])->total())->toBe(3);

    $zeile = $svc->paginateForSupplier($this->rootTeam, $this->bos->id)->getCollection()
        ->firstWhere('id', $this->limette->id);
    expect($zeile->structure?->gp?->name)->toBe('Limettensaft');
});

it('globale Suche findet über Lieferanten hinweg, case-insensitiv (DoD M2-03)', function () {
    $treffer = app(SupplierItemService::class)->searchGlobal($this->rootTeam, 'limettensaft');

    expect($treffer->total())->toBe(1)
        ->and($treffer->getCollection()->first()->supplier?->name)->toBe('BOS Food');
});

it('Geschwister-Team sieht im Browser nichts (D1-Leak-Check)', function () {
    expect(app(SupplierService::class)->listWithCounts($this->childB)->pluck('id'))->toContain($this->bos->id) // geerbt sichtbar
        ->and(app(SupplierItemService::class)->searchGlobal($this->childB, 'limettensaft')->total())->toBe(1);

    // Kind-eigener Artikel bleibt für Geschwister unsichtbar
    FoodAlchemistSupplierItem::create(['team_id' => $this->childA->id, 'supplier_id' => $this->bos->id, 'designation' => 'Limettensaft Eigenmarke A']);
    expect(app(SupplierItemService::class)->searchGlobal($this->childA, 'limettensaft')->total())->toBe(2)
        ->and(app(SupplierItemService::class)->searchGlobal($this->childB, 'limettensaft')->total())->toBe(1);
});
