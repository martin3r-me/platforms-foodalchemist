<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lauf 89 (demo, 10.10.):
 *  1. Rezept 3816 „Geflügelabschnitte" → GP „gefluegel: frisch, Abschnitte", Mutter „gefluegel" — der §11.2-Derivat-Pfad
 *     benannte die Mutter mit dem gestemmten Suchtext. Regelwerk §11.2: „<Mutter>: frisch, <Derivat-Form>".
 *  2. Rezept 3814: §2-Code-Befund aus dem GP-NAMEN „Rinderbeinscheiben: frisch, geschnitten" löste eine Heilrunde aus.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->la = fn (string $d) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => $d, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
});

it('Derivat: Mutter und Derivat tragen den Anzeigenamen mit Umlaut und Großschreibung', function () {
    ($this->la)('Gefluegel ganz frisch');

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Geflügelabschnitte');

    expect($gp)->toBeInstanceOf(FoodAlchemistGp::class)
        ->and($gp->name)->toBe('Geflügel: frisch, Abschnitte')
        ->and((bool) $gp->is_derivat)->toBeTrue()
        ->and(FoodAlchemistGp::find($gp->derivat_von_gp_id)->name)->toBe('Geflügel');
});

it('Derivat: Rinderparüren → Mutter „Rind" (Stamm, groß)', function () {
    ($this->la)('Rind frisch');

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Rinderparüren');

    expect($gp?->name)->toBe('Rind: frisch, Parüren')
        ->and(FoodAlchemistGp::find($gp->derivat_von_gp_id)->name)->toBe('Rind');
});

it('Mint: Hauptzutat aus kleingeschriebenem Text wird groß angelegt', function () {
    ($this->la)('Sesampaste');

    expect(app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'sesampaste')?->name)->toBe('Sesampaste');
});

it('heilbar: §2 nur im GP-Namen ist nicht heilbar, §2 in der Zeile bleibt heilbar', function () {
    $r = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'l89-jus', 'name' => 'Jus: Rind', 'status' => 'draft']);
    $g = $this->unitG($this->rootTeam)->id;
    $bein = $this->makeGp($this->rootTeam, 'Rinderbeinscheiben: frisch, geschnitten');
    $schalotte = $this->makeGp($this->rootTeam, 'Schalotten: frisch, gewürfelt');
    FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'gp_id' => $bein->id,
        'raw_text' => 'Rinderbeinscheiben: frisch', 'quantity' => '2000', 'unit_vocab_id' => $g, 'position' => 1]);
    FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'gp_id' => $schalotte->id,
        'raw_text' => 'Schalotten gewürfelt', 'quantity' => '200', 'unit_vocab_id' => $g, 'position' => 2]);
    $befund = fn (string $gpName) => ['paragraph' => '§2', 'schweregrad' => 'hart', 'feld' => 'zutat:' . $gpName,
        'begruendung' => 'Frische Zutat in Schnittform — Verarbeitung gehört nicht ins Grundprodukt (§2).', 'quelle' => 'code'];

    $heilbar = app(RecipeConformanceAdapter::class)->heilbar($this->rootTeam, $r->id,
        [$befund('Rinderbeinscheiben: frisch, geschnitten'), $befund('Schalotten: frisch, gewürfelt')]);

    expect(array_column($heilbar, 'feld'))->toBe(['zutat:Schalotten: frisch, gewürfelt']);
});
