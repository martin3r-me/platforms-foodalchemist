<?php

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
use Platform\FoodAlchemist\Livewire\Blaetter\Index as BlaetterIndex;
use Platform\FoodAlchemist\Livewire\Orders\Index as OrdersIndex;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
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

it('UI: „Bedarf übernehmen" im Planungsblatt legt Schienen an (idempotent bei Re-Klick)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $comp = Livewire::test(BlaetterIndex::class)
        ->set('zielTyp', 'recipe')
        ->call('waehleGericht', $this->kuchen->id)
        ->set('menge', 100)
        ->call('bedarfUebernehmen')
        ->assertSet('uebernahmeHinweis', fn ($v) => str_contains((string) $v, 'Bestellschiene'));

    expect(FoodAlchemistOrder::where('status', 'draft')->count())->toBe(2);

    // Erneuter Klick (gleiche Quelle) → keine Verdopplung (E10)
    $comp->call('bedarfUebernehmen');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    expect(FoodAlchemistOrder::where('status', 'draft')->count())->toBe(2)
        ->and((float) $chefs->total_net)->toBe(21.0);
});

it('UI: Bestellungen-Seite listet Schienen, Detail + Absenden + manuelle Menge', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $mehlLine = $chefs->lines()->where('gp_id', $this->mehl->id)->first();

    $comp = Livewire::test(OrdersIndex::class)
        ->assertSee('Chefs')->assertSee('Hanos')
        ->call('select', $chefs->id)
        ->assertSee('ART-MEH')          // Gebinde-Detail sichtbar
        ->assertSee('Absenden');

    // Manuelle Menge übersteuern: 10 → 15 Sack ×2 € = 30 € (+ Zucker 1 = 31 €)
    $comp->call('updateLineQty', $mehlLine->id, 15);
    expect((float) $chefs->refresh()->total_net)->toBe(31.0)
        ->and((bool) $mehlLine->refresh()->is_manual_qty)->toBeTrue();

    // Absenden → nicht mehr editierbar
    $comp->call('setStatus', 'sent');
    expect($chefs->refresh()->status->value)->toBe('sent');
    $comp->call('select', $chefs->id)->assertSee('eingefroren');
});

it('removeLine + leere Quelle: Zeile verschwindet, total_net rechnet nach', function () {
    $this->svc->addNeedFromTarget($this->rootTeam, $this->ziel, 'recipe:kuchen@100');
    $chefs = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Chefs'))->first();
    $zuckerLine = $chefs->lines()->where('gp_id', $this->zucker->id)->first();

    $this->svc->removeLine($this->rootTeam, $zuckerLine->id);

    expect($chefs->refresh()->lines()->count())->toBe(1)      // nur noch Mehl
        ->and((float) $chefs->total_net)->toBe(20.0);
});
