<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\EtikettService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Verkaufstexte ohne Marken-Klammer (Kurator 26.10.): Kundensicht (Rezept-Dokument/Reports mit intern=false, Kaskaden-Anhänge, Speiseplan-Komponenten,
 * Etikett) zeigt den GP-Namen ohne „/ (Marke)“; intern bleibt der volle Name. Bio/Vegan/Glutenfrei/Laktosefrei bleiben; Allergen- und Zutatenangabe
 * auf dem Etikett unverändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->g = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'], ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->gp = $this->makeGp($this->rootTeam, 'Sahnesauce: konserviert / (Altoni) / (Bio)');
    $this->gp->update(['zusatz' => 'Altoni', 'bio' => 'bio']);
    $this->rezept = app(RecipeService::class)->create($this->rootTeam, ['name' => 'Gericht: Schnitzel Rahm']);
    app(RecipeService::class)->syncIngredients($this->rootTeam, $this->rezept->id, [[
        'id' => null, 'gp_id' => $this->gp->id, 'raw_text' => '200 g', 'quantity' => '200', 'unit_vocab_id' => $this->g->id]]);
    $this->report = app(ReportExportService::class);
});

it('Rezept-Dokument: Kundensicht ohne Marke (Bio bleibt), interne Sicht mit vollem Namen', function () {
    $kunde = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id, array_merge($this->report->optionen([], 'recipe'), ['intern' => false, 'zutaten' => true]));
    $intern = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id, array_merge($this->report->optionen([], 'recipe'), ['intern' => true, 'zutaten' => true]));

    expect($kunde['recipe']['ingredients'][0]['name'])->toBe('Sahnesauce: konserviert / (Bio)')
        ->and($intern['recipe']['ingredients'][0]['name'])->toBe('Sahnesauce: konserviert / (Altoni) / (Bio)');
});

it('Etikett: Name ohne Marke (auch ohne Doppelpunkt), Allergen- und Zutatenangabe unverändert', function () {
    $ketchup = $this->makeGp($this->rootTeam, 'Ketchup / (Heinz)');
    $ketchup->update(['zusatz' => 'Heinz']);
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Großhandel']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Heinz Tomato Ketchup',
        'article_number' => 'K1', 'ingredients_supplier' => 'Tomaten, Branntweinessig, Zucker, Salz, SELLERIE']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $ketchup->id]);
    $ketchup->update(['lead_la_supplier_item_id' => $la->id]);
    $etikett = app(EtikettService::class);

    $d = $etikett->daten($this->rootTeam, 'gp', $ketchup->id);
    expect($d['bezeichnung'])->toBe('Ketchup')
        ->and($d['zutaten'])->toBe([['name' => 'Tomaten, Branntweinessig, Zucker, Salz, SELLERIE', 'allergen' => false]]);   // Zutatenangabe wörtlich vom Artikel

    $liste = $etikett->zutatenliste($this->rootTeam, $this->rezept->fresh(['ingredients.gp', 'ingredients.referencedRecipe']));
    expect(collect($liste)->pluck('name')->all())->toBe(['Sahnesauce'])
        ->and(array_key_exists('allergen', $liste[0]))->toBeTrue();
});
