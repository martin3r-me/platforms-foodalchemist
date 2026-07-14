<?php

use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * P3 — GP-Allergen-Backfill: persistiert NUR Metadaten (Konfidenz/Quelle/Zeitpunkt),
 * niemals die 14 Wert-Spalten (Korrektur #1). Konflikt → Signal. manual bleibt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
});

/** LA + Struktur(gp_id) + Allergen-Zeile (gluten-Wert, Rest default). */
function laMitGluten(int $teamId, int $supplierId, int $gpId, string $gluten): void
{
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $teamId, 'supplier_id' => $supplierId, 'designation' => 'X ' . $gluten, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $teamId, 'supplier_item_id' => $la->id, 'gp_id' => $gpId]);
    FoodAlchemistItemAllergen::create(['team_id' => $teamId, 'supplier_item_id' => $la->id, 'allergen_gluten' => $gluten]);
}

it('Konflikt (enthalten↔nicht) → LOW, Signal, und die Wert-Spalten bleiben unberührt', function () {
    $gp = $this->makeGp($this->rootTeam, 'Brotmix');
    $gp->update(['status' => 'approved']);
    laMitGluten($this->rootTeam->id, $this->sup->id, $gp->id, 'enthalten');
    laMitGluten($this->rootTeam->id, $this->sup->id, $gp->id, 'nicht_enthalten');

    $glutenVorher = $gp->fresh()->allergen_gluten;   // Default 'unbekannt'

    $this->artisan('foodalchemist:gp-allergen-backfill', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();

    $gp->refresh();
    expect((float) $gp->allergens_confidence)->toBe(0.33)               // low
        ->and($gp->allergens_source)->toBe('la_union')
        ->and($gp->allergens_aggregated_at)->not->toBeNull()
        ->and($gp->allergen_gluten)->toBe($glutenVorher);               // ⬅ Wert-Spalte NICHT geschrieben (Korrektur #1)

    expect(FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('dedup_key', 'dq-gp-allergen-konflikt')->exists())->toBeTrue();
});

it('konsistente LA-Daten → HIGH (1.0)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Butter');
    $gp->update(['status' => 'approved']);
    laMitGluten($this->rootTeam->id, $this->sup->id, $gp->id, 'nicht_enthalten');

    $this->artisan('foodalchemist:gp-allergen-backfill', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();
    expect((float) $gp->refresh()->allergens_confidence)->toBe(1.0)
        ->and($gp->allergens_source)->toBe('la_union');
});

it('manuell kuratierte GPs bleiben unangetastet', function () {
    $gp = $this->makeGp($this->rootTeam, 'Handkuratiert');
    $gp->update(['status' => 'approved', 'allergens_source' => 'manual', 'allergens_confidence' => 0.5]);
    laMitGluten($this->rootTeam->id, $this->sup->id, $gp->id, 'enthalten');

    $this->artisan('foodalchemist:gp-allergen-backfill', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();
    expect((float) $gp->refresh()->allergens_confidence)->toBe(0.5)
        ->and($gp->allergens_source)->toBe('manual');
});

it('dry-run schreibt nichts', function () {
    $gp = $this->makeGp($this->rootTeam, 'Trockentest');
    $gp->update(['status' => 'approved']);
    laMitGluten($this->rootTeam->id, $this->sup->id, $gp->id, 'enthalten');

    $this->artisan('foodalchemist:gp-allergen-backfill', ['--team' => $this->rootTeam->id])->assertSuccessful();
    expect($gp->refresh()->allergens_confidence)->toBeNull();
});
