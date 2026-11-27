<?php

use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · Grammatur „immer automatisch“, Schritt 2 → 98 · 3.5-6a/6b (Dominique 27.11.: Darreichung = Portion, fest 1): Eine
 * Standard-Darreichung „wie Rezept“ (menge_vom_standard) ist der ganze Ansatz. Die Portionszahl am Rezept (sales_unit_count)
 * teilte früher; sie zählt seit 6a/6b nicht mehr und entfällt mit 6c. Bratkartoffeln: 1 kg Kartoffeln (1,20 €/kg) + 200 g Speck (9 €/kg) = 1,2 kg, EK 3,00 €.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $t = $this->rootTeam->id;
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $ak = FoodAlchemistMarkupClass::create(['code' => 'ALC', 'label' => 'A la Carte', 'class_factor_pct' => 100,
        'vat_profile_key' => 'regulaer', 'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Großhandel']);
    $gp = function (string $name, float $eurJeKg) use ($t, $lieferant) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $lieferant->id, 'designation' => $name.' 1kg',
            'article_number' => 'A-'.$gp->id, 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'price' => $eurJeKg, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };
    $this->r = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'bratkartoffeln', 'name' => 'Bratkartoffeln',
        'status' => 'approved', 'is_sales_recipe' => true]);
    foreach ([[$gp('Kartoffeln', 1.20), 1000], [$gp('Speck', 9.00), 200]] as $i => [$p, $menge]) {
        $this->r->ingredients()->create(['team_id' => $t, 'position' => $i, 'gp_id' => $p->id, 'raw_text' => $p->name,
            'quantity' => $menge, 'unit_vocab_id' => $g->id, 'match_method' => 'manual']);
    }
    $form = fn (string $code) => FoodAlchemistServierform::firstOrCreate(['team_id' => $t, 'code' => $code], ['label' => ucfirst($code)])->id;
    $basis = ['team_id' => $t, 'recipe_id' => $this->r->id, 'unit_count' => 1, 'price_mode' => 'auto', 'markup_class_id' => $ak->id];
    $this->std = FoodAlchemistRecipeDarreichung::create($basis + ['serving_form_id' => $form('teller'), 'is_standard' => true, 'menge_vom_standard' => true]);
    $this->wie = FoodAlchemistRecipeDarreichung::create($basis + ['serving_form_id' => $form('buffet'), 'is_standard' => false, 'menge_vom_standard' => true]);
    app(RecipeRecomputeService::class)->recomputePipeline($this->r->id);
    $this->r->refresh();
});

it('Standard „wie Rezept“: Grammatur und EK je Portion = ganzer Ansatz', function () {
    $dar = app(DarreichungService::class);
    foreach ([$this->std, $this->wie] as $d) {
        $d->refresh();
        expect(round($dar->grammJeEinheit($d, $this->r), 1))->toBe(1200.0)
            ->and((float) $d->quantity_per_unit_g)->toBe(1200.0)
            ->and(round((float) $d->ek_portion, 4))->toBe(3.0);
    }
});

it('Standard-Komposition je Einheit und Produktionsanteil: eine Portion = ein Ansatz', function () {
    $dar = app(DarreichungService::class);
    $proEinheit = $dar->standardProEinheit($this->r, $this->rootTeam);
    expect(round(array_sum(array_column($proEinheit, 'masse_g')), 1))->toBe(1200.0);

    // 10 verkaufte Portionen = 10 Ansätze, für jede Zutat gleich
    $anteil = $dar->produktionsAnteil($this->r, $this->std->refresh(), 10, $this->rootTeam);
    expect(round($anteil['ansaetze'], 4))->toBe(10.0)
        ->and(array_map(fn ($a) => round($a, 4), array_values($anteil['je_zutat'])))->toBe([10.0, 10.0])
        ->and($anteil['gramm_je_portion'])->toBe(1200.0);
});
