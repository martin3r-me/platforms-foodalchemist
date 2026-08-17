<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * 07·M1 — LA-First-GP-Mint als geteilte Fähigkeit (aus dem Generator befreit).
 * Doktrin: kein GP ohne LA; Mint = tentative + LA-verknüpft; keine LA → kein GP.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(LaFirstGpService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);

    $this->mkLa = fn (string $designation) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'designation' => $designation, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
});

it('mintet ein tentatives, LA-verknüpftes GP für eine Lücke mit passender LA', function () {
    $la = ($this->mkLa)('Sesampaste');

    $gp = $this->svc->mintFromLa($this->rootTeam, 'Sesampaste');

    expect($gp)->toBeInstanceOf(FoodAlchemistGp::class)
        ->and($gp->status->value)->toBe('tentative')      // ReviewQueue-Quarantäne, Freigabe bleibt menschlich
        ->and($gp->team_id)->toBe($this->rootTeam->id)
        ->and($gp->requires_la)->toBeTrue();

    // LA ist verknüpft (Struktur-Zeile LA→GP) → Anreicherung fließt LA-abgeleitet.
    $struktur = FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->first();
    expect($struktur)->not->toBeNull()
        ->and($struktur->gp_id)->toBe($gp->id);
});

it('verwendet ein bereits gemapptes GP wieder statt neu zu minten', function () {
    $bestand = $this->makeGp($this->rootTeam, 'Sesampaste: geröstet');
    $la = ($this->mkLa)('Sesampaste');
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $bestand->id,
    ]);

    $vorher = FoodAlchemistGp::count();
    $gp = $this->svc->mintFromLa($this->rootTeam, 'Sesampaste');

    expect($gp->id)->toBe($bestand->id)
        ->and(FoodAlchemistGp::count())->toBe($vorher);   // kein Neu-Anlegen
});

it('keine passende LA → KEIN GP (Doktrin), sondern null', function () {
    $vorher = FoodAlchemistGp::count();

    $gp = $this->svc->mintFromLa($this->rootTeam, 'Marsianische Nichtzutat');

    expect($gp)->toBeNull()
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});

it('Generator-Integration: Lücke mit LA wird vorgeschlagen, aber noch nicht gemintet', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
    ] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }
    $la = ($this->mkLa)('Tahin');

    $vorher = FoodAlchemistGp::count();
    $resultat = app(RecipeGeneratorService::class)->generiere(
        $this->rootTeam, 'Sesam-Dip', [], kiRezeptOverride: [
            'name' => 'Dip: Sesam',
            'zutaten' => [['text' => 'Tahin', 'quantity' => 100, 'unit' => 'g']],
        ],
    );

    expect($resultat['statistik']['gp_neu_aus_la'])->toBe(0)
        ->and($resultat['statistik']['offen'])->toBe(1)
        ->and($resultat['offene'][0]['la_kandidaten'][0]['id'])->toBe($la->id);

    $zeile = $resultat['recipe']->ingredients()->first();
    expect($zeile->gp_id)->toBeNull()
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});

// T5 (Top-Down-Abschluss beim Freigeben): minteFehlendeGps zieht GPs für Rohzutaten ohne GP aus der
// besten LA (tentative) + verlinkt; kein LA → bleibt unbepreist (Doktrin); GP/Sub-Zeilen unangetastet.
it('T5: minteFehlendeGps mintet fehlende GPs aus passender LA (tentative) + verlinkt; ohne LA bleibt unbepreist', function () {
    $unit = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    ($this->mkLa)('Sesampaste');
    $recipe = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 't5-mint', 'name' => 'T5-Rezept', 'status' => 'approved']);
    $roh = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'raw_text' => 'Sesampaste', 'quantity' => '100', 'unit_vocab_id' => $unit->id, 'position' => 1]);
    $ohne = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'raw_text' => 'Marsianische Nichtzutat', 'quantity' => '10', 'unit_vocab_id' => $unit->id, 'position' => 2]);

    $r = app(\Platform\FoodAlchemist\Services\RecipeOneShotService::class)->minteFehlendeGps($this->rootTeam, $recipe->fresh());

    expect($r['minted'])->toBe(1)
        ->and($r['ohne_la'])->toBe(1);
    expect($roh->fresh()->gp_id)->not->toBeNull()
        ->and($ohne->fresh()->gp_id)->toBeNull();                                     // kein LA → kein GP
    expect(FoodAlchemistGp::find($roh->fresh()->gp_id)->status->value)->toBe('tentative'); // Review-Queue
});

it('T5: Zutaten mit vorhandenem GP oder Sub-Rezept werden NICHT angefasst', function () {
    $unit = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    ($this->mkLa)('Sesampaste');
    $bestandGp = $this->makeGp($this->rootTeam, 'Butter: frisch');
    $sub = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 't5-sub', 'name' => 'Sub', 'status' => 'approved']);
    $recipe = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 't5-skip', 'name' => 'T5-Skip', 'status' => 'approved']);
    $mitGp = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'gp_id' => $bestandGp->id, 'raw_text' => 'Butter', 'quantity' => '20', 'unit_vocab_id' => $unit->id, 'position' => 1]);
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'referenced_recipe_id' => $sub->id, 'raw_text' => 'Sub', 'quantity' => '50', 'unit_vocab_id' => $unit->id, 'position' => 2]);

    $vorher = FoodAlchemistGp::count();
    $r = app(\Platform\FoodAlchemist\Services\RecipeOneShotService::class)->minteFehlendeGps($this->rootTeam, $recipe->fresh());

    expect($r['status'])->toBe('vollständig')
        ->and($r['minted'])->toBe(0);
    expect($mitGp->fresh()->gp_id)->toBe($bestandGp->id)
        ->and(FoodAlchemistGp::count())->toBe($vorher);   // nichts geminted
});
