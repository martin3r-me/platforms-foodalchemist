<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LaCandidateFinder;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Entscheidung 10.10. (Lauf 89): ein §11.2-Derivat bekommt seine Mutter nur aus ROHWARE (frisch/TK, keine
 * Verarbeitung). Auf demo gab es für „Geflügel" nur Grillwurst/Lyoner/Burger — das Derivat hätte deren Allergene LIVE
 * geerbt. Eine offene Zeile ist besser.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->la = fn (string $d) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => $d, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
});

it('Mutter-LA nur verarbeitet → kein Derivat, keine Mutter, nichts angelegt', function () {
    ($this->la)('Gefluegel Grillwurst frisch');
    ($this->la)('Gefluegel Lyoner Scheiben');
    $vorher = FoodAlchemistGp::count();

    expect(app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Geflügelabschnitte'))->toBeNull()
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});

it('auch ein schon gemapptes Wurst-GP wird nicht zur Mutter', function () {
    $wurst = $this->makeGp($this->rootTeam, 'Gefluegel Grillwurst: frisch, angebraten');
    $la = ($this->la)('Gefluegel Grillwurst frisch');
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $wurst->id]);

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Geflügelabschnitte');

    expect($gp)->toBeNull()
        ->and(FoodAlchemistGp::where('derivat_von_gp_id', $wurst->id)->exists())->toBeFalse();
});

it('Rohware daneben → Derivat mit Rohware-Mutter', function () {
    ($this->la)('Gefluegel Grillwurst frisch');
    ($this->la)('Gefluegel ganz frisch');

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Geflügelabschnitte');

    expect($gp?->name)->toBe('Geflügel: frisch, Abschnitte')
        ->and(FoodAlchemistGp::find($gp->derivat_von_gp_id)->name)->toBe('Geflügel');
});

it('istRohwareVon: Teilstück ja, anderes Produkt / Zubereitung / Verarbeitung nein', function () {
    $f = app(LaCandidateFinder::class);

    expect($f->istRohwareVon('Rind frisch', 'rind'))->toBeTrue()
        ->and($f->istRohwareVon('Rinderhüfte frisch', 'rind'))->toBeTrue()
        ->and($f->istRohwareVon('Rind TK', 'rind'))->toBeTrue()
        ->and($f->istRohwareVon('Rinderbrühe', 'rind'))->toBeFalse()
        ->and($f->istRohwareVon('Gefluegel Grillwurst frisch', 'gefluegel'))->toBeFalse()
        ->and($f->istRohwareVon('Gefluegel Lyoner Scheiben', 'gefluegel'))->toBeFalse()
        ->and($f->istRohwareVon('Gefluegelbrust geräuchert', 'gefluegel'))->toBeFalse()
        ->and($f->istRohwareVon('Rindfleisch konserviert', 'rind'))->toBeFalse();
});
