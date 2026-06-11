<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M2-08 (DoD): Preis-Lebenszyklus — Historie konsistent nach 3 Operationen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->preise = app(PriceService::class);

    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS Food']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Limettensaft 1l', 'qty' => 1, 'unit_code' => 'l',
    ]);
});

it('DoD: Historie konsistent nach 3 Operationen (anlegen → anlegen → löschen)', function () {
    // Op 1: erster Preis — sofort aktiv (unbefristet)
    $p1 = $this->preise->createFor($this->rootTeam, $this->la, 10.00);
    expect($this->preise->activeFor($this->la->id)?->id)->toBe($p1->id);

    // Op 2: neuer Preis schließt Vorgänger (P-6)
    $p2 = $this->preise->createFor($this->rootTeam, $this->la, 12.00, '2');
    expect($this->preise->activeFor($this->la->id)?->id)->toBe($p2->id)
        ->and($p1->fresh()->valid_to)->not->toBeNull(); // gestempelt

    // Op 3: aktiven Preis löschen — Vorgänger bleibt gestempelt, wird aber wieder aktiv (neueste verbleibende)
    $this->preise->deleteFor($this->rootTeam, $this->la, $p2->id);
    $historie = $this->preise->historyFor($this->la->id);

    expect($historie)->toHaveCount(1)
        ->and($this->preise->activeFor($this->la->id)?->id)->toBe($p1->id)
        ->and($historie->first()->kategorie->istAktiv())->toBeTrue();
});

it('unbefristete neue Zeile schlägt gestempelte alte (Append-only-Ranking)', function () {
    $alt = $this->preise->createFor($this->rootTeam, $this->la, 10.00);
    $neu = $this->preise->createFor($this->rootTeam, $this->la, 12.00);

    expect($this->preise->activeFor($this->la->id)?->id)->toBe($neu->id)
        ->and((float) $this->preise->activeFor($this->la->id)->price)->toBe(12.00);
});

it('Guards: Kind-Team darf nicht, negative Preise nie, Status nur 0|2 (GL-11 I5)', function () {
    expect(fn () => $this->preise->createFor($this->childA, $this->la, 9.99))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
    expect(fn () => $this->preise->createFor($this->rootTeam, $this->la, -9.0))
        ->toThrow(RuntimeException::class, 'Service-Zuschläge');
    expect(fn () => $this->preise->createFor($this->rootTeam, $this->la, 5.0, '7'))
        ->toThrow(RuntimeException::class, 'Status');
});
