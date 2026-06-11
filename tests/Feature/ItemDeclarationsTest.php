<?php

use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M2-15: LA-Deklarationen — rohe GL-09-Domäne {0,1,3,NULL}, UI-Übersetzung, D1-Gate.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SupplierItemService::class);

    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS Food']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Cola 1l',
    ]);
});

it('liefert IMMER 18 Werte — ohne Zeile alles unbekannt', function () {
    $werte = $this->svc->getDeclarations($this->la);

    expect($werte)->toHaveCount(18)
        ->and(array_unique(array_values($werte)))->toBe(['unbekannt'])
        ->and(array_keys($werte))->toBe(array_keys(FoodAlchemistItemDeclaration::STOFFE));
});

it('Set-Roundtrip schreibt die ROHE Domäne: ja⇒3, nein⇒1, unbekannt⇒0 (GL-09 A1) + manual-Stempel', function () {
    $this->svc->setDeclarations($this->rootTeam, $this->la, [
        'caffeinated' => 'ja', 'with_dye' => 'nein',
    ]);

    $zeile = $this->la->fresh()->declarations;
    expect((int) $zeile->caffeinated)->toBe(3)
        ->and((int) $zeile->with_dye)->toBe(1)
        ->and((int) $zeile->waxed)->toBe(0)
        ->and($zeile->quelle)->toBe('manual');

    $ui = $this->svc->getDeclarations($this->la->fresh());
    expect($ui['caffeinated'])->toBe('ja')
        ->and($ui['with_dye'])->toBe('nein')
        ->and($ui['waxed'])->toBe('unbekannt');
});

it('Kind-Team darf geerbte Deklarationen nicht pflegen; ungültige Werte typisiert abgelehnt', function () {
    expect(fn () => $this->svc->setDeclarations($this->childA, $this->la, ['caffeinated' => 'ja']))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
    expect(fn () => $this->svc->setDeclarations($this->rootTeam, $this->la, ['caffeinated' => 'vielleicht']))
        ->toThrow(RuntimeException::class, 'Ungültiger Deklarations-Wert');
});
