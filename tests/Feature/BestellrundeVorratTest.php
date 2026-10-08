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
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderRound;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\LagerartikelService;
use Platform\FoodAlchemist\Livewire\Orders\Editor as OrdersEditor;
use Livewire\Livewire;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 73 · Bestellrunde: löschen, vollständig wieder öffnen, Lager-Reservierung über Runden, Konzept/Paket-Quellen.
 * Spec 74 · Lagerartikel: nicht über Rezeptbedarf, sondern Nachfüllen unter Mindestbestand.
 * Fixture wie LagerVerbrauchTest (Kuchen = 1000 g Mehl + 150 g Vanillesauce je 10 Port.).
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


function s73pos(array $p, $gp): ?array
{
    return collect($p['orders_preview'])->flatMap(fn ($g) => $g['positionen'])->firstWhere('gp_id', $gp->id);
}

it('Spec 73: Runde speichert ihre Quellen, öffnet vollständig wieder und lässt sich löschen', function () {
    $svc = app(OrderService::class);
    $r = $svc->generateDraftsFromSources($this->rootTeam, $this->quelle, null, $this->user->id, ['lager_abgleich' => false, 'skip' => []], ['label' => 'Sommerfest']);
    $round = FoodAlchemistOrderRound::find($r['round']['id']);
    expect($round->sources)->toHaveCount(1)->and($round->source_refs)->not->toBeEmpty()->and(FoodAlchemistOrder::count())->toBe(1);

    Livewire::test(OrdersEditor::class)->call('oeffnenRunde', $round->id)
        ->assertSet('cockpitSources', fn ($v) => count($v) === 1 && $v[0]['type'] === 'recipe' && (float) $v[0]['qty'] === 100.0)
        ->assertSet('rundeGesperrt', true)
        ->call('rundeLoeschen')->assertDispatched('orders-runde-geloescht');
    expect(FoodAlchemistOrder::count())->toBe(0)->and(FoodAlchemistOrderRound::count())->toBe(0);
});

it('Spec 73: Runde mit versendeter Bestellung lässt sich nicht löschen', function () {
    \Illuminate\Support\Carbon::setTestNow('2026-08-01 09:00');
    $svc = app(OrderService::class);
    $r = $svc->generateDraftsFromSources($this->rootTeam, $this->quelle, null, $this->user->id, ['skip' => []], ['label' => 'X']);
    FoodAlchemistOrder::query()->update(['status' => 'sent']);
    expect(fn () => $svc->deleteRound($this->rootTeam, $r['round']['id']))->toThrow(\RuntimeException::class, 'schon versendet');
    \Illuminate\Support\Carbon::setTestNow();
});

it('Spec 73: Lager, das eine gespeicherte Runde angerechnet hat, zählt in der nächsten nicht noch einmal', function () {
    ($this->bestand)($this->mehl, 4000);
    $svc = app(OrderService::class);
    $quelle = [['type' => 'recipe', 'id' => $this->kuchen->id, 'qty' => 100, 'unit' => 'portions', 'delivery_date' => now()->addDays(3)->toDateString()]];
    $a = $svc->generateDraftsFromSources($this->rootTeam, $quelle, null, $this->user->id, ['lager_abgleich' => true], ['label' => 'Runde A', 'desired_delivery_date' => now()->addDays(3)->toDateString()]);
    expect(FoodAlchemistOrderRound::find($a['round']['id'])->lager_reserviert['gp'][$this->mehl->id])->toEqual(4000);

    $b = $svc->previewFromSources($this->rootTeam, $quelle, null, ['lager_abgleich' => true]);
    expect((float) s73pos($b, $this->mehl)['qty_packs'])->toBe(10.0)                       // nichts mehr frei
        ->and(s73pos($b, $this->mehl)['lager_reserviert_g'])->toEqual(4000)
        ->and(s73pos($b, $this->mehl)['lager_reserviert_fuer'])->toBe(['Runde A']);

    $wieder = $svc->previewFromSources($this->rootTeam, $quelle, null, ['lager_abgleich' => true, 'round_id' => $a['round']['id']]);
    expect((float) s73pos($wieder, $this->mehl)['qty_packs'])->toBe(6.0);                 // eigene Reservierung zählt für sich selbst
});

it('Spec 73: Paket × Personen als Quelle (Vorschau, Vorlage, Bestellrunde)', function () {
    $paket = FoodAlchemistPaket::create(['team_id' => $this->rootTeam->id, 'name' => 'Kuchenbuffet']);
    $paket->dishes()->create(['team_id' => $this->rootTeam->id, 'sales_recipe_id' => $this->kuchen->id, 'position' => 1]);
    $p = app(OrderService::class)->previewFromSources($this->rootTeam, [['type' => 'paket', 'id' => $paket->id, 'qty' => 100, 'unit' => 'persons']]);
    expect((float) s73pos($p, $this->mehl)['qty_packs'])->toBe(10.0);

    $tpl = app(\Platform\FoodAlchemist\Services\OrderTemplateService::class);
    $v = $tpl->anlegen($this->rootTeam, ['name' => 'Buffet', 'kategorie' => 'Events']);
    $tpl->positionSetzen($this->rootTeam, $v->id, 'paket', $paket->id, 100);
    expect($tpl->quellen($this->rootTeam, $v->id))->toMatchArray([['type' => 'paket', 'id' => $paket->id, 'qty' => 100.0, 'unit' => 'persons', 'delivery_date' => null, 'reference' => 'Vorlage Buffet']])
        ->and($tpl->kategorien($this->rootTeam))->toBe(['Events']);

    Livewire::test(OrdersEditor::class)->call('oeffnenNeu')
        ->set('konzeptSuche', 'Kuchenb')->assertSee('Kuchenbuffet')
        ->call('cockpitKonzeptEinfuegen', 'paket', $paket->id)
        ->assertSet('cockpitSources', fn ($v) => $v[0]['type'] === 'paket' && $v[0]['unit'] === 'persons');
});

it('Spec 74: Lagerartikel kommen aus dem Vorrat, unter Mindestbestand wird nachgefüllt', function () {
    $la = app(LagerartikelService::class);
    $la->setzen($this->rootTeam, $this->zucker->id, true, '2', '5');      // min 2 kg, auffüllen auf 5 kg
    ($this->bestand)($this->zucker, 500);
    $svc = app(OrderService::class);

    $p = $svc->previewFromSources($this->rootTeam, $this->quelle);
    expect(s73pos($p, $this->zucker))->toBeNull()
        ->and(collect($p['vorrat'])->pluck('gp_id')->all())->toBe([$this->zucker->id])
        ->and($p['nachfuellen_moeglich'])->toBe(1)
        ->and($la->unterMindest($this->rootTeam)[0])->toMatchArray(['name' => 'Zucker', 'status' => 'unter_min', 'bestand' => 0.5]);

    $key = $p['vorrat'][0]['position_key'];
    $trotzdem = $svc->previewFromSources($this->rootTeam, $this->quelle, null, ['vorrat_bestellen' => [$key => true]]);
    expect(s73pos($trotzdem, $this->zucker))->not->toBeNull();

    $nach = $svc->previewFromSources($this->rootTeam, array_merge($this->quelle, [['type' => 'nachfuellen', 'id' => 0, 'qty' => 1, 'unit' => 'auftrag']]));
    $z = s73pos($nach, $this->zucker);
    expect((float) $z['qty_packs'])->toBe(5.0)->and($z['nachfuellen'])->toBeTrue()->and($nach['nachfuellen_aktiv'])->toBeTrue();   // 4,5 kg → 5 Sack
});

it('Spec 74: Lager-Seite zeigt Lagerartikel + Signal, MCP lagerartikel.GET/PUT', function () {
    $ctx = new \Platform\Core\Contracts\ToolContext($this->user, $this->rootTeam);
    $reg = app(\Platform\Core\Tools\ToolRegistry::class);
    $put = $reg->get('foodalchemist.lagerartikel.PUT')->execute(['gp_ids' => [$this->zucker->id], 'mindestbestand' => 2, 'sollbestand' => 5], $ctx);
    expect($put->success)->toBeTrue();
    $get = $reg->get('foodalchemist.lagerartikel.GET')->execute(['nur_unter_mindest' => true], $ctx);
    expect($get->data['anzahl'])->toBe(1)->and($get->data['lagerartikel'][0]['status'])->toBe('leer');

    Livewire::test(\Platform\FoodAlchemist\Livewire\Lager\Index::class)
        ->assertSee('1 Lagerartikel unter Mindestbestand')
        ->call('reiterSetzen', 'lagerartikel')->assertSee('Zucker')->assertSee('leer')
        ->set('vorrat.' . $this->zucker->id . '.min', '3')->call('lagerartikelSpeichern', $this->zucker->id)->assertSet('fehler', null);
    expect($la = $reg->get('foodalchemist.lagerartikel.GET')->execute([], $ctx)->data['lagerartikel'][0]['min'])->toBe(3.0);
});

it('Spec 73: Klick auf eine Bestellung öffnet den Editor, Hinweis auf die Runde', function () {
    $svc = app(OrderService::class);
    $r = $svc->generateDraftsFromSources($this->rootTeam, $this->quelle, null, $this->user->id, ['skip' => []], ['label' => 'Sommerfest']);
    $orderId = $r['orders'][0];
    Livewire::test(\Platform\FoodAlchemist\Livewire\Orders\Index::class)->call('oeffnen', $orderId)
        ->assertDispatched('orders-editor.bearbeiten', id: $orderId);
    Livewire::test(OrdersEditor::class)->call('oeffnenBearbeiten', $orderId)
        ->assertSee('Teil der Bestellrunde „Sommerfest“')->assertSee('Ganze Runde öffnen');
});

it('Spec 74: Grundprodukt → Reiter Lager pflegt Lagerartikel ohne Bearbeiten', function () {
    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\DetailPanel::class)
        ->call('zeige', $this->zucker->id)->set('section', 'lager')
        ->assertSee('Lagerartikel (Grundvorrat')
        ->set('lagerartikelForm.ist', true)->set('lagerartikelForm.min', '2')->set('lagerartikelForm.soll', '6')
        ->call('lagerartikelSetzen')->assertSet('lagerartikelFehler', null);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistGpLagerartikel::where('gp_id', $this->zucker->id)->first();
    expect((float) $la->mindestbestand)->toBe(2000.0)->and((float) $la->sollbestand)->toBe(6000.0);
});

it('Spec 74: Dashboard-Aufgabe „Lagerartikel unter Mindestbestand"', function () {
    app(LagerartikelService::class)->setzen($this->rootTeam, $this->zucker->id, true, '2', '5');
    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Dashboard::class)
        ->assertSee('Lagerartikel unter Mindestbestand')
        ->assertViewHas('aufgaben', fn ($a) => collect($a)->firstWhere('key', 'lager-mindest')['zahl'] === 1);
});

it('Spec 74: Vorschlag nach Warengruppe markiert genutzte Grundprodukte, typische Vorrats-Gruppen zuerst', function () {
    $t = $this->rootTeam->id;
    \Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe::create(['team_id' => $t, 'code' => '07', 'name' => 'Gewürze & Kräuter', 'sort_order' => 7]);
    \Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe::create(['team_id' => $t, 'code' => '02', 'name' => 'Molkerei', 'sort_order' => 2]);
    $this->zucker->update(['commodity_group_code' => '07']);
    $this->mehl->update(['commodity_group_code' => '07']);
    $this->butter->update(['commodity_group_code' => '02']);
    ($this->bestand)($this->zucker, 1000);
    ($this->bestand)($this->butter, 1000);                       // Mehl: weder Bestand noch bestellt → nicht vorgeschlagen
    $la = app(LagerartikelService::class);
    $v = $la->warengruppenVorschlag($this->rootTeam);
    expect($v[0])->toMatchArray(['code' => '07', 'anzahl' => 1, 'typisch' => true])->and($v[1]['code'])->toBe('02');

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Lager\Index::class)
        ->call('reiterSetzen', 'lagerartikel')->assertSee('Vorschlag nach Warengruppe')
        ->call('warengruppeAlsLagerartikel', '07')->assertSee('1 Grundprodukt(e) als Lagerartikel markiert');
    expect(array_keys($la->ids($this->rootTeam)))->toBe([$this->zucker->id]);
});

it('Spec 74: Ausbuchen nutzt die Gramm aus dem Produktions-Schnappschuss (Darreichungs-Deltas) und skaliert Handkorrekturen', function () {
    ($this->bestand)($this->mehl, 20000);
    $prod = app(ProductionOrderService::class);
    $order = $prod->saveNew($this->rootTeam, '2026-08-13', 'Delta', [['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'r:k']]);
    $zeile = $order->lines()->where('recipe_id', $this->kuchen->id)->first();
    expect(collect($zeile->zutaten)->firstWhere('gp_id', $this->mehl->id)['menge_g'])->toEqual(10000);
    // Darreichung mit weniger Mehl simulieren: Schnappschuss sagt 8 kg statt 10 kg
    $zut = collect($zeile->zutaten)->map(fn ($z) => ($z['gp_id'] ?? null) === $this->mehl->id ? ['menge_g' => 8000.0] + $z : $z)->all();
    $zeile->update(['zutaten' => $zut, 'is_manual_ansaetze' => true, 'manual_ansaetze' => (float) $zeile->ansaetze / 2]);   // + halbe Menge von Hand
    [$gp] = app(\Platform\FoodAlchemist\Services\ProduktionsVerbrauchService::class)->verbrauch($order->refresh());
    expect($gp[$this->mehl->id])->toEqual(4000);
});
