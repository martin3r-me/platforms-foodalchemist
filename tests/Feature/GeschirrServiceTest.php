<?php

use Platform\FoodAlchemist\Services\GeschirrService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Geschirr-Datenbank (#388): Leih-Servierartikel. Hier die Zahlen-Hygiene der
 * itemFelder-Normalisierung (leihpreis/pfand/Maße) — analog dezimalOrNull-Guard.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(GeschirrService::class);
    $this->supplier = $this->svc->createSupplier($this->rootTeam, ['name' => 'Geschirr Müller']);
});

it('Leihpreis/Pfand: Tippfehler/negativ wird null statt stiller 0; gültige Komma-Eingabe + legitime 0 bleiben', function () {
    $bad = $this->svc->createItem($this->rootTeam, $this->supplier->id, [
        'bezeichnung' => 'Teller flach', 'leihpreis' => 'abc', 'pfand' => '-5',
    ]);
    expect($bad->leihpreis)->toBeNull()
        ->and($bad->pfand)->toBeNull();

    $ok = $this->svc->createItem($this->rootTeam, $this->supplier->id, [
        'bezeichnung' => 'Teller tief', 'leihpreis' => '1,50', 'pfand' => '0',
    ]);
    expect((float) $ok->leihpreis)->toBe(1.5)
        ->and((float) $ok->pfand)->toBe(0.0); // legitime 0 bleibt erhalten
});
