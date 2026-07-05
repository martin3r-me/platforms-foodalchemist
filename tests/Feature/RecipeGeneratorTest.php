<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M4-14: Generator end-to-end — Bestand-Hybrid-Resolver (GP aus Bestand,
 * Stub für Halbfabrikat-Lücke, Hard-Stop für GP-Lücke), Anlage + Recompute.
 * $kiRezeptOverride = Test-Grenze (FakeProvider ist ein Kontext-Echo und kann
 * strukturell kein Rezept erfinden — dokumentiert in der Roadmap-Notiz).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->svc = app(RecipeGeneratorService::class);

    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
        ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1],
    ] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }

    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->mkGpMitPreis = function (string $name, ?string $slug, float $preis) use ($supplier) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['main_ingredient_slug' => $slug, 'status' => 'approved']);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };
});

it('DoD M4-14: 1 Rezept end-to-end — Bestand-GP + Sub-Stub für Halbfabrikat + Hard-Stop für Lücke', function () {
    ($this->mkGpMitPreis)('Schalotten: frisch, ganz', 'schalotten', 4.00);
    ($this->mkGpMitPreis)('Rotwein: trocken, Spätburgunder', 'rotwein', 6.00);

    $resultat = $this->svc->generiere($this->rootTeam, 'Dunkle Rotwein-Schalotten-Reduktion', [
        'convenience' => 'from_scratch', 'frische' => 'frisch',
    ], kiRezeptOverride: [
        'name' => 'Reduktion: Rotwein-Schalotte',
        'description' => 'Dunkle, sirupartige Reduktion als Saucenbasis.',
        'taste_direction' => 'herzhaft',
        'preparation' => '1. Schalotten anschwitzen. 2. Mit Rotwein abloeschen, reduzieren.',
        'zutaten' => [
            ['text' => 'Schalotten', 'slug' => 'schalotten', 'quantity' => 200, 'unit' => 'g'],
            ['text' => 'Rotwein', 'slug' => 'rotwein', 'quantity' => 500, 'unit' => 'ml'],
            ['text' => 'brauner Kalbsfond', 'quantity' => 250, 'unit' => 'ml'],   // Halbfabrikat-Lücke ⇒ Stub
            ['text' => 'Drachenfrucht-Essenz', 'quantity' => 10, 'unit' => 'ml'], // GP-Lücke ⇒ Hard-Stop
        ],
    ]);

    $recipe = $resultat['recipe'];
    expect($recipe->name)->toBe('Reduktion: Rotwein-Schalotte')
        ->and($recipe->status->value)->toBe('draft')
        ->and($recipe->last_modified_by)->toBe('generator')
        ->and($recipe->description_source)->toBe('ki')
        ->and($recipe->ingredients()->count())->toBe(4);

    // Statistik: 2 Bestand-GPs, 1 Stub neu, 1 offen
    expect($resultat['statistik'])->toBe(['bestand_gp' => 2, 'bestand_sub' => 0, 'stub_neu' => 1, 'offen' => 1]);

    // Stub existiert als Basisrezept (status stub, generator-markiert) — §4-Alias griff NICHT
    // (BRAUNER KALBSFOND existiert nicht), also Stub mit geputztem Namen
    $stub = FoodAlchemistRecipe::where('status', 'stub')->firstOrFail();
    expect($stub->last_modified_by)->toBe('generator_stub')
        ->and(mb_strtolower($stub->name))->toContain('kalbsfond');

    // Hard-Stop: Button-Heuristik sagt GP anlegen (keine Zubereitungs-Marker)
    expect($resultat['offene'][0]['text'])->toBe('Drachenfrucht-Essenz')
        ->and($resultat['offene'][0]['primaer'])->toBe('gp_anlegen');

    // Recompute lief: Yield aus 200 g + 500 ml (Stub + offen tragen 0 €/Daten bei)
    expect((float) $recipe->yield_kg)->toBeGreaterThan(0.9)
        ->and((float) $recipe->ek_total_eur)->toBeGreaterThan(0);

    // n_zutaten_ungemappt = 1 (Hard-Stop) ⇒ F7.1: Allergene unbekannt, Konfidenz low
    expect($recipe->n_ingredients_unmapped)->toBe(1)
        ->and($recipe->allergens_confidence)->toBe('low');
});

it('§4-Alias greift im Generator: Rinderbrühe nutzt den BESTAND statt einen Stub anzulegen', function () {
    $fond = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'heller_kalbsfond',
        'name' => 'HELLER KALBSFOND', 'status' => 'approved', 'ek_per_kg_eur' => 0.5,
    ]);

    $resultat = $this->svc->generiere($this->rootTeam, 'Helle Suppe', [], kiRezeptOverride: [
        'name' => 'Suppe: Hell',
        'zutaten' => [['text' => 'Rinderbrühe', 'slug' => 'rinderbruehe', 'quantity' => 1000, 'unit' => 'ml']],
    ]);

    expect($resultat['statistik']['bestand_sub'])->toBe(1)
        ->and($resultat['statistik']['stub_neu'])->toBe(0)
        ->and($resultat['recipe']->ingredients()->first()->referenced_recipe_id)->toBe($fond->id);
});

it('Fake-Provider ohne Override degradiert mit klarer Fehlermeldung (Echo kann kein Rezept erfinden)', function () {
    // Seit M7-03 fängt der Gateway-Structural-Retry (§3.3) das leere Echo
    // schon VOR dem Service-Guard — gleiche Semantik, frühere klare Meldung.
    expect(fn () => $this->svc->generiere($this->rootTeam, 'Irgendwas Feines'))
        ->toThrow(RuntimeException::class, 'strukturell unbrauchbar');
});
