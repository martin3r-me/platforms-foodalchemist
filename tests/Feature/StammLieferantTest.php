<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\StammLieferantService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M1-06: Stamm-Lieferanten-Matrix — Pflege + Lese-Vertrag (M3-06).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->stamm = app(StammLieferantService::class);

    $this->bos = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS Food']);
    $this->edna = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Edna Backwaren']);

    $this->stamm->setStamm($this->rootTeam, $this->bos->id);            // global
    $this->stamm->setStamm($this->rootTeam, $this->edna->id, '09');      // WG 09 Backwaren
});

it('Lese-Vertrag: WG-Stamm = WG-spezifisch + global (M3-06/Resolver)', function () {
    expect($this->stamm->stammSupplierIdsFor($this->rootTeam, '09'))->toBe([$this->bos->id, $this->edna->id])
        ->and($this->stamm->stammSupplierIdsFor($this->rootTeam, '01'))->toBe([$this->bos->id]) // nur global
        ->and($this->stamm->stammSupplierIdsFor($this->rootTeam))->toBe([$this->bos->id]);
});

it('setStamm ist idempotent und verlangt sichtbaren Lieferanten', function () {
    $a = $this->stamm->setStamm($this->rootTeam, $this->edna->id, '09');
    $b = $this->stamm->setStamm($this->rootTeam, $this->edna->id, '09');
    expect($a->id)->toBe($b->id);

    expect(fn () => $this->stamm->setStamm($this->rootTeam, 99999))
        ->toThrow(RuntimeException::class, 'visible');
});

it('D1: Kind erbt die Eltern-Matrix lesend und ergänzt Eigenes — Geschwister getrennt', function () {
    expect($this->stamm->stammSupplierIdsFor($this->childA, '09'))->toContain($this->edna->id); // geerbt

    $this->stamm->setStamm($this->childA, $this->bos->id, '01'); // eigener WG-Stamm
    expect($this->stamm->stammSupplierIdsFor($this->childA, '01'))->toContain($this->bos->id)
        ->and($this->stamm->stammSupplierIdsFor($this->childB, '01'))->toBe([$this->bos->id]); // B: nur global (geerbt), nicht As Eintrag
});

it('unsetStamm: eigene Zeile ja, geerbte Eltern-Zeile nie (D1)', function () {
    expect(fn () => $this->stamm->unsetStamm($this->childA, $this->edna->id, '09'))
        ->toThrow(RuntimeException::class, 'Eltern-Teams');

    expect($this->stamm->unsetStamm($this->rootTeam, $this->edna->id, '09'))->toBeTrue()
        ->and($this->stamm->stammSupplierIdsFor($this->rootTeam, '09'))->toBe([$this->bos->id]);
});
