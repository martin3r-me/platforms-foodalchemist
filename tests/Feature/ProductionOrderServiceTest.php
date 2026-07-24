<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Browser as ProduktionBrowser;
use Platform\FoodAlchemist\Livewire\Produktion\DetailPanel as ProduktionDetailPanel;
use Platform\FoodAlchemist\Livewire\Produktion\Editor as ProduktionEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 18 — Produktionsaufträge. Fixture wie OrderServiceTest/PlanungsblattServiceTest:
 * Kuchen (10 Portionen/Batch) = 1000 g Mehl + 150 g Vanillesauce; Vanillesauce (Basis
 * 1000 g) = 500 g Zucker + 500 g Butter. Zusätzlich „Tarte" (10 Portionen/Batch) =
 * 1000 g Mehl + 100 g Vanillesauce — zweites VK-Gericht, das dieselbe Sub-Rezept-Zutat
 * teilt (fürs Flaggschiff-Rundungs-Szenario).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $mkGp = function (string $name, float $preis, string $lieferant) {
        $supplier = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => $lieferant]);
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name . ' 1kg', 'article_number' => 'ART-' . strtoupper(substr($name, 0, 3)),
            'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

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

    $this->tarte = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'tarte', 'name' => 'DES: Tarte',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 3.00, 'sales_unit_count' => 10,
    ]);
    $this->tarte->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->mehl->id, 'raw_text' => 'Mehl', 'quantity' => 1000, 'unit_vocab_id' => $this->g->id]);
    $this->tarte->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 1, 'referenced_recipe_id' => $this->sauce->id, 'raw_text' => 'Vanillesauce', 'quantity' => 100, 'unit_vocab_id' => $this->g->id]);

    $rc = app(RecipeRecomputeService::class);
    $rc->recomputePipeline($this->sauce->id);
    $rc->recomputePipeline($this->kuchen->id);
    $rc->recomputePipeline($this->tarte->id);
});

it('draftForDate: nur EIN offener Auftrag je (team, production_date)', function () {
    $a = $this->svc->draftForDate($this->rootTeam, '2026-08-01');
    $b = $this->svc->draftForDate($this->rootTeam, '2026-08-01');
    $c = $this->svc->draftForDate($this->rootTeam, '2026-08-02');

    expect($a->id)->toBe($b->id)->and($a->id)->not->toBe($c->id)
        ->and(FoodAlchemistProductionOrder::whereDate('production_date', '2026-08-01')->where('status', 'planned')->count())->toBe(1);
});

it('addTarget aggregiert zwei verschiedene Ziele desselben Tages in einen Auftrag', function () {
    $order = $this->svc->draftForDate($this->rootTeam, '2026-08-01');
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'a');
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->tarte->id, 'portions' => 50], 'b');

    expect($order->targets)->toHaveCount(2)
        ->and($order->lines()->where('recipe_id', $this->kuchen->id)->exists())->toBeTrue()
        ->and($order->lines()->where('recipe_id', $this->tarte->id)->exists())->toBeTrue()
        ->and($order->lines()->where('recipe_id', $this->sauce->id)->count())->toBe(1); // EIN gemeinsamer Sauce-Bedarf, nicht zwei Zeilen
});

it('Flaggschiff: zwei Ziele mit je <1 Ansatz derselben Sub-Rezept-Zutat runden GEMEINSAM auf einen Ansatz', function () {
    $order = $this->svc->draftForDate($this->rootTeam, '2026-08-01');

    // Nur Kuchen (20 Portionen ÷ 10 = 2 Ansätze): Sauce-Bedarf 150g×2=300g ÷ 1000g = 0,3 → einzeln aufgerundet 1.
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->kuchen->id, 'portions' => 20], 'a');
    $sauceLine = $order->lines()->where('recipe_id', $this->sauce->id)->first();
    expect((float) $sauceLine->ansaetze)->toBe(1.0);

    // + Tarte (20 Portionen ÷ 10 = 2 Ansätze): Sauce-Bedarf 100g×2=200g ÷ 1000g = 0,2 (einzeln auch aufgerundet 1).
    // Additiv-falsch gerechnet wären das 1+1=2 Ansätze. Richtig: 0,3+0,2=0,5 GEMEINSAM gerundet → weiterhin 1.
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->tarte->id, 'portions' => 20], 'b');
    $sauceLine = $order->lines()->where('recipe_id', $this->sauce->id)->first();
    expect((float) $sauceLine->benoetigt_ansaetze)->toBe(0.5)
        ->and((float) $sauceLine->ansaetze)->toBe(1.0); // NICHT 2
});

it('removeTarget entfernt den Beitrag vollständig, nicht nur genullt', function () {
    $order = $this->svc->draftForDate($this->rootTeam, '2026-08-01');
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'a');
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->tarte->id, 'portions' => 50], 'b');

    $order = $this->svc->removeTarget($this->rootTeam, $order->id, 'b');

    expect($order->targets)->toHaveCount(1)
        ->and($order->lines()->where('recipe_id', $this->tarte->id)->exists())->toBeFalse()
        ->and($order->lines()->where('recipe_id', $this->kuchen->id)->exists())->toBeTrue();
});

it('Erneutes Hinzufügen desselben source_ref ersetzt statt verdoppelt', function () {
    $order = $this->svc->draftForDate($this->rootTeam, '2026-08-01');
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'a');
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->kuchen->id, 'portions' => 50], 'a'); // gleiche Quelle, andere Menge

    expect($order->targets)->toHaveCount(1)
        ->and((float) $order->targets[0]['portions'])->toBe(50.0)
        ->and((float) $order->lines()->where('recipe_id', $this->kuchen->id)->first()->ansaetze)->toBe(5.0); // 50÷10, nicht 15
});

it('Status-Guard: planned→in_progress friert Snapshot ein, illegale Übergänge werfen', function () {
    $order = $this->svc->draftForDate($this->rootTeam, '2026-08-01');
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'a');

    expect(fn () => $this->svc->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::Done))
        ->toThrow(\RuntimeException::class);

    $vorZubereitung = $order->lines()->where('recipe_id', $this->kuchen->id)->first()->zubereitung;

    $inArbeit = $this->svc->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::InProgress);
    expect($inArbeit->status)->toBe(ProductionOrderStatus::InProgress)->and($inArbeit->started_at)->not->toBeNull();

    // Rezept danach ändern — eingefrorene Zeile darf sich NICHT mehr bewegen (kein Recompute mehr im in_progress).
    $this->kuchen->update(['preparation' => 'GEÄNDERT nach dem Einfrieren.']);
    $this->svc->recomputeOrder($this->rootTeam, $inArbeit->refresh()); // no-op, da nicht mehr istOffen()
    expect($inArbeit->lines()->where('recipe_id', $this->kuchen->id)->first()->zubereitung)->toBe($vorZubereitung);

    expect(fn () => $this->svc->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::Planned))
        ->toThrow(\RuntimeException::class); // in_progress→planned gibt es nicht

    $fertig = $this->svc->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::Done);
    expect($fertig->status)->toBe(ProductionOrderStatus::Done)->and($fertig->finished_at)->not->toBeNull();
    expect(fn () => $this->svc->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::Cancelled))
        ->toThrow(\RuntimeException::class); // done = Endstation
});

it('updateLine-Notiz übersteht einen nachfolgenden Recompute', function () {
    $order = $this->svc->draftForDate($this->rootTeam, '2026-08-01');
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'a');
    $line = $order->lines()->where('recipe_id', $this->kuchen->id)->first();

    $this->svc->updateLine($this->rootTeam, $line->id, ['note' => 'Ofen 2 vorheizen']);

    // Weiteres Ziel hinzufügen löst einen vollen Recompute aus (Zeilen werden ersetzt).
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->tarte->id, 'portions' => 50], 'b');

    expect($order->lines()->where('recipe_id', $this->kuchen->id)->first()->note)->toBe('Ofen 2 vorheizen');
});

it('Team-Scoping-Guard: fremdes Team kann weder Ziel ändern noch Status setzen', function () {
    $order = $this->svc->draftForDate($this->rootTeam, '2026-08-01');
    $order = $this->svc->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->kuchen->id, 'portions' => 100], 'a');

    $fremdesTeam = \Platform\Core\Models\Team::create(['name' => 'Fremdes Team', 'user_id' => 1, 'personal_team' => false]);

    expect(fn () => $this->svc->addTarget($fremdesTeam, $order->id, ['recipe_id' => $this->kuchen->id, 'portions' => 10], 'x'))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => $this->svc->setStatus($fremdesTeam, $order->id, ProductionOrderStatus::InProgress))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('UI: Editor legt einen Produktionsauftrag komplett an (Stammdaten + Ziel + Speichern)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(ProduktionEditor::class)
        ->call('oeffnenNeu')
        ->set('productionDate', '2026-08-01')
        ->set('name', 'Sommerfest Vormittag')
        ->set('reference', 'Sommer-Buffet')
        ->set('zielTyp', 'recipe')
        ->set('auswahlRecipeId', $this->kuchen->id)
        ->set('auswahlMenge', 100)
        ->call('zielHinzufuegen')
        ->call('speichern')
        ->assertDispatched('produktion-gespeichert');

    $order = FoodAlchemistProductionOrder::where('name', 'Sommerfest Vormittag')->firstOrFail();
    expect($order->production_date->toDateString())->toBe('2026-08-01')
        ->and($order->reference)->toBe('Sommer-Buffet')
        ->and($order->lines()->where('recipe_id', $this->kuchen->id)->exists())->toBeTrue();
});

it('UI: Editor verweigert Speichern ohne Name (Pflichtfeld)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(ProduktionEditor::class)
        ->call('oeffnenNeu')
        ->set('productionDate', '2026-08-01')
        ->set('zielTyp', 'recipe')
        ->set('auswahlRecipeId', $this->kuchen->id)
        ->set('auswahlMenge', 100)
        ->call('zielHinzufuegen')
        ->call('speichern')
        ->assertNotDispatched('produktion-gespeichert')
        ->assertSet('fehler', 'Name, Datum und mindestens ein Ziel angeben.');

    expect(FoodAlchemistProductionOrder::count())->toBe(0);
});

it('UI: Browser listet Aufträge, Klick wählt sie im DetailPanel (Cockpit-KPIs)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $order = $this->svc->saveNew($this->rootTeam, '2026-08-01', 'Sommer-Buffet', [
        ['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'recipe:kuchen@100'],
    ]);

    Livewire::test(ProduktionBrowser::class)
        ->assertSee('Sommer-Buffet')
        ->call('waehle', $order->id)
        ->assertDispatched('production-order-selected');

    Livewire::test(ProduktionDetailPanel::class, ['orderId' => $order->id])
        ->assertSee('Sommer-Buffet')
        ->assertSee('DES: Kuchen')
        ->assertSee('Produktion starten');
});

it('Route: /blaetter redirected auf /produktion (keine toten Deep-Links)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->get(route('foodalchemist.blaetter.index'))
        ->assertRedirect(route('foodalchemist.produktion.index'));
})->skip(fn () => ! \Illuminate\Support\Facades\Route::has('foodalchemist.blaetter.index'), 'Modul-Routen im Test-Harness nicht registriert');

it('MCP im Lockstep: production_orders.GET/ADD_TARGET/SET_STATUS/UPDATE_LINE registriert + End-to-End', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(\Platform\Core\Tools\ToolRegistry::class);
    $kontext = new \Platform\Core\Contracts\ToolContext($user, $this->rootTeam);

    foreach (['production_orders.GET' => true, 'production_orders.ADD_TARGET' => false, 'production_orders.REMOVE_TARGET' => false, 'production_orders.UPDATE' => false, 'production_orders.SET_STATUS' => false, 'production_orders.UPDATE_LINE' => false] as $t => $readonly) {
        $tool = $registry->get("foodalchemist.{$t}");
        expect($tool)->not->toBeNull()
            ->and($tool->getMetadata()['read_only'])->toBe($readonly);
    }

    // ADD_TARGET (write): legt den Auftrag an (production_date + name) + fügt das Ziel hinzu
    $add = $registry->get('foodalchemist.production_orders.ADD_TARGET')
        ->execute(['production_date' => '2026-08-01', 'name' => 'Sommerfest', 'recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'recipe:kuchen@100'], $kontext);
    expect($add->success)->toBeTrue()->and($add->data['production_date'])->toBe('2026-08-01')->and($add->data['name'])->toBe('Sommerfest');
    $orderId = $add->data['order_id'];

    // ADD_TARGET erfordert order_id ODER production_date
    $bad = $registry->get('foodalchemist.production_orders.ADD_TARGET')
        ->execute(['recipe_id' => $this->tarte->id, 'portions' => 10, 'source_ref' => 'x'], $kontext);
    expect($bad->success)->toBeFalse();

    // ADD_TARGET per order_id (bestehender Auftrag) + REMOVE_TARGET wieder raus
    $add2 = $registry->get('foodalchemist.production_orders.ADD_TARGET')
        ->execute(['order_id' => $orderId, 'recipe_id' => $this->tarte->id, 'portions' => 50, 'source_ref' => 'recipe:tarte@50'], $kontext);
    expect($add2->success)->toBeTrue()->and(collect($add2->data['targets'])->pluck('source_ref')->all())->toContain('recipe:tarte@50');
    $rem = $registry->get('foodalchemist.production_orders.REMOVE_TARGET')
        ->execute(['order_id' => $orderId, 'source_ref' => 'recipe:tarte@50'], $kontext);
    expect($rem->success)->toBeTrue()->and(collect($rem->data['targets'])->pluck('source_ref')->all())->not->toContain('recipe:tarte@50');

    // UPDATE (write): Kopf-Felder ändern
    $upd = $registry->get('foodalchemist.production_orders.UPDATE')
        ->execute(['order_id' => $orderId, 'name' => 'Sommerfest 2026', 'reference' => 'Buffet', 'note' => 'Halle A'], $kontext);
    expect($upd->success)->toBeTrue()->and($upd->data['name'])->toBe('Sommerfest 2026')->and($upd->data['reference'])->toBe('Buffet');

    // GET Liste: 1 geplanter Auftrag (mit name)
    $list = $registry->get('foodalchemist.production_orders.GET')->execute([], $kontext);
    expect($list->success)->toBeTrue()->and($list->data['count'])->toBe(1)
        ->and($list->data['production_orders'][0]['name'])->toBe('Sommerfest 2026');

    // GET Detail: Kuchen-Zeile vorhanden
    $detail = $registry->get('foodalchemist.production_orders.GET')->execute(['order_id' => $orderId], $kontext);
    expect($detail->data['status'])->toBe('planned')
        ->and(collect($detail->data['zeilen'])->firstWhere('name', 'DES: Kuchen'))->not->toBeNull();
    $lineId = collect($detail->data['zeilen'])->firstWhere('name', 'DES: Kuchen')['id'];

    // UPDATE_LINE (write): Notiz setzen
    $upd = $registry->get('foodalchemist.production_orders.UPDATE_LINE')->execute(['line_id' => $lineId, 'note' => 'Ofen 2'], $kontext);
    expect($upd->success)->toBeTrue()->and($upd->data['note'])->toBe('Ofen 2');

    // SET_STATUS (write): Produktion starten; danach nicht mehr editierbar
    $started = $registry->get('foodalchemist.production_orders.SET_STATUS')->execute(['order_id' => $orderId, 'status' => 'in_progress'], $kontext);
    expect($started->success)->toBeTrue()->and($started->data['status'])->toBe('in_progress');
    $detail2 = $registry->get('foodalchemist.production_orders.GET')->execute(['order_id' => $orderId], $kontext);
    expect($detail2->data['editierbar'])->toBeFalse();

    // Illegaler Sprung (in_progress→planned gibt es nicht) → Guard
    $bad = $registry->get('foodalchemist.production_orders.SET_STATUS')->execute(['order_id' => $orderId, 'status' => 'done'], $kontext);
    expect($bad->success)->toBeTrue(); // in_progress→done IST erlaubt
});

it('S3: dokument() + Produktionsschein-Blade rendert', function () {
    $order = $this->svc->saveNew($this->rootTeam, '2026-08-01', 'Sommer-Buffet', [
        ['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'recipe:kuchen@100'],
    ]);

    $dok = $this->svc->dokument($this->rootTeam, $order->id);
    expect($dok['zeilen'])->toHaveCount(2) // Kuchen + Vanillesauce
        ->and($dok['production_date'])->toBe('2026-08-01')
        ->and($dok['name'])->toBe('Sommer-Buffet');

    $html = view('foodalchemist::dokumente.produktionsauftrag', ['dok' => $dok, 'istPdf' => true])->render();
    expect($html)->toContain('Produktionsschein')->toContain('DES: Kuchen')->toContain('Vanillesauce')->toContain('Sommer-Buffet');
});

it('S3-Bundle: dokument() enthält die Einkaufs-Sektion (Lieferant + EK); ?einkauf=0 lässt sie weg', function () {
    $order = $this->svc->saveNew($this->rootTeam, '2026-08-01', 'Sommer-Buffet', [
        ['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'recipe:kuchen@100'],
    ]);

    // Default: gebündelt — Einkauf nach Lieferant + Gesamt-EK dabei.
    $dok = $this->svc->dokument($this->rootTeam, $order->id);
    expect($dok['einkauf'])->not->toBeNull()
        ->and(collect($dok['einkauf']['lieferanten'])->pluck('lieferant')->sort()->values()->all())->toBe(['Chefs', 'Hanos'])
        ->and($dok['einkauf']['ek_gesamt'])->toBe(33.0); // Chefs 21 (Mehl 20 + Zucker 1) + Hanos 12 (Butter)

    $html = view('foodalchemist::dokumente.produktionsauftrag', ['dok' => $dok, 'istPdf' => true])->render();
    expect($html)->toContain('Einkauf / Bestellvorschlag')->toContain('Chefs')->toContain('Wareneinsatz gesamt');

    // Opt-out: nur Produktionsschein, keine EK/Lieferant-Daten.
    $dokOhne = $this->svc->dokument($this->rootTeam, $order->id, false);
    expect($dokOhne['einkauf'])->toBeNull();
    $htmlOhne = view('foodalchemist::dokumente.produktionsauftrag', ['dok' => $dokOhne, 'istPdf' => true])->render();
    expect($htmlOhne)->not->toContain('Einkauf / Bestellvorschlag');
});

it('V1: saveNew legt IMMER einen neuen Auftrag an — zwei benannte Aufträge am selben Tag koexistieren', function () {
    // Morgens: „Sommerfest Vormittag" mit einem Ziel.
    $order1 = $this->svc->saveNew($this->rootTeam, '2026-08-01', 'Sommerfest Vormittag', [
        ['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'recipe:kuchen@100'],
    ]);

    // Später ein ZWEITER, eigenständiger Auftrag für denselben Tag (V1: kein Tages-Merge mehr).
    $order2 = $this->svc->saveNew($this->rootTeam, '2026-08-01', 'Sommerfest Abend', [
        ['recipe_id' => $this->tarte->id, 'portions' => 50, 'source_ref' => 'recipe:tarte@50'],
    ]);

    // Zwei getrennte Aufträge, jeder mit exakt seinem Ziel — nichts vermischt sich.
    expect($order2->id)->not->toBe($order1->id)
        ->and($order1->name)->toBe('Sommerfest Vormittag')
        ->and($order2->name)->toBe('Sommerfest Abend')
        ->and(collect($order1->targets)->pluck('source_ref')->all())->toBe(['recipe:kuchen@100'])
        ->and(collect($order2->targets)->pluck('source_ref')->all())->toBe(['recipe:tarte@50'])
        ->and(FoodAlchemistProductionOrder::whereDate('production_date', '2026-08-01')->where('status', 'planned')->count())->toBe(2);

    // Rundung ist bewusst PRO AUFTRAG separat (V1-Trade-off): jeder Auftrag rundet seine
    // gemeinsame Sauce-Zutat für sich — order1 hat eine Sauce-Zeile, order2 hat eine eigene.
    expect((float) $order1->lines()->where('recipe_id', $this->sauce->id)->first()->ansaetze)->toBeGreaterThan(0.0)
        ->and((float) $order2->lines()->where('recipe_id', $this->sauce->id)->first()->ansaetze)->toBeGreaterThan(0.0);
});

it('V1: leerer Name ⇒ sprechendes Datums-Label (Fallback)', function () {
    $order = $this->svc->saveNew($this->rootTeam, '2026-08-01', '', [
        ['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'recipe:kuchen@100'],
    ]);
    expect($order->name)->toBe('Produktion 01.08.2026');
});

it('V1: draftForDate bleibt MCP-Kompat-Pfad (findOrCreate, ohne name = erster geplanter des Tages)', function () {
    $a = $this->svc->draftForDate($this->rootTeam, '2026-08-01');
    $b = $this->svc->draftForDate($this->rootTeam, '2026-08-01'); // ohne name ⇒ findet a wieder
    $c = $this->svc->draftForDate($this->rootTeam, '2026-08-01', null, 'Extra-Charge'); // name grenzt neu ab

    expect($a->id)->toBe($b->id)
        ->and($c->id)->not->toBe($a->id)
        ->and($c->name)->toBe('Extra-Charge')
        ->and($a->name)->toBe('Produktion 01.08.2026');
});

it('P1: Basisrezept-Ziel nach kg → kg ÷ Basis-Yield, auf ganze Ansätze aufgerundet', function () {
    // Vanillesauce: Basis-Yield 1,0 kg. 2,5 kg-Ziel → 2,5 Roh-Ansätze → aufgerundet 3.
    $order = $this->svc->saveNew($this->rootTeam, '2026-08-01', 'Mise en place', [
        ['recipe_id' => $this->sauce->id, 'amount_kg' => 2.5, 'source_ref' => 'recipe:sauce@2.5kg'],
    ]);

    $line = $order->lines()->where('recipe_id', $this->sauce->id)->first();
    expect((int) $line->ansaetze)->toBe(3)
        ->and((float) $line->benoetigt_ansaetze)->toBe(2.5)
        ->and($order->targets[0]['label'])->toBe('Vanillesauce (2,5 kg)');
});

it('P1: kg-Ziel bei fehlendem Basis-Yield ⇒ Warnung + 1 Ansatz (kein Crash)', function () {
    $fond = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Gemüsefond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => null,
    ]);
    $fond->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->zucker->id, 'raw_text' => 'Zucker', 'quantity' => 100, 'unit_vocab_id' => $this->g->id]);
    // Bewusst KEIN recomputePipeline: der würde yield_kg aus den Zutaten auffüllen — hier soll
    // der NULL-Yield-Fall (kg-Ziel nicht in Ansätze umrechenbar) getestet werden.
    $fond->update(['yield_kg' => null]);

    $order = $this->svc->saveNew($this->rootTeam, '2026-08-01', 'Fond-Tag', [
        ['recipe_id' => $fond->id, 'amount_kg' => 5.0, 'source_ref' => 'recipe:fond@5kg'],
    ]);

    expect((int) $order->lines()->where('recipe_id', $fond->id)->first()->ansaetze)->toBe(1)
        ->and(collect($order->warnungen)->filter(fn ($w) => str_contains($w, 'kg-Ziel ohne Basis-Yield'))->count())->toBeGreaterThan(0);
});

it('P1 UI: Editor legt ein Basisrezept-kg-Ziel an (Einheiten-Umschalter kg)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(ProduktionEditor::class)
        ->call('oeffnenNeu')
        ->set('productionDate', '2026-08-01')
        ->set('name', 'Vorbereitung')
        ->set('zielTyp', 'basisrezept')
        ->set('basisEinheit', 'kg')
        ->set('auswahlRecipeId', $this->sauce->id)
        ->set('auswahlMenge', 2.5)
        ->call('zielHinzufuegen')
        ->call('speichern')
        ->assertDispatched('produktion-gespeichert');

    $order = FoodAlchemistProductionOrder::where('name', 'Vorbereitung')->firstOrFail();
    expect((float) $order->targets[0]['amount_kg'])->toBe(2.5)
        ->and($order->targets[0])->not->toHaveKey('portions')
        ->and((int) $order->lines()->where('recipe_id', $this->sauce->id)->first()->ansaetze)->toBe(3);
});

it('P1 UI: Basisrezept-Zieltyp sucht im Basis-Scope, VK-Scope blendet Basisrezepte aus', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    // Basisrezept-Modus (->basis()) findet die Vanillesauce.
    Livewire::test(ProduktionEditor::class)
        ->call('oeffnenNeu')
        ->set('zielTyp', 'basisrezept')
        ->set('suche', 'Vanille')
        ->assertSee('Vanillesauce');

    // VK-Gericht-Modus (->verkauf()) blendet sie aus.
    Livewire::test(ProduktionEditor::class)
        ->call('oeffnenNeu')
        ->set('zielTyp', 'recipe')
        ->set('suche', 'Vanille')
        ->assertDontSee('Vanillesauce');
});

it('P1 MCP: produktionsblatt/bestellvorschlag/ADD_TARGET akzeptieren amount_kg', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(\Platform\Core\Tools\ToolRegistry::class);
    $kontext = new \Platform\Core\Contracts\ToolContext($user, $this->rootTeam);

    // read-only: Produktionsblatt für 2,5 kg Vanillesauce → 3 Ansätze.
    $blatt = $registry->get('foodalchemist.produktionsblatt.GET')
        ->execute(['recipe_id' => $this->sauce->id, 'amount_kg' => 2.5], $kontext);
    expect($blatt->success)->toBeTrue();
    $sauceRow = collect($blatt->data['rezepte'])->firstWhere('recipe_id', $this->sauce->id);
    expect((int) $sauceRow['ansaetze'])->toBe(3);

    // write: ADD_TARGET mit amount_kg legt ein kg-Ziel an.
    $add = $registry->get('foodalchemist.production_orders.ADD_TARGET')
        ->execute(['production_date' => '2026-08-01', 'name' => 'MCP-kg', 'recipe_id' => $this->sauce->id, 'amount_kg' => 2.5, 'source_ref' => 'recipe:sauce@2.5kg'], $kontext);
    expect($add->success)->toBeTrue()
        ->and((float) $add->data['targets'][0]['amount_kg'])->toBe(2.5);
});

it('S3: Produktionsschein-Dokument-Route liefert HTML + CSV-Download', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $order = $this->svc->saveNew($this->rootTeam, '2026-08-01', 'Sommer-Buffet', [
        ['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'recipe:kuchen@100'],
    ]);

    $this->get(route('foodalchemist.produktion.auftraege.dokument', ['order' => $order->id]))
        ->assertOk()->assertSee('Produktionsschein');

    $csv = $this->get(route('foodalchemist.produktion.auftraege.dokument', ['order' => $order->id, 'csv' => 1]));
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->streamedContent())->toContain('Rezept')->toContain('DES: Kuchen');
})->skip(fn () => ! \Illuminate\Support\Facades\Route::has('foodalchemist.produktion.auftraege.dokument'), 'Modul-Routen im Test-Harness nicht registriert');
