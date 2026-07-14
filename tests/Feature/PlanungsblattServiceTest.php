<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Blaetter\Index as BlaetterIndex;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R7.1 — Planungs-Blätter: hand-gerechnete Szenarien.
 *
 * Baum: VK-Gericht „Kuchen" (10 Portionen/Batch) = 1000 g Mehl (GP) + 150 g
 * Basisrezept „Vanillesauce". Vanillesauce (Basis 1000 g) = 500 g Zucker + 500 g Butter.
 * Lieferanten: Mehl+Zucker → Chefs, Butter → Hanos.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PlanungsblattService::class);
    $this->concepts = app(ConceptService::class);
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    $mkGpMitPreis = function (string $name, float $preisProKg, string $lieferant) {
        $supplier = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => $lieferant]);
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name . ' 1kg', 'article_number' => 'ART-' . strtoupper(substr($name, 0, 3)),
            'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preisProKg, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };

    $this->mehl = $mkGpMitPreis('Mehl', 2.00, 'Chefs');
    $this->zucker = $mkGpMitPreis('Zucker', 1.00, 'Chefs');
    $this->butter = $mkGpMitPreis('Butter', 12.00, 'Hanos');

    // Basisrezept Vanillesauce (Basis 1000 g) = 500 g Zucker + 500 g Butter
    $this->sauce = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vanillesauce', 'name' => 'Vanillesauce',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0,
    ]);
    $this->sauce->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->zucker->id, 'raw_text' => 'Zucker', 'quantity' => 500, 'unit_vocab_id' => $this->g->id]);
    $this->sauce->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 1, 'gp_id' => $this->butter->id, 'raw_text' => 'Butter', 'quantity' => 500, 'unit_vocab_id' => $this->g->id]);

    // VK-Gericht Kuchen (10 Portionen/Batch) = 1000 g Mehl + 150 g Vanillesauce
    $this->kuchen = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'kuchen', 'name' => 'DES: Kuchen',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 3.50, 'sales_unit_count' => 10,
        'preparation' => 'Mehl mit Vanillesauce verrühren und backen.',
    ]);
    $this->kuchen->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->mehl->id, 'raw_text' => 'Mehl', 'quantity' => 1000, 'unit_vocab_id' => $this->g->id]);
    $this->kuchen->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 1, 'referenced_recipe_id' => $this->sauce->id, 'raw_text' => 'Vanillesauce', 'quantity' => 150, 'unit_vocab_id' => $this->g->id]);

    $rc = app(RecipeRecomputeService::class);
    $rc->recomputePipeline($this->sauce->id);
    $rc->recomputePipeline($this->kuchen->id);
});

it('Produktionsblatt: Top-Gericht linear (10 Ansätze), Basisrezept auf ganze Ansätze gerundet (1,5 → 2)', function () {
    $blatt = $this->svc->produktionsblatt($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100]);

    $rez = collect($blatt['rezepte'])->keyBy('name');
    // Kuchen: 100 Portionen ÷ 10 = 10 Ansätze (linear, VK).
    expect($rez['DES: Kuchen']['ansaetze'])->toBe(10.0)
        ->and($rez['DES: Kuchen']['ist_basisrezept'])->toBeFalse();
    // Vanillesauce: 150 g × 10 = 1500 g Bedarf ÷ 1000 g Basis = 1,5 → aufgerundet 2 GANZE Ansätze.
    expect($rez['Vanillesauce']['benoetigt_ansaetze'])->toBe(1.5)
        ->and($rez['Vanillesauce']['ansaetze'])->toBe(2)
        ->and($rez['Vanillesauce']['ist_basisrezept'])->toBeTrue()
        ->and($rez['Vanillesauce']['produzierte_menge_kg'])->toBe(2.0);

    // Zutaten-Zeilen skaliert: Kuchen 10000 g Mehl; Vanillesauce 2 Ansätze → 1000 g Zucker/Butter.
    $kuchenMehl = collect($rez['DES: Kuchen']['zutaten'])->firstWhere('name', 'Mehl');
    expect($kuchenMehl['menge'])->toBe(10000.0);
    $sauceZucker = collect($rez['Vanillesauce']['zutaten'])->firstWhere('name', 'Zucker');
    expect($sauceZucker['menge'])->toBe(1000.0);
});

it('GP-Bedarf verlustfrei aggregiert über den Baum, EK aus Lead-€/g', function () {
    $blatt = $this->svc->produktionsblatt($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100]);
    $gp = collect($blatt['gp_bedarf'])->keyBy('name');

    // Mehl: 1000 g × 10 Ansätze = 10 kg, EK 10000 g × 0,002 €/g = 20,00 €.
    expect($gp['Mehl']['menge_kg'])->toBe(10.0)->and($gp['Mehl']['ek_eur'])->toBe(20.0);
    // Zucker: 500 g × 2 Ansätze = 1 kg, EK 1,00 €.
    expect($gp['Zucker']['menge_kg'])->toBe(1.0)->and($gp['Zucker']['ek_eur'])->toBe(1.0);
    // Butter: 500 g × 2 Ansätze = 1 kg, EK 12,00 €.
    expect($gp['Butter']['menge_kg'])->toBe(1.0)->and($gp['Butter']['ek_eur'])->toBe(12.0);
});

it('Bestellvorschlag: nach Lead-LA-Lieferant gruppiert, EK-Summe je Lieferant', function () {
    $blatt = $this->svc->bestellvorschlag($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'portions' => 100]);
    $lief = collect($blatt['lieferanten'])->keyBy('lieferant');

    // Chefs: Mehl 20 + Zucker 1 = 21,00 €; Hanos: Butter 12,00 €.
    expect($lief['Chefs']['ek_summe'])->toBe(21.0)
        ->and($lief['Hanos']['ek_summe'])->toBe(12.0)
        ->and(collect($lief['Chefs']['positionen'])->pluck('gp')->sort()->values()->all())->toBe(['Mehl', 'Zucker']);
    // Lead-Artikel + Ausweich-Feld vorhanden (hier keine Zweitquelle → null).
    $mehlPos = collect($lief['Chefs']['positionen'])->firstWhere('gp', 'Mehl');
    expect($mehlPos['lead_artikel'])->toBe('Mehl 1kg')->and($mehlPos['ek_bekannt'])->toBeTrue();
});

it('Konzept + Personen: Portions-Äquivalent × Pax → Batches', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Menü A']);
    $slot = $this->concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Dessert']);
    $slot = $this->concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->kuchen->id]);
    $slot->update(['quantity' => 1, 'unit_vocab_id' => $this->portion->id]); // 1 Portion pro Person

    // 20 Personen × 1 Portion ÷ 10 Portionen/Batch = 2 Ansätze Kuchen → Mehl 2000 g.
    $blatt = $this->svc->produktionsblatt($this->rootTeam, ['concept_id' => $concept->id, 'persons' => 20]);
    $rez = collect($blatt['rezepte'])->keyBy('name');
    expect($rez['DES: Kuchen']['ansaetze'])->toBe(2.0);
    $gp = collect($blatt['gp_bedarf'])->keyBy('name');
    expect($gp['Mehl']['menge_kg'])->toBe(2.0)->and($gp['Mehl']['ek_eur'])->toBe(4.0);
});

it('Einkaufsliste führt mehrere Ziele zusammen', function () {
    $blatt = $this->svc->einkaufsliste($this->rootTeam, [
        ['recipe_id' => $this->kuchen->id, 'portions' => 100],
        ['recipe_id' => $this->kuchen->id, 'portions' => 100],
    ]);
    $lief = collect($blatt['lieferanten'])->keyBy('lieferant');
    // Zusammengeführt VOR Rundung: 20 Ansätze Kuchen → Mehl 20 kg (40 €); Sauce-Bedarf
    // 150 g × 20 ÷ 1000 = 3,0 → 3 Ansätze → Zucker 1,5 kg (1,50 €), Butter 1,5 kg (18 €).
    expect($lief['Chefs']['ek_summe'])->toBe(41.5)->and($lief['Hanos']['ek_summe'])->toBe(18.0);
});

it('MCP: die drei Blätter-Tools sind registriert, read-only und liefern konsistente Zahlen', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($user, $this->rootTeam);

    foreach (['produktionsblatt', 'bestellvorschlag', 'einkaufsliste'] as $t) {
        $tool = $registry->get("foodalchemist.{$t}.GET");
        expect($tool)->not->toBeNull()
            ->and($tool->getMetadata()['read_only'])->toBeTrue()
            ->and($tool->getSchema()['type'])->toBe('object');
    }

    $prod = $registry->get('foodalchemist.produktionsblatt.GET')
        ->execute(['recipe_id' => $this->kuchen->id, 'portions' => 100], $kontext);
    expect($prod->success)->toBeTrue()
        ->and(collect($prod->data['rezepte'])->firstWhere('name', 'Vanillesauce')['ansaetze'])->toBe(2);

    $best = $registry->get('foodalchemist.bestellvorschlag.GET')
        ->execute(['recipe_id' => $this->kuchen->id, 'portions' => 100], $kontext);
    expect($best->success)->toBeTrue()
        ->and(collect($best->data['lieferanten'])->firstWhere('lieferant', 'Chefs')['ek_summe'])->toBe(21.0);

    $eink = $registry->get('foodalchemist.einkaufsliste.GET')
        ->execute(['ziele' => [['recipe_id' => $this->kuchen->id, 'portions' => 100]]], $kontext);
    expect($eink->success)->toBeTrue();

    // Validierung: beide Ziel-Schlüssel gleichzeitig → Fehler.
    $fehler = $registry->get('foodalchemist.produktionsblatt.GET')
        ->execute(['recipe_id' => $this->kuchen->id, 'concept_id' => 1], $kontext);
    expect($fehler->success)->toBeFalse()->and($fehler->errorCode)->toBe('VALIDATION_ERROR');
});

it('Blätter-UI: Gericht-Auswahl + Blätter-Filter (welche Blätter erzeugt werden)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    // Default: alle drei Blätter
    $comp = Livewire::test(BlaetterIndex::class)
        ->set('zielTyp', 'recipe')
        ->call('waehleGericht', $this->kuchen->id)
        ->set('menge', 100)
        ->assertSee('Produktionsblatt')
        ->assertSee('Vanillesauce')
        ->assertSee('Bestellvorschlag')
        ->assertSee('Einkaufsliste')
        ->assertSee('Chefs')
        ->assertSee('Hanos');

    // Filter: nur Produktion → Lieferanten-Blätter verschwinden
    $comp->set('blaetter', ['produktion'])
        ->assertSee('Produktionsblatt')
        ->assertDontSee('Bestellvorschlag')
        ->assertDontSee('Einkaufsliste');

    // Filter: nur Bestellung → Produktionsblatt verschwindet
    $comp->set('blaetter', ['bestellung'])
        ->assertDontSee('Produktionsblatt')
        ->assertSee('Bestellvorschlag')
        ->assertDontSee('Einkaufsliste');
});

it('Blätter-Dokument-Blade rendert (Produktion + Bestellung)', function () {
    $svc = app(PlanungsblattService::class);
    $ziel = ['recipe_id' => $this->kuchen->id, 'portions' => 100];

    $prodHtml = view('foodalchemist::dokumente.blatt', [
        'blatt' => $svc->produktionsblatt($this->rootTeam, $ziel),
        'typ' => 'produktion', 'titel' => 'Produktionsblatt', 'untertitel' => 'Kuchen · 100 Portionen', 'istPdf' => false,
    ])->render();
    expect($prodHtml)->toContain('Produktionsblatt')->toContain('Vanillesauce')->toContain('Basisrezept');

    // Produktionsblatt zeigt die Zubereitungs-Anweisung (Freitext, kein Step-Modell)
    expect($prodHtml)->toContain('Mehl mit Vanillesauce verrühren');

    $bestHtml = view('foodalchemist::dokumente.blatt', [
        'blatt' => $svc->bestellvorschlag($this->rootTeam, $ziel),
        'typ' => 'bestellung', 'titel' => 'Bestellvorschlag', 'untertitel' => 'Kuchen · 100 Portionen', 'istPdf' => false,
    ])->render();
    expect($bestHtml)->toContain('Bestellvorschlag')->toContain('Chefs')->toContain('Wareneinsatz gesamt');

    // Einkaufsliste-Blatt (Lieferanten-Ansicht, mehrere Ziele zusammengeführt)
    $einkHtml = view('foodalchemist::dokumente.blatt', [
        'blatt' => $svc->einkaufsliste($this->rootTeam, [$ziel]),
        'typ' => 'einkauf', 'titel' => 'Einkaufsliste', 'untertitel' => 'Event', 'istPdf' => false,
    ])->render();
    expect($einkHtml)->toContain('Einkaufsliste')->toContain('Chefs')->toContain('Wareneinsatz gesamt');
});
