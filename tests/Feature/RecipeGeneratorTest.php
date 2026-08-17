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
 * Bestands-Reuse und getrennte Auflösung offener Basisrezept-/GP-Zeilen), Anlage + Recompute.
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

it('DoD M4-14: Bestand wird gebunden, fehlende Basisrezepte und GPs bleiben zur Bestätigung offen', function () {
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
            ['text' => 'brauner Kalbsfond', 'quantity' => 250, 'unit' => 'ml'],   // Halbfabrikat-Lücke ⇒ offen
            ['text' => 'Drachenfrucht-Essenz', 'quantity' => 10, 'unit' => 'ml'], // GP-Lücke ⇒ Hard-Stop
        ],
    ]);

    $recipe = $resultat['recipe'];
    expect($recipe->name)->toBe('Reduktion: Rotwein-Schalotte')
        ->and($recipe->status->value)->toBe('draft')
        ->and($recipe->last_modified_by)->toBe('generator')
        ->and($recipe->description_source)->toBe('ki')
        ->and($recipe->ingredients()->count())->toBe(4);

    // Keine automatische Anlage während der Generierung: beide Lücken bleiben offen.
    // (Kohärenz-Gate 2026-08-07: statistik trägt jetzt zusätzlich kohaerenz + kritiker —
    // darum toMatchArray statt toBe.)
    expect($resultat['statistik'])->toMatchArray([
        'bestand_gp' => 2, 'bestand_sub' => 0, 'stub_neu' => 0,
        'stubs' => [], 'gp_neu_aus_la' => 0, 'offen' => 2,
    ]);
    // Dieses Rezept hat KEINE verdrahteten Sub-Rezepte (Kalbsfond = offene Lücke) → der
    // Kritiker-Call wird gegatet übersprungen, die Regel findet nichts, nichts wird entdrahtet.
    expect($resultat['statistik']['kritiker'])->toMatchArray([
        'geprueft' => false, 'uebersprungen_gating' => true, 'entdrahtet' => 0,
    ]);

    expect(FoodAlchemistRecipe::where('status', 'stub')->count())->toBe(0)
        ->and($resultat['offene'][0]['text'])->toBe('brauner Kalbsfond')
        ->and($resultat['offene'][0]['primaer'])->toBe('basisrezept_anlegen')
        ->and($resultat['offene'][1]['text'])->toBe('Drachenfrucht-Essenz')
        ->and($resultat['offene'][1]['primaer'])->toBe('lieferantenartikel_waehlen');

    // Recompute lief: offene Zeilen tragen Menge, aber noch keine EK-/Stammdaten.
    expect((float) $recipe->yield_kg)->toBeGreaterThan(0.9)
        ->and((float) $recipe->ek_total_eur)->toBeGreaterThan(0);

    expect($recipe->n_ingredients_unmapped)->toBe(2)
        ->and($recipe->allergens_confidence)->toBe('low');
});

it('VK-Generator nutzt vorhandene Grundprodukte fuer Direktartikel wie Fleur de Sel zuerst', function () {
    $fleur = ($this->mkGpMitPreis)('Fleur de Sel: trocken', 'fleur_de_sel', 18.00);

    $resultat = $this->svc->generiere($this->rootTeam, 'Gericht mit Finish-Salz', [
        'convenience' => 'from_scratch', 'frische' => 'frisch',
    ], kiRezeptOverride: [
        'name' => 'Gericht: Tomate mit Salz',
        'zutaten' => [
            ['text' => 'Fleur de Sel', 'slug' => 'fleur_de_sel', 'quantity' => 2, 'unit' => 'g'],
        ],
    ], vkModus: true);

    expect($resultat['recipe']->is_sales_recipe)->toBeTrue()
        ->and($resultat['statistik']['bestand_gp'])->toBe(1)
        ->and($resultat['offene'])->toBe([])
        ->and($resultat['recipe']->ingredients()->first()->gp_id)->toBe($fleur->id);
});

it('VK-Generator routet fehlende Direktartikel nach LA→GP, Subrezept-Komponenten aber als Basisrezept', function () {
    $resultat = $this->svc->generiere($this->rootTeam, 'Suppe mit Finish und Fond', [
        'convenience' => 'from_scratch', 'frische' => 'frisch',
    ], kiRezeptOverride: [
        'name' => 'Gericht: Suppe mit Fleur de Sel',
        'zutaten' => [
            ['text' => 'Geflügelfond', 'quantity' => 500, 'unit' => 'ml'],
            ['text' => 'Fleur de Sel', 'quantity' => 2, 'unit' => 'g'],
        ],
    ], vkModus: true);

    expect($resultat['statistik']['offen'])->toBe(2)
        ->and($resultat['offene'][0]['text'])->toBe('Geflügelfond')
        ->and($resultat['offene'][0]['primaer'])->toBe('basisrezept_anlegen')
        ->and($resultat['offene'][1]['text'])->toBe('Fleur de Sel')
        ->and($resultat['offene'][1]['primaer'])->toBe('lieferantenartikel_waehlen');
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

it('Band-Gate: FuzzyLow-Halbtreffer wird NICHT verdrahtet — Balsamico-Reduktion landet offen statt am Rahmeis', function () {
    // Rahmeis-in-Tomatensuppe (2026-08-06): 1 von 2 Query-Tokens matcht („balsamico"),
    // Kopf-Nomen „Rahmeis" fehlt in der Query → Score exakt 0.50 = FuzzyLow =
    // laut GL-04 §4.1 „Review nötig". Vorher wurde das Dessert still als
    // Sub-Rezept in die Suppe verdrahtet; jetzt bleibt die Zeile offen.
    $rahmeis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rahmeis_balsamico',
        'name' => 'Rahmeis: Balsamico', 'status' => 'approved', 'ek_per_kg_eur' => 3.0,
    ]);

    $resultat = $this->svc->generiere($this->rootTeam, 'Tomatensuppe mit Basilikum', [], kiRezeptOverride: [
        'name' => 'Suppe: Tomate-Basilikum',
        'zutaten' => [['text' => 'Balsamico-Reduktion', 'quantity' => 30, 'unit' => 'ml']],
    ]);

    expect($resultat['statistik']['bestand_sub'])->toBe(0)
        ->and($resultat['statistik']['offen'])->toBe(1)
        ->and($resultat['offene'][0]['text'])->toBe('Balsamico-Reduktion')
        // Transparenz: der abgewiesene Kandidat bleibt für die Review-Fläche sichtbar.
        ->and($resultat['offene'][0]['schwacher_treffer']['target'])->toBe('sub_recipe')
        ->and($resultat['offene'][0]['schwacher_treffer']['name'])->toBe('Rahmeis: Balsamico')
        ->and($resultat['offene'][0]['schwacher_treffer']['score'])->toBeLessThan(0.70);

    $zeile = $resultat['recipe']->ingredients()->first();
    expect($zeile->referenced_recipe_id)->toBeNull()
        ->and($zeile->gp_id)->toBeNull()
        ->and($zeile->match_method->value)->toBe('unmatched');
});

it('Band-Gate blockt nur Schwaches: exakte Benennung verdrahtet das Sub-Rezept weiterhin', function () {
    $rahmeis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rahmeis_balsamico',
        'name' => 'Rahmeis: Balsamico', 'status' => 'approved', 'ek_per_kg_eur' => 3.0,
    ]);

    $resultat = $this->svc->generiere($this->rootTeam, 'Dessert-Teller', [], kiRezeptOverride: [
        'name' => 'Dessert: Balsamico-Erdbeeren',
        'zutaten' => [['text' => 'Rahmeis: Balsamico', 'quantity' => 80, 'unit' => 'g']],
    ]);

    expect($resultat['statistik']['bestand_sub'])->toBe(1)
        ->and($resultat['statistik']['offen'])->toBe(0)
        ->and($resultat['recipe']->ingredients()->first()->referenced_recipe_id)->toBe($rahmeis->id);
});

/**
 * »Jus ist die Sauce« (2026-08-17): das LLM-Flag `sub_rezept` steuert die Entscheidung, ABER ein
 * STARKES Name-Halbfabrikat (jus/sud/sauce/fond/reduktion/… — queryIstHalbfabrikat) ÜBERSTIMMT jetzt
 * ein KI-»flach« (diese Marker sind im From-Scratch-Kontext immer gemacht). Die breitere
 * Button-Heuristik (creme/mousse/geschmort) bleibt NICHT-überstimmend — dort hält ein KI-»flach«.
 *   - Drachenfrucht-Essenz: `sub_rezept:true` → Sub (heuristik-blind, Flag=true gewinnt).
 *   - brauner Kalbsfond: `sub_rezept:false`, ABER fond = hartes Halbfabrikat → Sub (überstimmt Flag).
 *   - geschmorte Ochsenbacke: `sub_rezept:false`, geschmort nur Button-Heuristik → bleibt Ware (LA).
 */
it('LLM-Flag: true erzwingt Sub; ein hartes Halbfabrikat (fond/jus) überstimmt ein KI-flach, Button-Heuristik nicht', function () {
    $resultat = $this->svc->generiere($this->rootTeam, 'Fond-und-Essenz-Teller', [
        'convenience' => 'from_scratch', 'frische' => 'frisch',
    ], kiRezeptOverride: [
        'name' => 'Teller: Fond & Essenz',
        'zutaten' => [
            ['text' => 'Drachenfrucht-Essenz', 'quantity' => 10, 'unit' => 'ml', 'sub_rezept' => true],
            ['text' => 'brauner Kalbsfond', 'quantity' => 250, 'unit' => 'ml', 'sub_rezept' => false],
            ['text' => 'geschmorte Ochsenbacke', 'quantity' => 180, 'unit' => 'g', 'sub_rezept' => false],
        ],
    ]);

    expect($resultat['offene'])->toHaveCount(3)
        ->and($resultat['offene'][0]['text'])->toBe('Drachenfrucht-Essenz')
        ->and($resultat['offene'][0]['primaer'])->toBe('basisrezept_anlegen')       // Flag=true → Sub
        ->and($resultat['offene'][0]['la_kandidaten'])->toBe([])                     // Sub-Pfad → keine LA-Suche
        ->and($resultat['offene'][1]['text'])->toBe('brauner Kalbsfond')
        ->and($resultat['offene'][1]['primaer'])->toBe('basisrezept_anlegen')       // fond = hart → überstimmt Flag=false
        ->and($resultat['offene'][2]['text'])->toBe('geschmorte Ochsenbacke')
        ->and($resultat['offene'][2]['primaer'])->toBe('lieferantenartikel_waehlen'); // geschmort (Button) überstimmt Flag=false NICHT
});

// T4 »Gericht = Basisrezepte« rolle-hart: im VK-Gericht wird komponente/beilage IMMER Sub,
// echte Rohware (direktArtikel) bleibt LA. Im Basisrezept greift die Regel nicht (kein Rekurs).
it('T4: im Gericht (vkModus) wird komponente/beilage IMMER Basisrezept, Rohware bleibt LA', function () {
    $resultat = $this->svc->generiere($this->rootTeam, 'Rolle-Teller', [
        'convenience' => 'from_scratch', 'frische' => 'frisch',
    ], kiRezeptOverride: [
        'name' => 'Teller: Rolle-hart',
        'zutaten' => [
            ['text' => 'Kartoffelterrine', 'quantity' => 120, 'unit' => 'g', 'role' => 'beilage', 'sub_rezept' => false], // kein Marker + KI-flach → trotzdem Sub (Rolle)
            ['text' => 'Aprikosenragout', 'quantity' => 60, 'unit' => 'g', 'role' => 'komponente'],                       // kein Marker/Flag → Sub (Rolle)
            ['text' => 'Fleur de Sel', 'quantity' => 2, 'unit' => 'g', 'role' => 'garnitur'],                             // Rohware (direktArtikel) → LA
        ],
    ], vkModus: true);

    expect($resultat['offene'][0]['primaer'])->toBe('basisrezept_anlegen')
        ->and($resultat['offene'][1]['primaer'])->toBe('basisrezept_anlegen')
        ->and($resultat['offene'][2]['primaer'])->toBe('lieferantenartikel_waehlen');
});

it('T4: im Basisrezept (kein vkModus) greift die Rolle-Regel NICHT (komponente = rohes Bauteil)', function () {
    $resultat = $this->svc->generiere($this->rootTeam, 'Basis-Rolle', [
        'convenience' => 'from_scratch',
    ], kiRezeptOverride: [
        'name' => 'Basis: Rolle-egal',
        'zutaten' => [
            ['text' => 'Kartoffelterrine', 'quantity' => 120, 'unit' => 'g', 'role' => 'beilage', 'sub_rezept' => false],
        ],
    ], vkModus: false);

    expect($resultat['offene'][0]['primaer'])->toBe('lieferantenartikel_waehlen');
});

it('Fehlt das sub_rezept-Flag, bleibt die Namens-Heuristik der Fallback (kein stiller bool-Cast)', function () {
    $resultat = $this->svc->generiere($this->rootTeam, 'Fond-und-Essenz-Teller', [
        'convenience' => 'from_scratch', 'frische' => 'frisch',
    ], kiRezeptOverride: [
        'name' => 'Teller: Fond & Essenz ohne Flag',
        'zutaten' => [
            ['text' => 'brauner Kalbsfond', 'quantity' => 250, 'unit' => 'ml'],   // kein Flag → Heuristik: Sub
            ['text' => 'Drachenfrucht-Essenz', 'quantity' => 10, 'unit' => 'ml'], // kein Flag → Heuristik: Ware
        ],
    ]);

    expect($resultat['offene'][0]['primaer'])->toBe('basisrezept_anlegen')
        ->and($resultat['offene'][1]['primaer'])->toBe('lieferantenartikel_waehlen');
});

// ── L2 — Zerlegungs-Vorrang über Convenience (Entscheid 2026-08-17) ──────────────────────────
// Kernfrage: darf ein bestehender GP-Treffer eine komponente/beilage-Zeile FLACH machen, oder
// wird sie zum Basisrezept zerlegt? Die Convenience-Achse entscheidet.

it('L2 from_scratch: komponente mit GP-Treffer wird TROTZDEM zerlegt (GP geblockt, basisrezept_anlegen)', function () {
    ($this->mkGpMitPreis)('Rotkohl', 'rotkohl', 3.00);

    $resultat = $this->svc->generiere($this->rootTeam, 'Teller mit Püree', [
        'convenience' => 'from_scratch', 'frische' => 'frisch',
    ], kiRezeptOverride: [
        'name' => 'Gericht: Püree-Teller',
        'zutaten' => [
            ['text' => 'Rotkohl', 'slug' => 'rotkohl', 'quantity' => 200, 'unit' => 'g', 'role' => 'komponente'],
        ],
    ], vkModus: true);

    expect($resultat['statistik']['bestand_gp'])->toBe(0)          // GP NICHT verdrahtet
        ->and($resultat['statistik']['offen'])->toBe(1)
        ->and($resultat['offene'][0]['primaer'])->toBe('basisrezept_anlegen');
    expect($resultat['recipe']->ingredients()->first()->gp_id)->toBeNull();
});

it('L2 voll_convenience: komponente mit GP-Treffer bleibt FLACH (GP gewinnt, Fertigkomponente)', function () {
    ($this->mkGpMitPreis)('Rotkohl', 'rotkohl', 3.00);

    $resultat = $this->svc->generiere($this->rootTeam, 'Teller mit Püree', [
        'convenience' => 'voll_convenience', 'frische' => 'frisch',
    ], kiRezeptOverride: [
        'name' => 'Gericht: Püree-Teller VC',
        'zutaten' => [
            ['text' => 'Rotkohl', 'slug' => 'rotkohl', 'quantity' => 200, 'unit' => 'g', 'role' => 'komponente'],
        ],
    ], vkModus: true);

    expect($resultat['statistik']['bestand_gp'])->toBe(1)          // GP verdrahtet (gekauft)
        ->and($resultat['statistik']['offen'])->toBe(0);
    expect($resultat['recipe']->ingredients()->first()->gp_id)->not->toBeNull();
});

it('L2 egal (kein convenience-Key): komponente mit GP-Treffer bleibt FLACH (Bestand zuerst)', function () {
    ($this->mkGpMitPreis)('Rotkohl', 'rotkohl', 3.00);

    $resultat = $this->svc->generiere($this->rootTeam, 'Teller mit Püree', [
        'frische' => 'frisch',   // KEIN convenience → default/egal
    ], kiRezeptOverride: [
        'name' => 'Gericht: Püree-Teller egal',
        'zutaten' => [
            ['text' => 'Rotkohl', 'slug' => 'rotkohl', 'quantity' => 200, 'unit' => 'g', 'role' => 'komponente'],
        ],
    ], vkModus: true);

    expect($resultat['statistik']['bestand_gp'])->toBe(1)          // Bestand zuerst → GP gewinnt
        ->and($resultat['statistik']['offen'])->toBe(0);
});

it('L2 teil_convenience: Convenience-GP gewinnt, ein Nicht-Convenience-GP wird zerlegt', function () {
    $conv = ($this->mkGpMitPreis)('Rotkohl', 'rotkohl', 3.00);
    $conv->update(['tag_is_convenience' => true]);
    ($this->mkGpMitPreis)('Rahmspinat', 'rahmspinat', 2.50);   // kein Convenience-Tag

    $resultat = $this->svc->generiere($this->rootTeam, 'Teller mit Beilagen', [
        'convenience' => 'teil_convenience', 'frische' => 'frisch',
    ], kiRezeptOverride: [
        'name' => 'Gericht: Beilagen-Teller',
        'zutaten' => [
            ['text' => 'Rotkohl', 'slug' => 'rotkohl', 'quantity' => 200, 'unit' => 'g', 'role' => 'beilage'],
            ['text' => 'Rahmspinat', 'slug' => 'rahmspinat', 'quantity' => 150, 'unit' => 'g', 'role' => 'beilage'],
        ],
    ], vkModus: true);

    // Convenience-GP (Püree) verdrahtet, Nicht-Convenience-GP (Rahmspinat) zerlegt.
    expect($resultat['statistik']['bestand_gp'])->toBe(1)
        ->and($resultat['statistik']['offen'])->toBe(1);
    $offeneTexte = array_column($resultat['offene'], 'text');
    expect($offeneTexte)->toContain('Rahmspinat')
        ->and($offeneTexte)->not->toContain('Rotkohl');
});
