<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * P2 — Lead-LA-Repick: chirurgischer Fix nur dort, wo der aktuelle Lead nicht auf
 * einen Preis auflöst, ein anderer bepreister LA aber existiert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
});

/** LA + Struktur-Zeile (Verknüpfung zum GP) + optional aktiver Preis. */
function makeLa(int $teamId, int $supplierId, int $gpId, string $bez, ?float $preis): FoodAlchemistSupplierItem
{
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $teamId, 'supplier_id' => $supplierId, 'designation' => $bez, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $teamId, 'supplier_item_id' => $la->id, 'gp_id' => $gpId]);
    if ($preis !== null) {
        FoodAlchemistPrice::create(['team_id' => $teamId, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0', 'valid_to' => null]);
    }

    return $la;
}

it('fixt chirurgisch: Lead zeigt auf unbepreisten LA, es gibt einen bepreisten → --apply setzt den bepreisten', function () {
    $gp = $this->makeGp($this->rootTeam, 'Butter');
    $gp->update(['status' => 'approved']);
    $ohne = makeLa($this->rootTeam->id, $this->sup->id, $gp->id, 'Butter ohne Preis', null);
    $mit = makeLa($this->rootTeam->id, $this->sup->id, $gp->id, 'Butter mit Preis', 8.50);
    $gp->update(['lead_la_supplier_item_id' => $ohne->id, 'n_las_total' => 2]);

    // dry-run: nichts geschrieben
    $this->artisan('foodalchemist:lead-la-repick', ['--team' => $this->rootTeam->id])->assertSuccessful();
    expect($gp->refresh()->lead_la_supplier_item_id)->toBe($ohne->id);

    // apply: Lead springt auf den bepreisten LA
    $this->artisan('foodalchemist:lead-la-repick', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();
    expect($gp->refresh()->lead_la_supplier_item_id)->toBe($mit->id);
});

it('parkt: nur unbepreiste LAs → Lead bleibt unaufgelöst, kein Fix', function () {
    $gp = $this->makeGp($this->rootTeam, 'Safran');
    $gp->update(['status' => 'approved']);
    $ohne = makeLa($this->rootTeam->id, $this->sup->id, $gp->id, 'Safran ohne Preis', null);
    $gp->update(['lead_la_supplier_item_id' => $ohne->id, 'n_las_total' => 1]);

    $this->artisan('foodalchemist:lead-la-repick', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();
    // kein bepreister LA → Lead unverändert (Park-Fall, kein stiller Wechsel)
    expect($gp->refresh()->lead_la_supplier_item_id)->toBe($ohne->id);
});

it('lässt saubere Leads unangetastet: Lead löst bereits auf', function () {
    $gp = $this->makeGp($this->rootTeam, 'Mehl');
    $gp->update(['status' => 'approved']);
    $mit = makeLa($this->rootTeam->id, $this->sup->id, $gp->id, 'Mehl mit Preis', 1.20);
    $billiger = makeLa($this->rootTeam->id, $this->sup->id, $gp->id, 'Mehl billiger', 0.90);
    $gp->update(['lead_la_supplier_item_id' => $mit->id, 'n_las_total' => 2]);

    // Lead löst bereits auf → wird NICHT gewechselt (auch wenn ein billigerer existiert; kein Churn)
    $this->artisan('foodalchemist:lead-la-repick', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();
    expect($gp->refresh()->lead_la_supplier_item_id)->toBe($mit->id);
});
