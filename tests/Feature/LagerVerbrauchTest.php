<?php

use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryBatch;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\EigenproduktionService;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\ProduktionsVerbrauchService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 72 · Lager im Fluss: Eigenproduktion kürzt den Rezeptbedarf der Bestellrunde (per Knopf),
 * Produktion „fertig" bucht den Verbrauch exakt aus (GP + Eigenproduktion), Einzelbestellung kürzt auf Restbedarf.
 * Fixture: Kuchen (10 Port./Ansatz) = 1000 g Mehl + 150 g Vanillesauce; Vanillesauce (1 kg) = 500 g Zucker + 500 g Butter.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $t = $this->rootTeam->id;
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->la = [];
    $mk = function (string $name, float $preis) use ($t) {
        $sup = FoodAlchemistSupplier::firstOrCreate(['team_id' => $t, 'name' => 'Chefs']);
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $sup->id, 'designation' => $name . ' 1kg', 'article_number' => 'A-' . $name, 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);
        $this->la[$name] = $la;

        return $gp->refresh();
    };
    $this->mehl = $mk('Mehl', 2.0);
    $this->zucker = $mk('Zucker', 1.0);
    $this->butter = $mk('Butter', 12.0);
    $this->sauce = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'vanillesauce', 'name' => 'Vanillesauce', 'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0]);
    $this->sauce->ingredients()->create(['team_id' => $t, 'position' => 0, 'gp_id' => $this->zucker->id, 'raw_text' => 'Zucker', 'quantity' => 500, 'unit_vocab_id' => $g->id]);
    $this->sauce->ingredients()->create(['team_id' => $t, 'position' => 1, 'gp_id' => $this->butter->id, 'raw_text' => 'Butter', 'quantity' => 500, 'unit_vocab_id' => $g->id]);
    $this->kuchen = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'kuchen', 'name' => 'DES: Kuchen', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 3.5, 'sales_unit_count' => 10]);
    $this->kuchen->ingredients()->create(['team_id' => $t, 'position' => 0, 'gp_id' => $this->mehl->id, 'raw_text' => 'Mehl', 'quantity' => 1000, 'unit_vocab_id' => $g->id]);
    $this->kuchen->ingredients()->create(['team_id' => $t, 'position' => 1, 'referenced_recipe_id' => $this->sauce->id, 'raw_text' => 'Vanillesauce', 'quantity' => 150, 'unit_vocab_id' => $g->id]);
    $rc = app(RecipeRecomputeService::class);
    $rc->recomputePipeline($this->sauce->id);
    $rc->recomputePipeline($this->kuchen->id);
    $this->ort = FoodAlchemistInventoryLocation::create(['team_id' => $t, 'name' => 'Lager', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
    $this->bestand = fn ($gp, float $gramm) => FoodAlchemistInventoryStock::create(['team_id' => $t, 'inventory_location_id' => $this->ort->id, 'gp_id' => $gp->id, 'qty_base' => $gramm, 'base_unit' => 'g']);
    $this->quelle = [['type' => 'recipe', 'id' => $this->kuchen->id, 'qty' => 100, 'unit' => 'portions', 'delivery_date' => '2026-08-13']];
});

function vorschauPos(array $p, $gp): ?array
{
    return collect($p['orders_preview'])->flatMap(fn ($g) => $g['positionen'])->firstWhere('gp_id', $gp->id);
}

it('Bestellrunde: eingefrorenes Gericht wird angezeigt und kürzt den Bedarf erst auf Knopfdruck', function () {
    app(EigenproduktionService::class)->einlagern($this->rootTeam, ['recipe_id' => $this->kuchen->id, 'menge' => 30]);   // 30 Portionen TK
    $svc = app(OrderService::class);

    $nur = $svc->previewFromSources($this->rootTeam, $this->quelle);
    expect($nur['rezept_lager'])->toHaveCount(1)
        ->and($nur['rezept_lager'][0])->toMatchArray(['recipe_id' => $this->kuchen->id, 'im_lager' => 30.0, 'bedarf' => 100.0, 'abziehen' => false])
        ->and((float) vorschauPos($nur, $this->mehl)['qty_packs'])->toBe(10.0);

    $mit = $svc->previewFromSources($this->rootTeam, $this->quelle, null, ['lager_rezept' => [$this->kuchen->id => true]]);
    expect((float) vorschauPos($mit, $this->mehl)['qty_packs'])->toBe(7.0)
        ->and($mit['rezept_lager'][0]['abgezogen'])->toBe(30.0);

    $svc->generateDraftsFromSources($this->rootTeam, $this->quelle, null, null, ['lager_abgleich' => true]);
    expect((float) \Platform\FoodAlchemist\Models\FoodAlchemistOrderLine::where('supplier_item_id', $this->la['Mehl']->id)->value('qty_packs'))->toBe(7.0);
});

it('Bestellrunde: eingefrorenes Basisrezept kürzt nur die Komponente, nicht das Gericht', function () {
    app(EigenproduktionService::class)->einlagern($this->rootTeam, ['recipe_id' => $this->sauce->id, 'menge' => 1]);     // 1 kg Sauce
    $p = app(OrderService::class)->previewFromSources($this->rootTeam, $this->quelle, null, ['lager_abgleich' => true]);
    // Bedarf Sauce 1,5 kg − 1 kg Lager = 0,5 kg → 1 Ansatz → 500 g Zucker statt 1 kg (2 Ansätze)
    expect((float) vorschauPos($p, $this->zucker)['needed_base_g'])->toEqual(500)
        ->and((float) vorschauPos($p, $this->mehl)['qty_packs'])->toBe(10.0)
        ->and(collect($p['rezept_lager'])->firstWhere('recipe_id', $this->sauce->id)['abgezogen'])->toBe(1.0);
});

it('Produktion fertig: Verbrauch exakt aus dem Lager, Eigenproduktion per FIFO, nie unter 0, einmalig', function () {
    ($this->bestand)($this->mehl, 12000);
    ($this->bestand)($this->butter, 300);                         // zu wenig — Rest wird gemeldet
    $prod = app(ProductionOrderService::class);
    $order = $prod->saveNew($this->rootTeam, '2026-08-13', 'Sommerfest', [['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'r:kuchen']]);
    $saucenZeile = $order->lines()->where('recipe_id', $this->sauce->id)->first();
    expect($saucenZeile)->not->toBeNull();

    // Sauce kommt aus dem TK statt aus der Produktion
    $charge = app(EigenproduktionService::class)->einlagern($this->rootTeam, ['recipe_id' => $this->sauce->id, 'menge' => 2]);
    $prod->setLineStruck($this->rootTeam, $saucenZeile->id, true, 'aus dem TK');
    $prod->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::InProgress);
    $prod->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::Done, ['finish_note' => 'fertig']);

    expect((float) FoodAlchemistInventoryStock::where('gp_id', $this->mehl->id)->value('qty_base'))->toBe(2000.0)      // 12 kg − 10 kg
        ->and((float) FoodAlchemistInventoryStock::where('gp_id', $this->butter->id)->value('qty_base'))->toBe(300.0)   // Sauce nicht produziert → keine Butter
        ->and((float) $charge->refresh()->qty_rest)->toBe(500.0)                                                         // 2 kg − 1,5 kg
        ->and(FoodAlchemistInventoryMovement::where('production_order_id', $order->id)->where('source', 'produktion')->count())->toBe(1)
        ->and(FoodAlchemistInventoryMovement::where('production_order_id', $order->id)->where('source', 'entnahme')->count())->toBe(1);

    $nochmal = app(ProduktionsVerbrauchService::class)->ausbuchen($this->rootTeam, $order->refresh());
    expect($nochmal['bereits_gebucht'])->toBeTrue();
});

it('Produktion fertig: fehlender Bestand wird gemeldet statt negativ gebucht, kleinste Mengen exakt', function () {
    ($this->bestand)($this->zucker, 200);
    ($this->bestand)($this->butter, 999.5);
    $prod = app(ProductionOrderService::class);
    $order = $prod->saveNew($this->rootTeam, '2026-08-13', 'Sauce', [['recipe_id' => $this->sauce->id, 'amount_kg' => 1, 'source_ref' => 'r:sauce']]);
    $prod->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::InProgress);
    $prod->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::Done, ['finish_note' => 'fertig']);

    expect((float) FoodAlchemistInventoryStock::where('gp_id', $this->zucker->id)->value('qty_base'))->toBe(0.0)
        ->and((float) FoodAlchemistInventoryStock::where('gp_id', $this->butter->id)->value('qty_base'))->toBe(499.5);
    $ev = \Platform\FoodAlchemist\Models\FoodAlchemistProductionEvent::where('order_id', $order->id)->where('event_type', 'order_status_changed')->latest('id')->first();
    expect($ev->payload['lager_verbrauch']['fehlt'][0])->toMatchArray(['name' => 'Zucker', 'menge_g' => 300.0]);
});

it('Einzelbestellung: Zeile zeigt Lager und kürzt auf den Restbedarf', function () {
    ($this->bestand)($this->mehl, 4000);
    $svc = app(OrderService::class);
    $line = $svc->addManualLine($this->rootTeam, $this->la['Mehl']->id, 10, null, null, '2026-08-13');
    $z = collect($svc->detail($this->rootTeam, $line->order_id)['zeilen'])->firstWhere('id', $line->id);
    expect($z['inventory']['packs_fuer_rest'])->toBe(6.0);

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Orders\Editor::class)
        ->call('oeffnenBearbeiten', $line->order_id)
        ->assertSee('auf 6 Sack kürzen')
        ->call('updateLineQty', $line->id, 6);
    expect((float) $line->refresh()->qty_packs)->toBe(6.0);
});

it('Inventur im Editor: Liste bleibt vorne, Öffnen/Anlegen öffnet den Editor, Schließen kehrt zur Liste zurück', function () {
    ($this->bestand)($this->mehl, 4000);
    $lw = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Lager\Index::class)
        ->call('reiterSetzen', 'inventur')->set('neuLagerortId', $this->ort->id)->set('neuDatum', '2026-09-30')
        ->call('inventurAnlegen')
        ->assertDispatched('modal.open', name: 'lager-inventur')
        ->assertSee('Inventuren')->assertSee('Zählliste')->assertSee('Mehl');
    $id = $lw->get('inventurId');
    $lw->call('beiModalGeschlossen', 'lager-inventur')->assertSet('inventurId', null)->assertDontSee('data-lager-inventur-editor', false)
        ->call('inventurOeffnen', $id)->assertDispatched('modal.open', name: 'lager-inventur')->assertSet('inventurId', $id);
});
