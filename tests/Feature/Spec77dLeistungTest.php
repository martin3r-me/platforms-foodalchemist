<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryBatch;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\EigenproduktionService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Spec 77d · Prüfpunkte aus dem Review: (1) die Einschränkungs-Prüfung kostet höchstens EINE Abfrage je Team
 * und Prozess, nie eine je Scope-Aufruf (visibleToTeam läuft in Schleifen: Planungsblatt, Bestellrunde,
 * Browser, MCP-Listen). (2) Team-strikte Wege (Chargen) bleiben nutzbar, auch wenn das Rezept nicht mehr
 * freigegeben ist.
 */
beforeEach(function () {
    $this->seedWareneingang();
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Rinderfond');
});

function abfragenFuer(callable $fn): int
{
    TeamAncestryRegistry::flushAll();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

it('Leistung: 50 Scope-Aufrufe kosten mit und ohne Einschränkung höchstens eine Zusatz-Abfrage', function () {
    $schleife = fn () => collect(range(1, 50))->each(fn () => FoodAlchemistRecipe::visibleToTeam($this->childA)->whereKey($this->rezept->id)->exists());

    $ohne = abfragenFuer($schleife);
    app(InhaltsFreigabeService::class)->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->inhaber);
    $mit = abfragenFuer($schleife);

    // 50 eigentliche Abfragen + Ketten-Aufbau + genau eine Prüfung je Team (gemerkt)
    expect($mit - $ohne)->toBeLessThanOrEqual(1)
        ->and($mit)->toBeLessThan(60);
    // Oberteam (Kette = 1): keine Prüfabfrage
    $oben = abfragenFuer(fn () => collect(range(1, 50))->each(fn () => FoodAlchemistRecipe::visibleToTeam($this->rootTeam)->exists()));
    expect($oben)->toBeLessThanOrEqual(51);
});

it('Chargen bleiben entnehmbar, auch wenn das Rezept nicht mehr freigegeben ist', function () {
    $ort = FoodAlchemistInventoryLocation::create(['team_id' => $this->childA->id, 'name' => 'Kühlhaus', 'type' => 'cold', 'is_default' => true, 'is_active' => true]);
    FoodAlchemistInventoryBatch::create(['team_id' => $this->childA->id, 'recipe_id' => $this->rezept->id, 'inventory_location_id' => $ort->id,
        'charge' => 'C-1', 'base_unit' => 'g', 'qty_initial' => 5000, 'qty_rest' => 5000, 'produced_at' => now()]);
    app(InhaltsFreigabeService::class)->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->inhaber);
    expect(FoodAlchemistRecipe::visibleToTeam($this->childA)->whereKey($this->rezept->id)->exists())->toBeFalse();

    $svc = app(EigenproduktionService::class);
    expect($svc->offeneChargen($this->childA, $this->rezept->id))->toHaveCount(1);
    $svc->entnehmen($this->childA, ['recipe_id' => $this->rezept->id, 'menge' => '1', 'grund' => 'verbrauch']);
    expect((float) FoodAlchemistInventoryBatch::where('team_id', $this->childA->id)->value('qty_rest'))->toBe(4000.0);
});
