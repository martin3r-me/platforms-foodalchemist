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
        'label' => 'Teller flach', 'rental_price' => 'abc', 'pfand' => '-5',
    ]);
    expect($bad->rental_price)->toBeNull()
        ->and($bad->pfand)->toBeNull();

    $ok = $this->svc->createItem($this->rootTeam, $this->supplier->id, [
        'label' => 'Teller tief', 'rental_price' => '1,50', 'pfand' => '0',
    ]);
    expect((float) $ok->rental_price)->toBe(1.5)
        ->and((float) $ok->pfand)->toBe(0.0); // legitime 0 bleibt erhalten
});
