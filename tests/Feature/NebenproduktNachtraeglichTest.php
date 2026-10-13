<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Nebenprodukt (§11.2) nachträglich: Fond/Jus mit „Rinderparüren“ standen auf Allergen-Sicherheit
 * „niedrig“ (Fall 13.10.2026). Zwei Lücken:
 *  1. updateGp setzte is_derivat, aber requires_la blieb 1 ⇒ „Preis fehlt“.
 *  2. Die Rezept-Konfidenz las das eigene (leere) LA-Profil des Derivats ⇒ NONE ⇒ Rezept LOW,
 *     obwohl die WERTE längst LIVE von der Mutter kommen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Delta Test']);

    // Mutter mit vollständigem, eindeutigem LA-Profil (alle 14 nicht enthalten) ⇒ HIGH
    $this->mutter = $this->makeGp($this->rootTeam, 'Roastbeef: frisch');
    $item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id, 'designation' => 'Roastbeef LA',
    ]);
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $this->mutter->id,
    ]);
    FoodAlchemistItemAllergen::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id,
        ...collect(FoodAlchemistGp::ALLERGEN_FIELDS)->mapWithKeys(fn ($f) => ["allergen_{$f}" => 'nicht_enthalten'])->all(),
    ]);
});

it('Derivat übernimmt die Allergen-Sicherheit der Mutter (HIGH statt NONE)', function () {
    $derivat = $this->makeGp($this->rootTeam, 'Rinderparüren: frisch');
    $derivat->update(['is_derivat' => true, 'derivat_von_gp_id' => $this->mutter->id, 'requires_la' => false]);

    $agg = app(GpAggregateService::class);
    expect($agg->allergenKonfidenz($this->mutter)['confidence'])->toBe('high')
        ->and($agg->allergenKonfidenz($derivat->fresh())['confidence'])->toBe('high');

    // Backfill schreibt dieselbe Sicherheit, Quelle bleibt „derivat“
    $r = $agg->backfillAllergenKonfidenz($derivat->fresh());
    expect($r['confidence'])->toBe('high')->and($r['source'])->toBe('derivat');

    // Mutter ohne LA-Profil ⇒ Derivat bekommt keine Scheinsicherheit
    $leereMutter = $this->makeGp($this->rootTeam, 'Rinderhuefte: frisch');
    $derivat->update(['derivat_von_gp_id' => $leereMutter->id]);
    expect($agg->allergenKonfidenz($derivat->fresh())['confidence'])->toBe('none');
});

it('Rezept mit Derivat-Zutat wird nicht mehr auf LOW gedeckelt', function () {
    $derivat = $this->makeGp($this->rootTeam, 'Rinderparüren: frisch');
    $derivat->update(['is_derivat' => true, 'derivat_von_gp_id' => $this->mutter->id, 'requires_la' => false]);

    $g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ])->id;
    $rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'jus_rind_test', 'name' => 'Jus Rind Test', 'status' => 'draft',
    ]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $rezept->id, 'position' => 1, 'raw_text' => 'Rinderparüren',
        'match_method' => 'manual', 'unit_vocab_id' => $g, 'quantity' => 1000, 'gp_id' => $derivat->id,
    ]);

    app(RecipeRecomputeService::class)->recomputePipeline($rezept->id);

    expect($rezept->fresh()->allergens_confidence)->toBe('high');
});

it('updateGp: nachträglich zum Derivat ⇒ requires_la=0, zurück ⇒ requires_la=1', function () {
    $gp = $this->makeGp($this->rootTeam, 'Rinderabschnitte: frisch');
    $gp->update(['condition' => 'frisch', 'requires_la' => true]);
    $naming = app(GpNamingService::class);

    $naming->updateGp($this->rootTeam, $gp, [
        'name' => 'Rinderabschnitte: frisch', 'condition' => 'frisch',
        'is_derivat' => true, 'derivat_von_gp_id' => $this->mutter->id,
    ]);
    expect($gp->fresh()->is_derivat)->toBeTrue()
        ->and($gp->fresh()->requires_la)->toBeFalse();

    $naming->updateGp($this->rootTeam, $gp->fresh(), [
        'name' => 'Rinderabschnitte: frisch', 'condition' => 'frisch', 'is_derivat' => false, 'derivat_von_gp_id' => null,
    ]);
    expect($gp->fresh()->is_derivat)->toBeFalse()
        ->and($gp->fresh()->requires_la)->toBeTrue();
});

it('updateGp ohne Derivat-Wechsel lässt einen bewusst gesetzten Platzhalter (requires_la=0) stehen', function () {
    $gp = $this->makeGp($this->rootTeam, 'Wasser: Leitung');
    $gp->update(['requires_la' => false]);

    app(GpNamingService::class)->updateGp($this->rootTeam, $gp, ['name' => 'Wasser: Leitung']);

    expect($gp->fresh()->requires_la)->toBeFalse();
});
