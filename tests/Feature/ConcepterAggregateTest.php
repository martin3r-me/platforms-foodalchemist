<?php

use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->pakete = app(PaketService::class);
    $this->concepts = app(ConceptService::class);
    $this->agg = app(ConcepterAggregateService::class);

    // A: vollständig (Portion 250 g, high), vegan. B: vollständig (200 g, medium),
    // enthält Schwein, Allergen-Konfidenz low. C: KEINE Portionsgramm → unvollständig.
    $mk = fn (array $attr) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $this->rootTeam->id, 'status' => 'approved', 'ist_verkaufsrezept' => true,
    ], $attr));

    $this->a = $mk([
        'recipe_key' => 'a', 'name' => 'Green Power', 'vk_netto' => 2.00, 'ek_total_eur' => 0.60,
        'arbeitszeit_min' => 15, 'vk_menge_pro_einheit_g' => 250,
        'nutri_kcal_per_100g' => 200, 'nutri_protein_g_per_100g' => 10, 'nutri_fat_g_per_100g' => 5,
        'nutri_carbs_g_per_100g' => 20, 'nutri_salt_g_per_100g' => 1, 'nutri_konfidenz' => 'high',
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'allergene_konfidenz' => 'high',
    ]);
    $this->b = $mk([
        'recipe_key' => 'b', 'name' => 'Pulled Pork', 'vk_netto' => 3.00, 'ek_total_eur' => 0.90,
        'arbeitszeit_min' => 10, 'vk_menge_pro_einheit_g' => 200,
        'nutri_kcal_per_100g' => 150, 'nutri_protein_g_per_100g' => 8, 'nutri_konfidenz' => 'medium',
        'spec_is_vegan' => false, 'spec_is_vegetarian' => false, 'spec_contains_pork' => true,
        'allergene_konfidenz' => 'low',
    ]);
    $this->c = $mk([
        'recipe_key' => 'c', 'name' => 'Mystery Dish', 'vk_netto' => 5.50, 'ek_total_eur' => 1.50,
        'arbeitszeit_min' => 20, 'vk_menge_pro_einheit_g' => null,        // KEINE Portionsgramm
        'nutri_kcal_per_100g' => 999, 'nutri_konfidenz' => 'high',        // hat Daten, aber unbrauchbar ohne g
        'spec_is_vegetarian' => true, 'allergene_konfidenz' => 'medium',
    ]);
});

it('M10R-1: alle additiven Spalten + neuen Tabellen existieren', function () {
    expect(Schema::hasTable('foodalchemist_vocab_klassen'))->toBeTrue()
        ->and(Schema::hasTable('foodalchemist_concept_sektor_eignung'))->toBeTrue()
        ->and(Schema::hasColumn('foodalchemist_concepts', 'klasse'))->toBeTrue()
        ->and(Schema::hasColumn('foodalchemist_concepts', 'schreibstil_id'))->toBeTrue()
        ->and(Schema::hasColumn('foodalchemist_concepts', 'brief'))->toBeTrue()
        ->and(Schema::hasColumn('foodalchemist_concepts', 'naehrwerte_cache'))->toBeTrue()
        ->and(Schema::hasColumn('foodalchemist_concepts', 'arbeitszeit_min_cache'))->toBeTrue()
        ->and(Schema::hasColumn('foodalchemist_pakete', 'klasse'))->toBeTrue()
        ->and(Schema::hasColumn('foodalchemist_pakete', 'naehrwerte_cache'))->toBeTrue()
        ->and(Schema::hasColumn('foodalchemist_foodbooks', 'schreibstil_id'))->toBeTrue();
});

it('Paket-Aggregat: Nährwerte/Person aus Portionsgramm, Konfidenz schwächstes Glied', function () {
    $p = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->pakete->syncGerichte($this->rootTeam, $p->id, [
        ['vk_recipe_id' => $this->a->id], ['vk_recipe_id' => $this->b->id],
    ]);

    $agg = $this->agg->paketAggregat($p->refresh());

    // A: 200 kcal × 2.5 = 500; B: 150 × 2.0 = 300 → 800
    expect($agg['naehrwerte']['kcal'])->toBe(800.0)
        ->and($agg['naehrwerte']['protein_g'])->toBe(41.0)            // 10×2.5 + 8×2.0 = 25 + 16
        ->and($agg['naehrwerte']['n_mit_naehrwerten'])->toBe(2)
        ->and($agg['naehrwerte']['vollstaendig'])->toBeTrue()
        ->and($agg['naehrwerte']['konfidenz'])->toBe('medium')        // min(high, medium)
        ->and($agg['arbeitszeit_min'])->toBe(25)                      // 15 + 10
        ->and((float) $agg['ek_pro_person'])->toBe(1.5)               // 0.60 + 0.90
        ->and($agg['allergene']['contains_pork'])->toBeTrue()
        ->and($agg['allergene']['is_vegan'])->toBeFalse();
});

it('Concept-Aggregat: festes Gericht ohne Portionsgramm degradiert ehrlich (Konfidenz, vollstaendig)', function () {
    $p = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->pakete->syncGerichte($this->rootTeam, $p->id, [
        ['vk_recipe_id' => $this->a->id], ['vk_recipe_id' => $this->b->id],
    ]);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot1 = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot1->id, ['paket_id' => $p->id]);
    $slot2 = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $slot2->id, ['vk_recipe_id' => $this->c->id, 'menge' => 2]);

    $agg = $this->agg->conceptAggregat($c->refresh());

    expect($agg['n_slots'])->toBe(2)
        ->and($agg['n_gerichte'])->toBe(3)                            // A, B, C (distinkt)
        ->and($agg['naehrwerte']['kcal'])->toBe(800.0)                // C trägt nichts bei (keine g)
        ->and($agg['naehrwerte']['n_gerichte'])->toBe(3)              // betrachtet
        ->and($agg['naehrwerte']['n_mit_naehrwerten'])->toBe(2)       // nur A+B brauchbar
        ->and($agg['naehrwerte']['vollstaendig'])->toBeFalse()
        ->and($agg['naehrwerte']['konfidenz'])->toBe('low')           // Lücke deckelt auf „low"
        ->and($agg['arbeitszeit_min'])->toBe(45)                      // 15 + 10 + 20 (ohne Mengen-Faktor)
        ->and((float) $agg['ek_pro_person'])->toBe(4.5)               // 0.60 + 0.90 + 1.50×2
        ->and($agg['allergene']['contains_pork'])->toBeTrue()
        ->and($agg['allergene']['konfidenz'])->toBe('low');           // B = schwächstes Glied
});

it('fillSlot persistiert die Aggregat-Caches am Concept (refreshCache-Hook)', function () {
    $p = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->pakete->syncGerichte($this->rootTeam, $p->id, [['vk_recipe_id' => $this->a->id]]);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Mini']);
    $slot = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $p->id]);

    $c->refresh();
    expect($c->arbeitszeit_min_cache)->toBe(15)
        ->and((float) $c->ek_pro_person_cache)->toBe(0.6)
        ->and($c->naehrwerte_cache)->toBeArray()
        ->and($c->naehrwerte_cache['kcal'])->toEqual(500);            // 200 × 2.5 (JSON normalisiert .0)
});

it('syncGerichte persistiert den Paket-Nährwert-Cache', function () {
    $p = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->pakete->syncGerichte($this->rootTeam, $p->id, [['vk_recipe_id' => $this->a->id]]);

    $p->refresh();
    expect($p->arbeitszeit_min_cache)->toBe(15)
        ->and($p->naehrwerte_cache)->toBeArray()
        ->and($p->naehrwerte_cache['kcal'])->toEqual(500);           // JSON normalisiert .0
});

// ── Mengen-Modell (Dominique-Entscheid 2026-06-15: einheit-abhängig) ─────────

it('EK teilt durch die Portionszahl (Batch→Portion), nicht ek_total roh', function () {
    // ek_total_eur 30 € = Batch für 10 Portionen → 3 €/Portion. Vor dem Fix: 30 € (Batch roh).
    $batch = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'status' => 'approved', 'ist_verkaufsrezept' => true,
        'recipe_key' => 'batch', 'name' => 'Gulasch (Topf)', 'vk_netto' => 5.00,
        'ek_total_eur' => 30.00, 'vk_anzahl_einheiten' => 10,
    ]);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Batch-Test']);
    $slot = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['vk_recipe_id' => $batch->id, 'menge' => 2]);

    $agg = $this->agg->conceptAggregat($c->refresh());

    expect((float) $agg['ek_pro_person'])->toBe(6.0)          // 30 ÷ 10 × 2 (nicht 60)
        ->and((float) $agg['vk_summe'])->toBe(10.0)           // 5 × 2 (vk_netto bereits pro Portion)
        ->and($agg['ek_n_positionen'])->toBe(1)
        ->and($agg['ek_n_beitragend'])->toBe(1);

    // Cockpit (ConceptService) MUSS dieselbe Zahl liefern — eine Helfer-Stelle.
    $cockpit = $this->concepts->preisCockpit($c->refresh());
    expect((float) $cockpit['ek_pro_person'])->toBe(6.0)
        ->and($cockpit['hat_ek_luecke'])->toBeFalse();
});

it('Gramm-Einheit rechnet anteilig am Portionsgewicht', function () {
    $gramm = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm',
        'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    // Portion 250 g, EK 4 €/Portion (anzahl 1). 125 g = halbe Portion → 2 € EK, 1 € VK.
    $beilage = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'status' => 'approved', 'ist_verkaufsrezept' => true,
        'recipe_key' => 'beilage', 'name' => 'Reis', 'vk_netto' => 2.00,
        'ek_total_eur' => 4.00, 'vk_anzahl_einheiten' => 1, 'vk_menge_pro_einheit_g' => 250,
    ]);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Gramm-Test']);
    $slot = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Beilage']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['vk_recipe_id' => $beilage->id]);
    $this->concepts->setSlotMengeEinheit($this->rootTeam, $slot->id, 125, $gramm->id);

    $agg = $this->agg->conceptAggregat($c->refresh());

    expect((float) $agg['ek_pro_person'])->toBe(2.0)          // 4 × (125 ÷ 250)
        ->and((float) $agg['vk_summe'])->toBe(1.0)            // 2 × 0.5
        ->and($agg['ek_n_beitragend'])->toBe(1);
});

it('Gramm-Einheit ohne Portionsgewicht trägt ehrlich nicht bei', function () {
    $gramm = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm',
        'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $ohneG = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'status' => 'approved', 'ist_verkaufsrezept' => true,
        'recipe_key' => 'noweight', 'name' => 'Mystery', 'vk_netto' => 9.00,
        'ek_total_eur' => 4.00, 'vk_anzahl_einheiten' => 1, 'vk_menge_pro_einheit_g' => null,
    ]);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Gramm-Lücke']);
    // 1 belastbare Portion + 1 Gramm-Position ohne Portionsgewicht.
    $s1 = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $s1->id, ['vk_recipe_id' => $this->a->id, 'menge' => 1]); // a: ek 0.60
    $s2 = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Beilage']);
    $this->concepts->fillSlot($this->rootTeam, $s2->id, ['vk_recipe_id' => $ohneG->id]);
    $this->concepts->setSlotMengeEinheit($this->rootTeam, $s2->id, 100, $gramm->id);

    $agg = $this->agg->conceptAggregat($c->refresh());

    expect((float) $agg['ek_pro_person'])->toBe(0.6)          // nur a trägt bei, Mystery fällt ehrlich raus
        ->and($agg['ek_n_positionen'])->toBe(2)
        ->and($agg['ek_n_beitragend'])->toBe(1);

    $cockpit = $this->concepts->preisCockpit($c->refresh());
    expect($cockpit['hat_ek_luecke'])->toBeTrue();            // UI kann ehrlich warnen
});

it('Gewicht/Person: Σ Effektiv-Gramm; Position ohne Portionsgewicht → unvollständig', function () {
    // a: 250 g/Portion, b: 200 g/Portion (beide vollständig)
    $c = $this->concepts->create($this->rootTeam, ['name' => 'Gewicht-voll']);
    $s1 = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $s1->id, ['vk_recipe_id' => $this->a->id, 'menge' => 1]); // 1 × 250 g
    $s2 = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $s2->id, ['vk_recipe_id' => $this->b->id, 'menge' => 2]); // 2 × 200 g

    $agg = $this->agg->conceptAggregat($c->refresh());
    expect((float) $agg['gewicht_pro_person_g'])->toBe(650.0)
        ->and($agg['gewicht_vollstaendig'])->toBeTrue();

    // + c ohne Portionsgewicht → Gewicht unvollständig (c trägt nichts bei)
    $c2 = $this->concepts->create($this->rootTeam, ['name' => 'Gewicht-Lücke']);
    $x1 = $this->concepts->addSlot($this->rootTeam, $c2->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $x1->id, ['vk_recipe_id' => $this->a->id, 'menge' => 1]); // 250 g
    $x2 = $this->concepts->addSlot($this->rootTeam, $c2->id, ['rolle' => 'Extra']);
    $this->concepts->fillSlot($this->rootTeam, $x2->id, ['vk_recipe_id' => $this->c->id, 'menge' => 1]); // c: kein Portionsgewicht

    $agg2 = $this->agg->conceptAggregat($c2->refresh());
    expect((float) $agg2['gewicht_pro_person_g'])->toBe(250.0)
        ->and($agg2['gewicht_vollstaendig'])->toBeFalse();
});

it('Stück-Modus (kg↔Stück): EK/Stück = ek_total÷ertrag, Gewicht/Stück = yield_g÷ertrag', function () {
    $stk = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count',
    ]);
    // 20 kg Batch ergibt 50 Törtchen, EK gesamt 20 € → 1 Stück = 400 g, EK/Stück = 0,40 €.
    $toertchen = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'status' => 'approved', 'ist_verkaufsrezept' => false,
        'recipe_key' => 'toertchen', 'name' => 'Törtchen-Teig',
        'ek_total_eur' => 20.00, 'yield_kg' => 20, 'ertrag_stueck' => 50,
    ]);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Stück-Test']);
    $slot = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Petit Four']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['vk_recipe_id' => $toertchen->id, 'type' => 'basisrezept', 'menge' => 2]);
    $this->concepts->setSlotMengeEinheit($this->rootTeam, $slot->id, 2, $stk->id);

    $agg = $this->agg->conceptAggregat($c->refresh());
    expect((float) $agg['ek_pro_person'])->toBe(0.8)            // 20/50 × 2
        ->and((float) $agg['gewicht_pro_person_g'])->toBe(800.0) // (20000/50) × 2
        ->and($agg['gewicht_vollstaendig'])->toBeTrue();

    $cockpit = $this->concepts->preisCockpit($c->refresh());
    $zeile = collect($cockpit['zeilen'])->firstWhere('slot_id', $slot->id);
    expect((float) $zeile['ek'])->toBe(0.8);                     // Cockpit identisch (kanonische Stelle)
});
