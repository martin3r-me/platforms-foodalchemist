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
        ->set('reference', 'Sommer-Buffet')
        ->set('zielTyp', 'recipe')
        ->set('auswahlRecipeId', $this->kuchen->id)
        ->set('auswahlMenge', 100)
        ->call('zielHinzufuegen')
        ->call('speichern')
        ->assertDispatched('produktion-gespeichert');

    $order = FoodAlchemistProductionOrder::where('reference', 'Sommer-Buffet')->firstOrFail();
    expect($order->production_date->toDateString())->toBe('2026-08-01')
        ->and($order->lines()->where('recipe_id', $this->kuchen->id)->exists())->toBeTrue();
});

it('UI: Browser listet Aufträge, Klick wählt sie im DetailPanel (Cockpit-KPIs)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $order = $this->svc->saveNew($this->rootTeam, '2026-08-01', [
        ['recipe_id' => $this->kuchen->id, 'portions' => 100, 'source_ref' => 'recipe:kuchen@100'],
    ], 'Sommer-Buffet');

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
