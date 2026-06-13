<?php

use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
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
