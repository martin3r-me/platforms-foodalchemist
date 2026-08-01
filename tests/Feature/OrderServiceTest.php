<?php

use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Orders\Editor as OrdersEditor;
use Platform\FoodAlchemist\Livewire\Orders\Index as OrdersIndex;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 17 / S2 — Bestellschienen-Motor. Fixture wie PlanungsblattServiceTest:
 * Kuchen (10 Portionen/Batch) = 1000 g Mehl (Chefs) + 150 g Vanillesauce;
 * Vanillesauce (1000 g) = 500 g Zucker (Chefs) + 500 g Butter (Hanos). Alle LAs
 * 1-kg-Gebinde. Bei 100 Portionen: Mehl 10 kg, Zucker 1 kg, Butter 1 kg.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(OrderService::class);
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $this->laOf = [];
    $mkGp = function (string $name, float $preis, string $lieferant) {
        $supplier = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => $lieferant]);
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name . ' 1kg', 'article_number' => 'ART-' . strtoupper(substr($name, 0, 3)),
            'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);
        $this->laOf[$name] = $la;

        return $gp->refresh();
    };

    $this->mehl = $mkGp('Mehl', 2.00, 'Chefs');
    $this->zucker = $mkGp('Zucker', 1.00, 'Chefs');
    $this->butter = $mkGp('Butter', 12.00, 'Hanos');

    $this->sauce = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vanillesauce', 'name' => 'Vanillesauce',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0,
    ]);
    $this->sauce->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->zucker->id, 'raw_text' => 'Zucker', 'quantity' => 500, 'unit_vocab_id' => $this->g->id]);
    $this->sauce->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 1, 'gp_id' => $this->butter->id, 'raw_text' => 'Butter', 'quantity' => 500, 'unit_vocab_id' => $this->g->id]);

    $this->kuchen = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'kuchen', 'name' => 'DES: Kuchen',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 3.50, 'sales_unit_count' => 10,
    ]);
    $this->kuchen->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->mehl->id, 'raw_text' => 'Mehl', 'quantity' => 1000, 'unit_vocab_id' => $this->g->id]);
    $this->kuchen->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 1, 'referenced_recipe_id' => $this->sauce->id, 'raw_text' => 'Vanillesauce', 'quantity' => 150, 'unit_vocab_id' => $this->g->id]);

    $rc = app(RecipeRecomputeService::class);
    $rc->recomputePipeline($this->sauce->id);
    $rc->recomputePipeline($this->kuchen->id);

    $this->ziel = ['recipe_id' => $this->kuchen->id, 'portions' => 100];
});

it('draftForSupplier: nur EIN offener Draft je (team, supplier)', function () {
    $chefs = FoodAlchemistSupplier::where('name', 'Chefs')->first();
    $a = $this->svc->draftForSupplier($this->rootTeam, $chefs->id);
    $b = $this->svc->draftForSupplier($this->rootTeam, $chefs->id);

    expect($a->id)->toBe($b->id)
        ->and(FoodAlchemistOrder::where('supplier_id', $chefs->id)->where('status', 'draft')->count())->toBe(1);
});

it('addNeedFromTarget: je Lieferant eine Schiene, Gebinde-Zeilen + total_net (echte Gebinde)', function () {
    $res = $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');

    expect($res['orders'])->toHaveCount(2)->and($res['skipped_ohne_la'])->toBe([]);

    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $hanos = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();

    // Chefs: Mehl 10 kg → 10 Sack ×2 € = 20 € + Zucker 1 kg → 1 Sack ×1 € = 1 € ⇒ 21,00 €.
    expect((float) $chefs->total_net)->toBe(21.0)
        ->and((float) $hanos->total_net)->toBe(12.0);   // Butter 1 kg → 1 Sack ×12 €

    $mehlLine = $chefs->lines()->where('gp_id', $this->mehl->id)->first();
    expect((float) $mehlLine->qty_packs)->toBe(10.0)
        ->and((float) $mehlLine->pack_price)->toBe(2.0)
        ->and((float) $mehlLine->line_total)->toBe(20.0)
        ->and($mehlLine->article_number)->toBe('ART-MEH')
        ->and((float) $mehlLine->needed_base_g)->toBe(10000.0);
});

it('E10: dieselbe Quelle erneut übernehmen ersetzt ihren Beitrag (verdoppelt NICHT)', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100'); // Re-Import gleiche Quelle

    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $mehlLine = $chefs->lines()->where('gp_id', $this->mehl->id)->first();

    expect((float) $mehlLine->needed_base_g)->toBe(10000.0)   // NICHT 20000
        ->and((float) $mehlLine->qty_packs)->toBe(10.0)
        ->and((float) $chefs->total_net)->toBe(21.0)
        ->and($chefs->lines()->count())->toBe(2);            // keine Dubletten-Zeilen
});

it('E10: zwei verschiedene Quellen akkumulieren am selben Artikel', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'event:sommerfest'); // andere Quelle

    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $mehlLine = $chefs->lines()->where('gp_id', $this->mehl->id)->first();

    // Mehl 10 kg + 10 kg = 20 kg → 20 Sack ×2 € = 40 €; Zucker 1+1=2 kg → 2 ×1 € = 2 € ⇒ 42 €.
    expect((float) $mehlLine->needed_base_g)->toBe(20000.0)
        ->and((float) $mehlLine->qty_packs)->toBe(20.0)
        ->and((float) $chefs->total_net)->toBe(42.0)
        ->and($mehlLine->source_contributions)->toHaveKeys(['recipe:kuchen@100', 'event:sommerfest']);
});

it('E3: Aufrundung auf dem Aggregat, nicht pro Quelle (2×0,4 kg = 1 Sack, nicht 2)', function () {
    $chefs = FoodAlchemistSupplier::where('name', 'Chefs')->first();
    $draft = $this->svc->draftForSupplier($this->rootTeam, $chefs->id);
    $line = FoodAlchemistOrderLine::create([
        'team_id' => $this->rootTeam->id, 'order_id' => $draft->id,
        'supplier_item_id' => $this->laOf['Mehl']->id, 'gp_id' => $this->mehl->id,
        'source_contributions' => ['A' => 400, 'B' => 400], // 0,4 kg + 0,4 kg = 0,8 kg
    ]);

    $this->svc->recomputeLine($line);

    // Aggregat 800 g ÷ 1000 g = 0,8 → 1 Sack. Pro Quelle gerundet wären es 2 gewesen.
    expect((float) $line->qty_packs)->toBe(1.0)
        ->and((float) $line->needed_base_g)->toBe(800.0)
        ->and((float) $line->line_total)->toBe(2.0);
});

it('Status-Guard: draft→sent ok (sent_at gesetzt), draft→confirmed verboten, delivered ist Endstation', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();

    expect(fn () => $this->svc->setStatus($this->rootTeam, $chefs->id, OrderStatus::Confirmed))
        ->toThrow(\RuntimeException::class);

    $sent = $this->svc->setStatus($this->rootTeam, $chefs->id, OrderStatus::Sent);
    expect($sent->status)->toBe(OrderStatus::Sent)->and($sent->sent_at)->not->toBeNull();

    $delivered = $this->svc->setStatus($this->rootTeam, $chefs->id, OrderStatus::Delivered);
    expect($delivered->status)->toBe(OrderStatus::Delivered)->and($delivered->delivered_at)->not->toBeNull();

    expect(fn () => $this->svc->setStatus($this->rootTeam, $chefs->id, OrderStatus::Cancelled))
        ->toThrow(\RuntimeException::class); // delivered = Endstation
});

it('E11/E2: Draft-Preis lebt, versendeter Beleg friert ein', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    expect((float) $chefs->total_net)->toBe(21.0);

    // Mehl-Preis steigt 2 → 3 €/kg (neue aktive Zeile).
    FoodAlchemistPrice::where('supplier_item_id', $this->laOf['Mehl']->id)->update(['price' => 3.00]);

    // Draft frischt auf (E11): Mehl 10 ×3 = 30 € + Zucker 1 = 31 €.
    $this->svc->recomputeOrder($chefs->refresh());
    expect((float) $chefs->refresh()->total_net)->toBe(31.0);

    // Versenden friert ein; weitere Preisänderung darf den Beleg nicht mehr bewegen (E2).
    $this->svc->setStatus($this->rootTeam, $chefs->id, OrderStatus::Sent);
    FoodAlchemistPrice::where('supplier_item_id', $this->laOf['Mehl']->id)->update(['price' => 99.00]);
    $this->svc->recomputeOrder($chefs->refresh());
    expect((float) $chefs->refresh()->total_net)->toBe(31.0); // unverändert
});

it('MOQ-Ampel: unter Mindestbestellwert + Frei-Haus-Grenze', function () {
    FoodAlchemistSupplier::where('name', 'Hanos')->update(['min_order_value' => 50.0, 'free_shipping_threshold' => 100.0]);
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $hanos = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();

    $ampel = $this->svc->moqAmpel($hanos);           // Butter 12 €
    expect($ampel['unter_mindestbestellwert'])->toBeTrue()
        ->and($ampel['fehlt_bis_min'])->toBe(38.0)
        ->and($ampel['frei_haus'])->toBeFalse()
        ->and($ampel['fehlt_bis_frei_haus'])->toBe(88.0);
});

it('MCP im Lockstep: orders.GET/ADD_NEED/SET_STATUS registriert + End-to-End', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($user, $this->rootTeam);

    foreach (['orders.GET' => true, 'orders.ADD_NEED' => false, 'orders.SET_STATUS' => false] as $t => $readonly) {
        $tool = $registry->get("foodalchemist.{$t}");
        expect($tool)->not->toBeNull()
            ->and($tool->getMetadata()['read_only'])->toBe($readonly);
    }

    // ADD_NEED (write): Bedarf übernehmen
    $add = $registry->get('foodalchemist.orders.ADD_NEED')
        ->execute(['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'recipe:kuchen@100'], $kontext);
    expect($add->success)->toBeTrue()->and($add->data['orders'])->toHaveCount(2);

    // GET Liste: 2 Entwürfe
    $list = $registry->get('foodalchemist.orders.GET')->execute([], $kontext);
    expect($list->success)->toBeTrue()->and($list->data['count'])->toBe(2);

    // GET Detail: Chefs = 21 € + MOQ-Ampel vorhanden
    $chefsId = collect($list->data['orders'])->firstWhere('supplier', 'Chefs')['id'];
    $detail = $registry->get('foodalchemist.orders.GET')->execute(['order_id' => $chefsId], $kontext);
    expect($detail->data['total_net'])->toBe(21.0)
        ->and($detail->data['moq'])->toHaveKey('unter_mindestbestellwert')
        ->and($detail->data['editierbar'])->toBeTrue();

    // SET_STATUS (write): versenden; danach nicht mehr editierbar
    $sent = $registry->get('foodalchemist.orders.SET_STATUS')->execute(['order_id' => $chefsId, 'status' => 'sent'], $kontext);
    expect($sent->success)->toBeTrue()->and($sent->data['status'])->toBe('sent');
    $detail2 = $registry->get('foodalchemist.orders.GET')->execute(['order_id' => $chefsId], $kontext);
    expect($detail2->data['editierbar'])->toBeFalse();

    // Illegaler Sprung (sent→draft gibt es nicht) → Guard
    $bad = $registry->get('foodalchemist.orders.SET_STATUS')->execute(['order_id' => $chefsId, 'status' => 'cancelled'], $kontext);
    expect($bad->success)->toBeTrue(); // sent→cancelled IST erlaubt
});

it('UI: „An Bestellung übergeben" im Produktionsauftrag legt Schienen an (idempotent bei Re-Klick, Spec 18)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $prod = app(\Platform\FoodAlchemist\Services\ProductionOrderService::class);
    $order = $prod->saveNew($this->rootTeam, '2026-08-01', 'Sommerfest', [
        ['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'recipe:kuchen@100'],
    ]);

    $comp = Livewire::test(\Platform\FoodAlchemist\Livewire\Produktion\DetailPanel::class, ['orderId' => $order->id])
        ->call('anBestellungUebergeben')
        ->assertSet('hinweis', fn ($v) => str_contains((string) $v, 'Bestellschiene'));

    expect(FoodAlchemistOrder::where('status', 'draft')->count())->toBe(2);

    // Erneuter Klick (gleiche Quelle) → keine Verdopplung (E10)
    $comp->call('anBestellungUebergeben');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    expect(FoodAlchemistOrder::where('status', 'draft')->count())->toBe(2)
        ->and((float) $chefs->total_net)->toBe(21.0);

    // Rückverknüpfung: der Produktionsauftrag findet die aus ihm erzeugten Bestellschienen
    // (über den source_ref-Präfix produktion:{id}: — es gibt keine FK).
    $verknuepft = $prod->verknuepfteOrders($this->rootTeam, $order->id);
    expect($verknuepft->pluck('supplier.name')->sort()->values()->all())->toBe(['Chefs', 'Hanos']);

    // Im DetailPanel sichtbar (der gemeldete Bug: Bestellung tauchte nicht auf).
    Livewire::test(\Platform\FoodAlchemist\Livewire\Produktion\DetailPanel::class, ['orderId' => $order->id])
        ->assertSee('Chefs')->assertSee('Hanos');
});

it('UI: Bestellungen-Seite listet Schienen, Detail + Absenden + manuelle Menge', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $mehlLine = $chefs->lines()->where('gp_id', $this->mehl->id)->first();

    // Browser listet die Schienen …
    Livewire::test(OrdersIndex::class)->assertSee('Chefs')->assertSee('Hanos');

    // … der Editor zeigt Detail + Absenden; manuelle Menge greift.
    $ed = Livewire::test(OrdersEditor::class)
        ->call('oeffnenBearbeiten', $chefs->id)
        ->assertSee('ART-MEH')          // Gebinde-Detail sichtbar
        ->assertSee('Absenden');

    // Manuelle Menge übersteuern: 10 → 15 Sack ×2 € = 30 € (+ Zucker 1 = 31 €)
    $ed->call('updateLineQty', $mehlLine->id, 15);
    expect((float) $chefs->refresh()->total_net)->toBe(31.0)
        ->and((bool) $mehlLine->refresh()->is_manual_qty)->toBeTrue();

    // Absenden → nicht mehr editierbar
    $ed->call('setStatus', 'sent');
    expect($chefs->refresh()->status->value)->toBe('sent');
    $ed->call('oeffnenBearbeiten', $chefs->id)->assertSee('eingefroren');
});

it('S3: dokument() + mailtoData() + Bestell-Dokument-Blade rendert', function () {
    FoodAlchemistSupplier::where('name', 'Chefs')->update(['email_order' => 'einkauf@chefs.test', 'city' => 'Köln']);
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();

    $dok = $this->svc->dokument($this->rootTeam, $chefs->id);
    expect($dok['zeilen'])->toHaveCount(2)
        ->and($dok['total_net'])->toBe(21.0)
        ->and($dok['lieferant']['email_order'])->toBe('einkauf@chefs.test');

    $html = view('foodalchemist::dokumente.bestellung', ['dok' => $dok, 'istPdf' => true])->render();
    expect($html)->toContain('Chefs')->toContain('ART-MEH')->toContain('Wareneinsatz netto')->toContain('21,00');

    $m = $this->svc->mailtoData($this->rootTeam, $chefs->id);
    expect($m['to'])->toBe('einkauf@chefs.test')
        ->and($m['subject'])->toContain('Chefs')
        ->and($m['body'])->toContain('Mehl')->toContain('Netto gesamt: 21,00 €');
});

it('S3: orders.UPDATE_LINE MCP — manuelle Menge + Zeile entfernen', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($user, $this->rootTeam);

    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $mehlLine = $chefs->lines()->where('gp_id', $this->mehl->id)->first();
    $zuckerLine = $chefs->lines()->where('gp_id', $this->zucker->id)->first();

    $tool = $registry->get('foodalchemist.orders.UPDATE_LINE');
    expect($tool)->not->toBeNull()->and($tool->getMetadata()['read_only'])->toBeFalse();

    $r = $tool->execute(['line_id' => $mehlLine->id, 'qty_packs' => 15], $kontext);
    expect($r->success)->toBeTrue()->and($r->data['is_manual_qty'])->toBeTrue()
        ->and((float) $chefs->refresh()->total_net)->toBe(31.0);   // 15×2 + 1

    $rm = $tool->execute(['line_id' => $zuckerLine->id, 'remove' => true], $kontext);
    expect($rm->success)->toBeTrue()->and($rm->data['removed'])->toBeTrue()
        ->and($chefs->refresh()->lines()->count())->toBe(1);
});

it('S3: Dokument-Route liefert HTML + CSV-Download', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();

    $this->get(route('foodalchemist.orders.dokument', ['order' => $chefs->id]))
        ->assertOk()->assertSee('Wareneinsatz netto');

    $csv = $this->get(route('foodalchemist.orders.dokument', ['order' => $chefs->id, 'csv' => 1]));
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->streamedContent())->toContain('Artikel-Nr')->toContain('ART-MEH');
})->skip(fn () => ! \Illuminate\Support\Facades\Route::has('foodalchemist.orders.dokument'), 'Modul-Route im Test-Harness nicht registriert');

it('removeLine + leere Quelle: Zeile verschwindet, total_net rechnet nach', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $zuckerLine = $chefs->lines()->where('gp_id', $this->zucker->id)->first();

    $this->svc->removeLine($this->rootTeam, $zuckerLine->id);

    expect($chefs->refresh()->lines()->count())->toBe(1)      // nur noch Mehl
        ->and((float) $chefs->total_net)->toBe(20.0);
});

// ── Spec 20 · E1 ──────────────────────────────────────────────────────────

it('E1: updateHeader pflegt Anlass/Liefertermin/Notiz im Draft; leerer String löscht', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();

    $this->svc->updateHeader($this->rootTeam, $chefs->id, [
        'reference' => 'Sommerfest', 'desired_delivery_date' => '2026-08-01', 'note' => 'vor 10 Uhr',
    ]);
    $chefs->refresh();
    expect($chefs->reference)->toBe('Sommerfest')
        ->and($chefs->desired_delivery_date?->toDateString())->toBe('2026-08-01')
        ->and($chefs->note)->toBe('vor 10 Uhr');

    // Leerer String löscht (nullt) das Feld.
    $this->svc->updateHeader($this->rootTeam, $chefs->id, ['reference' => '']);
    expect($chefs->refresh()->reference)->toBeNull();
});

it('E1: updateHeader-Guard — versendeter Beleg ist eingefroren', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $this->svc->setStatus($this->rootTeam, $chefs->id, OrderStatus::Sent);

    expect(fn () => $this->svc->updateHeader($this->rootTeam, $chefs->id, ['reference' => 'zu spät']))
        ->toThrow(\RuntimeException::class);
});

it('E1: Bedarf-Anzeige — kg-Artikel zeigt kg, Stück-Artikel zeigt Stk (nie mehr fälschlich „kg")', function () {
    // kg-Fall aus dem Bestand.
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $d = $this->svc->detail($this->rootTeam, $chefs->id);
    $mehl = collect($d['zeilen'])->firstWhere('gp_id', $this->mehl->id);
    expect($mehl['needed_unit'])->toBe('kg')->and($mehl['needed_display'])->toBe(10.0);   // 10 kg

    // Stück-Fall: GP mit Stückgewicht 250 g, LA in Stk-Gebinden; Bedarf 3000 g = 12 Stk.
    $sup = FoodAlchemistSupplier::where('name', 'Hanos')->first();
    $ei = $this->makeGp($this->rootTeam, 'Ei');
    $ei->update(['piece_default_g' => 250]);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id,
        'designation' => 'Eier 6er', 'article_number' => 'ART-EI', 'qty' => 6.0, 'unit_code' => 'Stk', 'packaging_unit' => 'Karton',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $ei->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 1.50, 'status' => '0']);
    $ei->update(['lead_la_supplier_item_id' => $la->id]);

    $hanos = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();
    $line = FoodAlchemistOrderLine::create([
        'team_id' => $this->rootTeam->id, 'order_id' => $hanos->id,
        'supplier_item_id' => $la->id, 'gp_id' => $ei->id,
        'source_contributions' => ['manual' => 3000], // 3000 g ÷ 250 g = 12 Stk
    ]);
    $this->svc->recomputeLine($line);

    $d2 = $this->svc->detail($this->rootTeam, $hanos->id);
    $eiZeile = collect($d2['zeilen'])->firstWhere('gp_id', $ei->id);
    expect($eiZeile['needed_unit'])->toBe('Stk')
        ->and($eiZeile['needed_display'])->toBe(12.0)
        ->and($eiZeile['unit_code'])->toBe('Stk');   // Gegenprobe: die Rohspalte ist Stk, Anzeige NICHT kg
});

it('E1: parseHerkunft + Detail-Herkunft — Produktion mit id/Link, concept/recipe erkannt', function () {
    $parsed = $this->svc->parseHerkunft([
        'produktion:7:recipe:kuchen@abc', 'concept:12@100p', 'recipe:kuchen@100', 'event:sommerfest', 'irgendwas',
    ]);
    $byType = collect($parsed)->keyBy('type');
    expect($byType['produktion']['production_order_id'])->toBe(7)
        ->and($byType['produktion']['label'])->toBe('Produktion #7')
        ->and($byType['concept']['label'])->toBe('Konzept 12')
        ->and($byType['recipe']['label'])->toBe('Gericht kuchen')
        ->and($byType['event']['label'])->toBe('sommerfest')
        ->and($byType['sonstige']['label'])->toBe('irgendwas');

    // Detail trägt die Herkunft pro Zeile + als dedupliziertes Schienen-Aggregat.
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $d = $this->svc->detail($this->rootTeam, $chefs->id);
    $mehl = collect($d['zeilen'])->firstWhere('gp_id', $this->mehl->id);
    expect($mehl['herkunft'][0]['type'])->toBe('recipe')
        ->and(collect($d['herkunft'])->pluck('type')->all())->toContain('recipe');
});

it('E1: MCP orders.UPDATE registriert + End-to-End (Kopf im Draft) + Guard nach send', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($user, $this->rootTeam);

    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();

    $tool = $registry->get('foodalchemist.orders.UPDATE');
    expect($tool)->not->toBeNull()->and($tool->getMetadata()['read_only'])->toBeFalse();

    $r = $tool->execute(['order_id' => $chefs->id, 'reference' => 'Gala', 'desired_delivery_date' => '2026-09-01'], $kontext);
    expect($r->success)->toBeTrue()
        ->and($r->data['reference'])->toBe('Gala')
        ->and($r->data['desired_delivery_date'])->toBe('2026-09-01');

    // Ohne änderbare Felder → NO_CHANGE
    $noop = $tool->execute(['order_id' => $chefs->id], $kontext);
    expect($noop->success)->toBeFalse()->and($noop->errorCode)->toBe('NO_CHANGE');

    // Nach send verboten
    $this->svc->setStatus($this->rootTeam, $chefs->id, OrderStatus::Sent);
    $blocked = $tool->execute(['order_id' => $chefs->id, 'reference' => 'zu spät'], $kontext);
    expect($blocked->success)->toBeFalse()->and($blocked->errorCode)->toBe('NOT_ALLOWED');
});

it('E1: UI — 3-Panel-Cockpit, Lieferant-Filter + Kopf speichern + Zeilen-Notiz', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $hanos = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();
    $mehlLine = $chefs->lines()->where('gp_id', $this->mehl->id)->first();

    $comp = Livewire::test(OrdersIndex::class)->assertSee('Chefs')->assertSee('Hanos');

    // Lieferant-Filter: nur die Chefs-Schiene in der Liste (Hanos-Zeile verschwindet).
    $comp->set('supplierFilter', $chefs->supplier_id)
        ->assertSee('ord-' . $chefs->id)
        ->assertDontSee('ord-' . $hanos->id);

    // Editor: Kopf-Form geladen; speichern persistiert + Zeilen-Notiz.
    $ed = Livewire::test(OrdersEditor::class)->call('oeffnenBearbeiten', $chefs->id)
        ->set('formReference', 'Betriebsfeier')
        ->set('formDeliveryDate', '2026-08-15')
        ->call('saveHeader')
        ->assertSet('hinweis', 'Kopf gespeichert.');
    expect($chefs->refresh()->reference)->toBe('Betriebsfeier');

    $ed->call('updateLineNote', $mehlLine->id, 'bio bevorzugt');
    expect($mehlLine->refresh()->note)->toBe('bio bevorzugt');
});

// ── Spec 20 · E2 (Direktbestellung) ─────────────────────────────────────────

it('E2: addManualLine legt Schiene an + manuelle Zeile mit Preis-Snapshot (needed_base_g=0)', function () {
    $chefs = FoodAlchemistSupplier::where('name', 'Chefs')->first();
    expect(FoodAlchemistOrder::where('supplier_id', $chefs->id)->count())->toBe(0);

    $line = $this->svc->addManualLine($this->rootTeam, $this->laOf['Mehl']->id, 3, 'ohne Ziel bestellt');

    $draft = FoodAlchemistOrder::where('supplier_id', $chefs->id)->where('status', 'draft')->first();
    expect($draft)->not->toBeNull()
        ->and((int) $line->order_id)->toBe((int) $draft->id)
        ->and((bool) $line->is_manual_qty)->toBeTrue()
        ->and((float) $line->qty_packs)->toBe(3.0)
        ->and((float) $line->needed_base_g)->toBe(0.0)          // kein Ziel-Bedarf
        ->and((float) $line->pack_price)->toBe(2.0)             // Snapshot aus aktivem Preis
        ->and((float) $line->line_total)->toBe(6.0)             // 3 × 2 €
        ->and($line->article_number)->toBe('ART-MEH')
        ->and($line->note)->toBe('ohne Ziel bestellt')
        ->and((float) $draft->refresh()->total_net)->toBe(6.0);

    // Erneuter Aufruf für denselben Artikel = Menge neu setzen (idempotent, keine Dublette).
    $this->svc->addManualLine($this->rootTeam, $this->laOf['Mehl']->id, 5);
    expect($draft->refresh()->lines()->count())->toBe(1)
        ->and((float) $draft->lines()->first()->qty_packs)->toBe(5.0)
        ->and((float) $draft->total_net)->toBe(10.0);
});

it('E2: manuelle Zeile übersteht Recompute + Bedarfs-Übernahme in dieselbe Schiene', function () {
    // Erst manuell 3 Sack Mehl, dann Ziel-Bedarf (10 kg = 10 Sack) in dieselbe Chefs-Schiene.
    $this->svc->addManualLine($this->rootTeam, $this->laOf['Mehl']->id, 3);
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');

    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $mehlLine = $chefs->lines()->where('gp_id', $this->mehl->id)->first();

    // Manuelle Menge bleibt bei 3 (übersteuert den 10-Sack-Bedarf); needed_base_g trägt den Bedarf.
    expect((bool) $mehlLine->is_manual_qty)->toBeTrue()
        ->and((float) $mehlLine->qty_packs)->toBe(3.0)
        ->and((float) $mehlLine->needed_base_g)->toBe(10000.0)
        ->and($mehlLine->source_contributions)->toHaveKey('recipe:kuchen@100')
        ->and($chefs->lines()->count())->toBe(2);   // Mehl (manuell+Bedarf) + Zucker (nur Bedarf)

    // Chefs: Mehl 3 × 2 € = 6 € + Zucker 1 Sack × 1 € = 1 € ⇒ 7,00 €.
    expect((float) $chefs->refresh()->total_net)->toBe(7.0);

    // Bedarf-Anzeige: 10 kg trotz manueller 3 Sack.
    $d = $this->svc->detail($this->rootTeam, $chefs->id);
    $mehl = collect($d['zeilen'])->firstWhere('gp_id', $this->mehl->id);
    expect($mehl['needed_display'])->toBe(10.0)->and($mehl['needed_unit'])->toBe('kg');
});

it('E2: manuelle Zeile — Draft-Preis lebt, versendeter Beleg friert ein', function () {
    $this->svc->addManualLine($this->rootTeam, $this->laOf['Butter']->id, 2);   // Hanos, 12 €/kg
    $hanos = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();
    expect((float) $hanos->total_net)->toBe(24.0);                              // 2 × 12 €

    // Preis 12 → 15 €/kg: Draft frischt auf (E11).
    FoodAlchemistPrice::where('supplier_item_id', $this->laOf['Butter']->id)->update(['price' => 15.00]);
    $this->svc->recomputeOrder($hanos->refresh());
    expect((float) $hanos->refresh()->total_net)->toBe(30.0);                   // 2 × 15 €

    // Versenden friert ein; weitere Preisänderung bewegt den Beleg nicht mehr (E2).
    $this->svc->setStatus($this->rootTeam, $hanos->id, OrderStatus::Sent);
    FoodAlchemistPrice::where('supplier_item_id', $this->laOf['Butter']->id)->update(['price' => 99.00]);
    $this->svc->recomputeOrder($hanos->refresh());
    expect((float) $hanos->refresh()->total_net)->toBe(30.0);                   // unverändert
});

it('E2: createDraft — findOrCreate je Lieferant + optionale Kopf-Felder', function () {
    $chefs = FoodAlchemistSupplier::where('name', 'Chefs')->first();

    $a = $this->svc->createDraft($this->rootTeam, $chefs->id, ['reference' => 'Sommerfest']);
    expect($a->status->value ?? (string) $a->status)->toBe('draft')
        ->and($a->reference)->toBe('Sommerfest');

    // Zweiter Aufruf gibt dieselbe offene Schiene zurück (keine zweite Draft-Schiene).
    $b = $this->svc->createDraft($this->rootTeam, $chefs->id);
    expect((int) $b->id)->toBe((int) $a->id)
        ->and(FoodAlchemistOrder::where('supplier_id', $chefs->id)->where('status', 'draft')->count())->toBe(1);

    // Unbekannter/nicht sichtbarer Lieferant → Fehler.
    expect(fn () => $this->svc->createDraft($this->rootTeam, 999999))
        ->toThrow(\RuntimeException::class);
});

it('E2: MCP im Lockstep — orders.CREATE + orders.ADD_LINE registriert + End-to-End', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($user, $this->rootTeam);

    foreach (['orders.CREATE', 'orders.ADD_LINE'] as $t) {
        $tool = $registry->get("foodalchemist.{$t}");
        expect($tool)->not->toBeNull()
            ->and($tool->getMetadata()['read_only'])->toBeFalse();
    }

    $chefs = FoodAlchemistSupplier::where('name', 'Chefs')->first();

    // CREATE: leere Schiene für Chefs anlegen
    $create = $registry->get('foodalchemist.orders.CREATE')
        ->execute(['supplier_id' => $chefs->id, 'reference' => 'Direktkauf'], $kontext);
    expect($create->success)->toBeTrue()
        ->and($create->data['status'])->toBe('draft')
        ->and($create->data['reference'])->toBe('Direktkauf');
    $orderId = $create->data['order_id'];

    // ADD_LINE: manuell 4 Sack Mehl an dieselbe Schiene
    $add = $registry->get('foodalchemist.orders.ADD_LINE')
        ->execute(['supplier_item_id' => $this->laOf['Mehl']->id, 'qty_packs' => 4, 'note' => 'bio'], $kontext);
    expect($add->success)->toBeTrue()
        ->and((int) $add->data['order_id'])->toBe((int) $orderId)   // fällt in die CREATE-Schiene
        ->and($add->data['is_manual_qty'])->toBeTrue()
        ->and((float) $add->data['qty_packs'])->toBe(4.0)
        ->and((float) $add->data['line_total'])->toBe(8.0)          // 4 × 2 €
        ->and($add->data['note'])->toBe('bio');

    // GET Detail: die manuelle Zeile ist sichtbar + total_net stimmt
    $detail = $registry->get('foodalchemist.orders.GET')->execute(['order_id' => $orderId], $kontext);
    expect($detail->data['total_net'])->toBe(8.0)
        ->and(collect($detail->data['zeilen'])->firstWhere('supplier_item_id', $this->laOf['Mehl']->id)['is_manual_qty'])->toBeTrue();

    // ADD_LINE auf unbekannten Artikel → sauberer Fehler
    $bad = $registry->get('foodalchemist.orders.ADD_LINE')
        ->execute(['supplier_item_id' => 999999, 'qty_packs' => 1], $kontext);
    expect($bad->success)->toBeFalse();
});

// ── Spec 20 · E2 UI (Direktbestellung im Einkauf-Cockpit) ───────────────────

it('E2 UI: „Neue Bestellung" legt leere Draft-Schiene an + wählt sie', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $chefs = FoodAlchemistSupplier::where('name', 'Chefs')->first();
    expect(FoodAlchemistOrder::where('supplier_id', $chefs->id)->count())->toBe(0);

    Livewire::test(OrdersIndex::class)
        ->set('neuerLieferant', $chefs->id)
        ->call('neueBestellung')
        ->assertDispatched('orders-editor.bearbeiten')   // öffnet den Editor mit dem neuen Draft
        ->assertSet('neuerLieferant', null);

    expect(FoodAlchemistOrder::where('supplier_id', $chefs->id)->where('status', 'draft')->count())->toBe(1);
});

it('E2 UI: „Neue Bestellung" ohne Lieferant → Fehlerhinweis, keine Schiene', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    Livewire::test(OrdersIndex::class)
        ->call('neueBestellung')
        ->assertSet('fehler', fn ($v) => str_contains((string) $v, 'Lieferant'));
    expect(FoodAlchemistOrder::count())->toBe(0);
});

it('E2 UI: „＋ Artikel"-Livesearch findet Artikel + hängt manuelle Zeile an dessen Schiene', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $chefs = FoodAlchemistSupplier::where('name', 'Chefs')->first();
    $offen = $this->svc->createDraft($this->rootTeam, $chefs->id, [], null);   // Editor braucht einen offenen Beleg

    $comp = Livewire::test(OrdersEditor::class)
        ->call('oeffnenBearbeiten', $offen->id)
        ->set('artikelSuche', 'mehl')
        ->assertSee('Mehl 1kg');           // Livesearch-Dropdown im Hinzufügen-Tab

    $comp->call('artikelHinzufuegen', $this->laOf['Mehl']->id)
        ->assertSet('hinweis', 'Artikel hinzugefügt.')
        ->assertSet('artikelSuche', '')
        ->assertSet('orderId', fn ($v) => $v !== null);

    $draft = FoodAlchemistOrder::where('supplier_id', $chefs->id)->where('status', 'draft')->first();
    $line = $draft->lines()->where('supplier_item_id', $this->laOf['Mehl']->id)->first();
    expect($line)->not->toBeNull()
        ->and((bool) $line->is_manual_qty)->toBeTrue()
        ->and((float) $line->qty_packs)->toBe(1.0);
});

it('E2 UI: Bedarf-Schnellerfassung VK-Gericht (Portionen) → Schienen befüllt', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $offen = $this->svc->createDraft($this->rootTeam, FoodAlchemistSupplier::where('name', 'Chefs')->first()->id, [], null);
    $comp = Livewire::test(OrdersEditor::class)
        ->call('oeffnenBearbeiten', $offen->id)
        ->set('bedarfSuche', 'Kuchen')
        ->assertSee('DES: Kuchen');

    $comp->call('bedarfRezeptWaehlen', $this->kuchen->id)
        ->assertSet('bedarfRecipeVk', true)
        ->assertSet('bedarfEinheit', 'portions')
        ->set('bedarfMenge', '100')
        ->call('bedarfUebernehmen')
        ->assertSet('hinweis', fn ($v) => str_contains((string) $v, 'Bedarf übernommen'))
        ->assertSet('bedarfRecipeId', null)          // reset nach Übernahme
        ->assertSet('orderId', fn ($v) => $v !== null);

    // Kuchen 100 Port. → Chefs (Mehl+Zucker) + Hanos (Butter) = 2 Schienen
    expect(FoodAlchemistOrder::where('status', 'draft')->count())->toBe(2);
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    expect($chefs->lines()->where('gp_id', $this->mehl->id)->exists())->toBeTrue();
});

it('E2 UI: Bedarf-Schnellerfassung Basisrezept in kg → amount_kg-Ziel', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(OrdersEditor::class)
        ->call('bedarfRezeptWaehlen', $this->sauce->id)
        ->assertSet('bedarfRecipeVk', false)         // Basisrezept
        ->assertSet('bedarfEinheit', 'ansaetze')     // Default Basis
        ->set('bedarfEinheit', 'kg')
        ->set('bedarfMenge', '2')                    // 2 kg Vanillesauce
        ->call('bedarfUebernehmen')
        ->assertSet('hinweis', fn ($v) => str_contains((string) $v, 'Bedarf übernommen'));

    // Vanillesauce (yield 1 kg) 2 kg = 2 Ansätze → Zucker 1 kg (Chefs) + Butter 1 kg (Hanos)
    expect(FoodAlchemistOrder::where('status', 'draft')->count())->toBe(2);
});

it('E2 UI: Bedarf-Schnellerfassung ohne Menge → Fehler, keine Schiene', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    Livewire::test(OrdersEditor::class)
        ->call('bedarfRezeptWaehlen', $this->kuchen->id)
        ->call('bedarfUebernehmen')
        ->assertSet('fehler', fn ($v) => str_contains((string) $v, 'Menge'));
    expect(FoodAlchemistOrder::count())->toBe(0);
});

/**
 * Spec 20 · E3 — Preisstrategie-Switch + „Neu quellen".
 * Zusatz-Fixture: GP „Oel" mit ZWEI LAs an ZWEI Lieferanten (Chefs 2 €, Hanos 9 €, je
 * 1-kg-Gebinde) + VK-Gericht „Salat" (1 Portion = 1000 g Öl) — so wechselt eine Strategie
 * den Lieferanten nachweisbar.
 */
describe('E3 · Preisstrategie-Switch + Neu quellen', function () {
    beforeEach(function () {
        $chefs = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
        $hanos = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
        $this->oel = $this->makeGp($this->rootTeam, 'Oel');

        $mkLa = function ($supplier, float $preis) {
            $la = FoodAlchemistSupplierItem::create([
                'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
                'designation' => 'Oel 1kg ' . $supplier->name, 'article_number' => 'OEL-' . strtoupper(substr($supplier->name, 0, 3)),
                'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Kanister',
            ]);
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->oel->id]);
            FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);

            return $la;
        };
        $this->chefs = $chefs;
        $this->hanos = $hanos;
        $this->oelChefs = $mkLa($chefs, 2.00);
        $this->oelHanos = $mkLa($hanos, 9.00);

        $this->salat = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => 'salat', 'name' => 'VOR: Salat',
            'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 4.0, 'sales_unit_count' => 1,
        ]);
        $this->salat->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->oel->id, 'raw_text' => 'Öl', 'quantity' => 1000, 'unit_vocab_id' => $this->g->id]);
        app(RecipeRecomputeService::class)->recomputePipeline($this->salat->id);

        $this->settings = app(TeamSettingsService::class);
        $this->salatZiel = ['recipe_id' => $this->salat->id, 'portions' => 1];
    });

    it('Strategie-Override in bestellvorschlag wechselt den Lead-Lieferanten deterministisch', function () {
        // Prioritäts-Kette Hanos zuerst (nur relevant für die Prioritäts-Strategie).
        $this->settings->update($this->rootTeam, ['lead_la_prioritaeten' => [$this->hanos->id]]);
        $planung = app(PlanungsblattService::class);

        $guenstig = $planung->bestellvorschlag($this->rootTeam, $this->salatZiel, LeadLaStrategie::GuenstigsterPreis);
        $prio = $planung->bestellvorschlag($this->rootTeam, $this->salatZiel, LeadLaStrategie::PrioritaetsKette);

        expect($guenstig['lieferanten'][0]['lieferant'])->toBe('Chefs')      // 2 € < 9 €
            ->and($prio['lieferanten'][0]['lieferant'])->toBe('Hanos');      // Ketten-Position schlägt Preis
    });

    it('resourceOrder: Strategie-Switch verschiebt Contributions in die andere Lieferanten-Schiene', function () {
        // Start: Prioritäts-Kette Hanos ⇒ Übernahme landet bei Hanos.
        $this->settings->update($this->rootTeam, ['lead_la_strategie' => LeadLaStrategie::PrioritaetsKette, 'lead_la_prioritaeten' => [$this->hanos->id]]);
        $this->svc->addNeedFromTarget($this->rootTeam, $this->salatZiel, 'recipe:salat@1');

        $hanosOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();
        expect($hanosOrder->lines()->where('gp_id', $this->oel->id)->first()->supplier_item_id)->toBe($this->oelHanos->id);

        // Neu quellen auf „günstigster Preis" ⇒ Öl wandert nach Chefs.
        $report = $this->svc->resourceOrder($this->rootTeam, $hanosOrder->id, LeadLaStrategie::GuenstigsterPreis);

        $chefsOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->where('status', 'draft')->first();
        $chefsLine = $chefsOrder->lines()->where('gp_id', $this->oel->id)->first();

        expect($report['strategie'])->toBe('guenstigster_preis')
            ->and($report['wechsel'])->toHaveCount(1)
            ->and($report['wechsel'][0]['schiene_wechsel'])->toBeTrue()
            ->and($report['wechsel'][0]['nach_lieferant'])->toBe('Chefs')
            ->and((int) $chefsLine->supplier_item_id)->toBe($this->oelChefs->id)
            ->and((float) $chefsLine->needed_base_g)->toBe(1000.0)
            ->and((float) $chefsLine->pack_price)->toBe(2.0)
            ->and((float) $chefsOrder->total_net)->toBe(2.0)
            // Quell-Schiene: Öl-Zeile ist weg (Beitrag verschoben, nicht dupliziert).
            ->and($hanosOrder->refresh()->lines()->where('gp_id', $this->oel->id)->count())->toBe(0)
            ->and($hanosOrder->sourcing_strategy)->toBe('guenstigster_preis');
    });

    it('resourceOrder: gleicher Lieferant, anderer Artikel — nur der LA der Zeile wechselt (keine Schiene)', function () {
        // Zweiter Chefs-LA für Öl (teurer), damit ein Wechsel innerhalb Chefs möglich ist.
        $oelChefs2 = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->chefs->id,
            'designation' => 'Oel 1kg Chefs Bio', 'article_number' => 'OEL-CHB',
            'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Kanister',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $oelChefs2->id, 'gp_id' => $this->oel->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $oelChefs2->id, 'price' => 5.00, 'status' => '0']);

        // günstigster Preis ⇒ Übernahme bei Chefs auf den 2-€-LA.
        $this->settings->update($this->rootTeam, ['lead_la_strategie' => LeadLaStrategie::GuenstigsterPreis]);
        $this->svc->addNeedFromTarget($this->rootTeam, $this->salatZiel, 'recipe:salat@1');
        $chefsOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
        expect($chefsOrder->lines()->where('gp_id', $this->oel->id)->first()->supplier_item_id)->toBe($this->oelChefs->id);

        // Günstigsten Chefs-LA sperren ⇒ effektiver Lead = teurerer Chefs-LA (gleicher Lieferant).
        app(LeadLaService::class)->sperren($this->rootTeam, $this->oel, $this->oelChefs->id, true);
        $report = $this->svc->resourceOrder($this->rootTeam, $chefsOrder->id, LeadLaStrategie::GuenstigsterPreis);

        $line = $chefsOrder->refresh()->lines()->where('gp_id', $this->oel->id)->first();
        expect($report['wechsel'])->toHaveCount(1)
            ->and($report['wechsel'][0]['schiene_wechsel'])->toBeFalse()
            ->and($report['orders'])->toBe([$chefsOrder->id])   // keine zweite Schiene
            ->and((int) $line->supplier_item_id)->toBe($oelChefs2->id)
            ->and((float) $line->pack_price)->toBe(5.0);
    });

    it('resourceOrder: manuelle Zeilen bleiben beim Neu-quellen unangetastet', function () {
        // Extra Hanos-Artikel (kein GP) für eine manuelle Zeile.
        $pfeffer = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->hanos->id,
            'designation' => 'Pfeffer schwarz 100g', 'article_number' => 'PFE-100',
            'qty' => 0.1, 'unit_code' => 'kg', 'packaging_unit' => 'Dose',
        ]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $pfeffer->id, 'price' => 3.00, 'status' => '0']);

        $this->settings->update($this->rootTeam, ['lead_la_strategie' => LeadLaStrategie::PrioritaetsKette, 'lead_la_prioritaeten' => [$this->hanos->id]]);
        $this->svc->addNeedFromTarget($this->rootTeam, $this->salatZiel, 'recipe:salat@1');   // Öl → Hanos
        $this->svc->addManualLine($this->rootTeam, $pfeffer->id, 2.0, 'Direktkauf');          // manuelle Zeile in Hanos-Schiene

        $hanosOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();
        $this->svc->resourceOrder($this->rootTeam, $hanosOrder->id, LeadLaStrategie::GuenstigsterPreis);   // Öl → Chefs

        $manuell = $hanosOrder->refresh()->lines()->where('supplier_item_id', $pfeffer->id)->first();
        expect($manuell)->not->toBeNull()
            ->and((bool) $manuell->is_manual_qty)->toBeTrue()
            ->and((float) $manuell->qty_packs)->toBe(2.0)
            ->and($hanosOrder->lines()->where('gp_id', $this->oel->id)->count())->toBe(0);   // Öl-Bedarf ist gewandert
    });

    it('resourceOrder: gesendeter Beleg ist eingefroren — kein Neu-quellen', function () {
        $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
        $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
        $this->svc->setStatus($this->rootTeam, $chefs->id, OrderStatus::Sent);

        expect(fn () => $this->svc->resourceOrder($this->rootTeam, $chefs->id, LeadLaStrategie::GuenstigsterPreis))
            ->toThrow(\RuntimeException::class);
    });

    it('MCP orders.RESOURCE: preview zeigt Wechsel ohne Persistenz, apply verschiebt', function () {
        $user = $this->makeUser($this->rootTeam);
        $this->actingAs($user);
        $registry = app(ToolRegistry::class);
        $kontext = new ToolContext($user, $this->rootTeam);

        $this->settings->update($this->rootTeam, ['lead_la_strategie' => LeadLaStrategie::PrioritaetsKette, 'lead_la_prioritaeten' => [$this->hanos->id]]);
        $this->svc->addNeedFromTarget($this->rootTeam, $this->salatZiel, 'recipe:salat@1');   // Öl → Hanos
        $hanosOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();

        // Vorschau: Wechsel wird gemeldet, aber nichts verschoben.
        $preview = $registry->get('foodalchemist.orders.RESOURCE')
            ->execute(['order_id' => $hanosOrder->id, 'strategy' => 'guenstigster_preis', 'preview' => true], $kontext);
        expect($preview->success)->toBeTrue()
            ->and($preview->data['applied'])->toBeFalse()
            ->and($preview->data['wechsel'])->toHaveCount(1)
            ->and($hanosOrder->refresh()->lines()->where('gp_id', $this->oel->id)->count())->toBe(1);   // noch da

        // Anwenden: jetzt wandert Öl nach Chefs.
        $applied = $registry->get('foodalchemist.orders.RESOURCE')
            ->execute(['order_id' => $hanosOrder->id, 'strategy' => 'guenstigster_preis'], $kontext);
        $chefsOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->where('status', 'draft')->first();

        expect($applied->data['applied'])->toBeTrue()
            ->and($hanosOrder->refresh()->lines()->where('gp_id', $this->oel->id)->count())->toBe(0)
            ->and($chefsOrder->lines()->where('gp_id', $this->oel->id)->first()->supplier_item_id)->toBe($this->oelChefs->id);
    });

    // ── E3b · Alternativ-Artikel je Zeile + Neu-quellen-UI ──

    it('lineAlternativen: Rangliste ohne den aktuellen LA, mit Vergleichspreis + Schienen-Wechsel-Flag', function () {
        $this->settings->update($this->rootTeam, ['lead_la_strategie' => LeadLaStrategie::GuenstigsterPreis]);
        $this->svc->addNeedFromTarget($this->rootTeam, $this->salatZiel, 'recipe:salat@1');   // Öl → Chefs (2 €)
        $chefsOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
        $line = $chefsOrder->lines()->where('gp_id', $this->oel->id)->first();
        expect((int) $line->supplier_item_id)->toBe($this->oelChefs->id);

        $alt = $this->svc->lineAlternativen($this->rootTeam, $line->id);
        // Der aktuell gewählte Chefs-LA fällt raus, nur der Hanos-LA bleibt als Ausweichquelle.
        expect($alt)->toHaveCount(1)
            ->and($alt[0]['la_id'])->toBe($this->oelHanos->id)
            ->and($alt[0]['supplier'])->toBe('Hanos')
            ->and($alt[0]['schiene_wechsel'])->toBeTrue()
            ->and($alt[0]['vergleichspreis'])->toBe(9.0);
    });

    it('switchLineArticle: anderer Lieferant → Zeile wandert in dessen Draft-Schiene', function () {
        $this->settings->update($this->rootTeam, ['lead_la_strategie' => LeadLaStrategie::GuenstigsterPreis]);
        $this->svc->addNeedFromTarget($this->rootTeam, $this->salatZiel, 'recipe:salat@1');   // Öl → Chefs
        $chefsOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
        $line = $chefsOrder->lines()->where('gp_id', $this->oel->id)->first();

        $res = $this->svc->switchLineArticle($this->rootTeam, $line->id, $this->oelHanos->id);

        $hanosOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->where('status', 'draft')->first();
        $hanosLine = $hanosOrder->lines()->where('gp_id', $this->oel->id)->first();
        expect($res['schiene_wechsel'])->toBeTrue()
            ->and($res['target_order_id'])->toBe($hanosOrder->id)
            ->and((int) $hanosLine->supplier_item_id)->toBe($this->oelHanos->id)
            ->and((float) $hanosLine->needed_base_g)->toBe(1000.0)
            ->and((float) $hanosLine->pack_price)->toBe(9.0)
            // Quell-Schiene: Öl-Zeile ist weg (Beitrag verschoben, nicht dupliziert).
            ->and($chefsOrder->refresh()->lines()->where('gp_id', $this->oel->id)->count())->toBe(0);
    });

    it('switchLineArticle: gleicher Lieferant → nur der Artikel der Zeile wechselt (keine Schiene)', function () {
        $oelChefs2 = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->chefs->id,
            'designation' => 'Oel 1kg Chefs Bio', 'article_number' => 'OEL-CHB',
            'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Kanister',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $oelChefs2->id, 'gp_id' => $this->oel->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $oelChefs2->id, 'price' => 5.00, 'status' => '0']);

        $this->settings->update($this->rootTeam, ['lead_la_strategie' => LeadLaStrategie::GuenstigsterPreis]);
        $this->svc->addNeedFromTarget($this->rootTeam, $this->salatZiel, 'recipe:salat@1');   // Öl → Chefs (2-€-LA)
        $chefsOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
        $line = $chefsOrder->lines()->where('gp_id', $this->oel->id)->first();

        $res = $this->svc->switchLineArticle($this->rootTeam, $line->id, $oelChefs2->id);

        $line->refresh();
        expect($res['schiene_wechsel'])->toBeFalse()
            ->and($res['target_order_id'])->toBeNull()
            ->and((int) $line->supplier_item_id)->toBe($oelChefs2->id)
            ->and((float) $line->pack_price)->toBe(5.0)
            ->and($chefsOrder->refresh()->lines()->where('gp_id', $this->oel->id)->count())->toBe(1);   // keine zweite Schiene
    });

    it('switchLineArticle: LA einer fremden GP wird abgelehnt', function () {
        $this->settings->update($this->rootTeam, ['lead_la_strategie' => LeadLaStrategie::GuenstigsterPreis]);
        $this->svc->addNeedFromTarget($this->rootTeam, $this->salatZiel, 'recipe:salat@1');
        $line = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first()
            ->lines()->where('gp_id', $this->oel->id)->first();

        // Ein LA ohne Struktur-Verknüpfung zur Öl-GP ist keine Ausweichquelle.
        $fremd = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->chefs->id,
            'designation' => 'Salz 1kg', 'article_number' => 'SAL-1', 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack',
        ]);
        expect(fn () => $this->svc->switchLineArticle($this->rootTeam, $line->id, $fremd->id))
            ->toThrow(\RuntimeException::class);
    });

    it('E3b UI: Preisstrategie-Select + Neu quellen (Vorschau → Anwenden) verschiebt die Schiene', function () {
        $this->actingAs($this->makeUser($this->rootTeam));
        $this->settings->update($this->rootTeam, ['lead_la_strategie' => LeadLaStrategie::PrioritaetsKette, 'lead_la_prioritaeten' => [$this->hanos->id]]);
        $this->svc->addNeedFromTarget($this->rootTeam, $this->salatZiel, 'recipe:salat@1');   // Öl → Hanos
        $hanosOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();

        $comp = Livewire::test(OrdersEditor::class)
            ->call('oeffnenBearbeiten', $hanosOrder->id)
            ->set('formStrategy', 'guenstigster_preis')
            ->call('neuQuellenVorschau')
            ->assertSet('resourceVorschau', fn ($v) => is_array($v) && count($v['wechsel']) === 1);
        // Vorschau persistiert nichts.
        expect($hanosOrder->refresh()->lines()->where('gp_id', $this->oel->id)->count())->toBe(1);

        $comp->call('neuQuellenAnwenden')
            ->assertSet('hinweis', 'Neu gequellt.')
            ->assertSet('resourceVorschau', null);

        $chefsOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->where('status', 'draft')->first();
        expect($chefsOrder->lines()->where('gp_id', $this->oel->id)->count())->toBe(1)
            ->and($hanosOrder->refresh()->lines()->where('gp_id', $this->oel->id)->count())->toBe(0);
    });

    it('E3b UI: alternativeWaehlen stellt die Zeile auf den Ausweich-LA um + folgt in die neue Schiene', function () {
        $this->actingAs($this->makeUser($this->rootTeam));
        $this->settings->update($this->rootTeam, ['lead_la_strategie' => LeadLaStrategie::GuenstigsterPreis]);
        $this->svc->addNeedFromTarget($this->rootTeam, $this->salatZiel, 'recipe:salat@1');   // Öl → Chefs
        $chefsOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
        $line = $chefsOrder->lines()->where('gp_id', $this->oel->id)->first();

        Livewire::test(OrdersEditor::class)
            ->call('oeffnenBearbeiten', $chefsOrder->id)
            ->call('alternativenUmschalten', $line->id)
            ->assertSet('altLineId', $line->id)
            ->call('alternativeWaehlen', $line->id, $this->oelHanos->id)
            ->assertSet('hinweis', 'Artikel umgestellt.')
            ->assertSet('altLineId', null);

        $hanosOrder = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->where('status', 'draft')->first();
        expect($hanosOrder->lines()->where('gp_id', $this->oel->id)->count())->toBe(1)
            ->and($chefsOrder->refresh()->lines()->where('gp_id', $this->oel->id)->count())->toBe(0);
    });
});
