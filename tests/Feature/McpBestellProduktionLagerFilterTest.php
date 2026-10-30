<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryCount;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryCountLine;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\DeliveryNotesGetTool;
use Platform\FoodAlchemist\Tools\InventoryCountsGetTool;
use Platform\FoodAlchemist\Tools\InventoryGetTool;
use Platform\FoodAlchemist\Tools\OrdersGetTool;
use Platform\FoodAlchemist\Tools\ProductionOrdersGetTool;
use Platform\FoodAlchemist\Tools\SupplierInvoicesGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 91 PR 4 (C12, C13, C16, B8): orders.GET und production_orders.GET filtern und blättern wie Bestell- und Produktions-Browser,
 * inventory.GET kennt den Grund der Bewegungen, inventory_counts.GET die Zeilen-Filter der Zählliste; Lieferscheine und Rechnungen
 * haben eine zweite Seite statt eines stillen Deckels bei 500. Vorher: orders/production_orders nur `status`, ungeblättert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->t = $this->rootTeam->id;
    $this->chefs = FoodAlchemistSupplier::create(['team_id' => $this->t, 'name' => 'Chefs Culinar']);
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->t, 'name' => 'Hanos']);
    $this->heute = Carbon::today();
});

it('C12 · orders.GET filtert wie der Bestell-Browser: Lieferant, Produktion, Suche, Zeitraum, Datumsbasis, Positionen, Klärung', function () {
    $hochzeit = FoodAlchemistProductionOrder::create(['team_id' => $this->t, 'name' => 'Hochzeit Meier', 'production_date' => $this->heute->toDateString(), 'status' => 'planned']);
    $bestellung = fn (FoodAlchemistSupplier $s, string $liefertag, array $a = []) => FoodAlchemistOrder::create(['team_id' => $this->t, 'supplier_id' => $s->id,
        'status' => 'draft', 'desired_delivery_date' => $liefertag] + $a);
    $mehl = $bestellung($this->chefs, $this->heute->toDateString(), ['reference' => 'Wochenbedarf']);
    FoodAlchemistOrderLine::create(['team_id' => $this->t, 'order_id' => $mehl->id, 'designation' => 'Weizenmehl Type 405', 'qty_packs' => 2, 'pack_price' => 9.5,
        'source_contributions' => ["produktion:{$hochzeit->id}:x" => 4000]]);
    $butter = $bestellung($this->hanos, $this->heute->copy()->addWeek()->toDateString());
    FoodAlchemistOrderLine::create(['team_id' => $this->t, 'order_id' => $butter->id, 'designation' => 'Süßrahmbutter', 'qty_packs' => 1, 'pack_price' => 7]);
    $leer = $bestellung($this->hanos, $this->heute->copy()->addWeek()->toDateString());
    DB::table('foodalchemist_orders')->where('id', $butter->id)->update(['created_at' => $this->heute->copy()->subDays(3)]);
    $ids = fn (array $a) => collect(app(OrdersGetTool::class)->execute($a, $this->ctx)->data['orders'])->pluck('id')->sort()->values()->all();

    expect($ids(['supplier_id' => $this->hanos->id]))->toBe([$butter->id, $leer->id])
        ->and($ids(['production_order_id' => $hochzeit->id]))->toBe([$mehl->id])
        ->and($ids(['q' => 'weizenmehl']))->toBe([$mehl->id])
        ->and($ids(['q' => 'hochzeit']))->toBe([$mehl->id])          // Herkunft zählt mit
        ->and($ids(['zeitraum' => 'heute']))->toBe([$mehl->id])
        ->and($ids(['zeitraum' => 'naechste']))->toBe([$butter->id, $leer->id])
        ->and($ids(['von' => $this->heute->copy()->addDay()->toDateString()]))->toBe([$butter->id, $leer->id])
        ->and($ids(['datumsbasis' => 'bestelldatum', 'bis' => $this->heute->copy()->subDay()->toDateString()]))->toBe([$butter->id])
        ->and($ids(['nur_mit_positionen' => true]))->toBe([$mehl->id, $butter->id]);
    expect(app(OrdersGetTool::class)->execute(['zeitraum' => 'monat'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and(app(OrdersGetTool::class)->execute(['datumsbasis' => 'lieferdatum'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('C12 + D20 · orders.GET liefert Standort, Bestelldatum, supplier_id, Herkunft und blättert mit total', function () {
    $produktion = FoodAlchemistProductionOrder::create(['team_id' => $this->t, 'name' => 'Sommerfest', 'production_date' => '2026-07-01', 'status' => 'planned']);
    $o = FoodAlchemistOrder::create(['team_id' => $this->t, 'supplier_id' => $this->chefs->id, 'status' => 'draft', 'desired_delivery_date' => '2026-06-30']);
    FoodAlchemistOrderLine::create(['team_id' => $this->t, 'order_id' => $o->id, 'designation' => 'Zucker', 'qty_packs' => 1, 'pack_price' => 3,
        'source_contributions' => ["produktion:{$produktion->id}:x" => 1000]]);
    foreach (range(1, 2) as $i) {
        FoodAlchemistOrder::create(['team_id' => $this->t, 'supplier_id' => $this->hanos->id, 'status' => 'draft', 'desired_delivery_date' => "2026-07-0{$i}"]);
    }
    $r = app(OrdersGetTool::class)->execute(['per_page' => 2, 'kunde' => 'x'], $this->ctx);
    $seite2 = app(OrdersGetTool::class)->execute(['per_page' => 2, 'page' => 2], $this->ctx)->data;

    expect($r->data['orders'][0])->toMatchArray(['id' => $o->id, 'supplier_id' => $this->chefs->id, 'team_id' => $this->t,
        'standort' => $this->rootTeam->name, 'bestelldatum' => $o->created_at->toDateString()])
        ->and($r->data['orders'][0]['herkunft'][0])->toMatchArray(['production_order_id' => $produktion->id])
        ->and($r->data['orders'][0]['herkunft'][0]['label'])->toContain('Sommerfest')
        ->and($r->data)->toMatchArray(['count' => 2, 'total' => 3, 'page' => 1, 'per_page' => 2])
        ->and($r->data['warnungen'][0])->toContain("'kunde'")
        ->and($seite2)->toMatchArray(['count' => 1, 'total' => 3, 'page' => 2]);
});

it('C13 · production_orders.GET filtert wie der Produktions-Browser und liefert dessen Spalten', function () {
    $saucier = FoodAlchemistProductionStation::create(['team_id' => $this->t, 'name' => 'Saucier', 'slug' => 'saucier']);
    $heute = FoodAlchemistProductionOrder::create(['team_id' => $this->t, 'name' => 'Bankett Rathaus', 'reference' => 'BR-7', 'production_date' => $this->heute->toDateString(),
        'status' => 'planned', 'targets' => [['label' => 'Rinderjus (6 kg)'], ['label' => 'Kartoffelgratin']]]);
    $zeile = fn (string $rezept, array $a) => FoodAlchemistProductionOrderLine::create(['team_id' => $this->t, 'production_order_id' => $heute->id,
        'recipe_id' => $this->makeRecipe($this->rootTeam, $rezept)->id] + $a);
    $zeile('Rinderjus', ['ansaetze' => 1.5, 'portionen' => 60, 'arbeitszeit_min' => 45, 'station_id' => $saucier->id]);
    $zeile('Kartoffelgratin', ['ansaetze' => 2, 'manual_ansaetze' => 3, 'is_manual_ansaetze' => true, 'portionen' => 40, 'arbeitszeit_min' => 30]);
    $zeile('Kalbsfond', ['ansaetze' => 9, 'portionen' => 900, 'arbeitszeit_min' => 900, 'is_struck' => true]);
    $gestern = FoodAlchemistProductionOrder::create(['team_id' => $this->t, 'name' => 'Kantine', 'production_date' => $this->heute->copy()->subDay()->toDateString(), 'status' => 'done']);
    $namen = fn (array $a) => collect(app(ProductionOrdersGetTool::class)->execute($a, $this->ctx)->data['production_orders'])->pluck('name')->all();

    expect($namen(['zeitraum' => 'heute']))->toBe(['Bankett Rathaus'])
        ->and($namen(['zeitraum' => 'vergangen']))->toBe(['Kantine'])
        ->and($namen(['von' => $this->heute->toDateString()]))->toBe(['Bankett Rathaus'])
        ->and($namen(['q' => 'br-7']))->toBe(['Bankett Rathaus'])
        ->and($namen(['status' => 'done']))->toBe(['Kantine'])
        ->and(app(ProductionOrdersGetTool::class)->execute(['zeitraum' => 'morgen'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
    $bankett = app(ProductionOrdersGetTool::class)->execute(['q' => 'bankett'], $this->ctx)->data['production_orders'][0];
    expect($bankett)->toMatchArray(['ziele' => ['Rinderjus (6 kg)', 'Kartoffelgratin'], 'ansaetze' => 4.5, 'portionen' => 100, 'arbeitszeit_min' => 75,
        'posten' => ['Saucier'], 'ohne_posten' => 1, 'bedarf' => 'entwurf', 'team_id' => $this->t, 'standort' => $this->rootTeam->name]);
});

it('C13 · production_orders.GET blättert (per_page 10–250, total)', function () {
    foreach (range(1, 11) as $i) {
        FoodAlchemistProductionOrder::create(['team_id' => $this->t, 'name' => sprintf('Auftrag %02d', $i), 'production_date' => '2026-08-'.sprintf('%02d', $i), 'status' => 'planned']);
    }
    $s1 = app(ProductionOrdersGetTool::class)->execute(['per_page' => 10], $this->ctx)->data;
    $s2 = app(ProductionOrdersGetTool::class)->execute(['per_page' => 10, 'page' => 2], $this->ctx)->data;

    expect($s1)->toMatchArray(['count' => 10, 'total' => 11, 'page' => 1, 'per_page' => 10])
        ->and($s2['production_orders'][0]['name'])->toBe('Auftrag 11')
        ->and($s2)->toMatchArray(['count' => 1, 'page' => 2]);
});

it('C16 · inventory.GET filtert Bewegungen nach Grund; inventory_counts.GET Zeilen nach suche/zustand/warengruppe/ohne_preis', function () {
    $ort = FoodAlchemistInventoryLocation::create(['team_id' => $this->t, 'name' => 'Hauptlager', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
    $gp = fn (string $name, string $zustand, string $wg) => FoodAlchemistGp::create(['team_id' => $this->t, 'gp_key' => "spec91-{$name}||", 'name' => $name,
        'condition' => $zustand, 'commodity_group_code' => $wg]);
    $butter = $gp('Butter', 'frisch', '07');
    $erbsen = $gp('Erbsen', 'TK', '03');
    $bewegung = fn (string $grund, string $hash) => FoodAlchemistInventoryMovement::create(['team_id' => $this->t, 'inventory_location_id' => $ort->id, 'gp_id' => $butter->id,
        'direction' => 'out', 'qty_base' => 250, 'base_unit' => 'g', 'source' => 'hand', 'reason' => $grund, 'source_hash' => $hash, 'moved_at' => now()]);
    $bewegung('verderb', 'b1');
    $bewegung('bruch', 'b2');
    $gruende = fn (array $a) => array_column(app(InventoryGetTool::class)->execute(['bewegungen' => true] + $a, $this->ctx)->data['bewegungen'], 'grund');

    expect($gruende(['grund' => 'bruch']))->toBe(['bruch'])
        ->and($gruende([]))->toEqualCanonicalizing(['verderb', 'bruch'])
        ->and(app(InventoryGetTool::class)->execute(['bewegungen' => true, 'grund' => 'diebstahl'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');

    $count = FoodAlchemistInventoryCount::create(['team_id' => $this->t, 'inventory_location_id' => $ort->id, 'count_date' => '2026-10-01', 'status' => 'offen']);
    $line = fn (FoodAlchemistGp $g, ?float $preis, int $pos) => FoodAlchemistInventoryCountLine::create(['team_id' => $this->t, 'inventory_count_id' => $count->id,
        'gp_id' => $g->id, 'base_unit' => 'g', 'qty_expected' => 1000, 'price_per_base' => $preis, 'position' => $pos]);
    $line($butter, 0.009, 1);
    $line($erbsen, null, 2);
    $zeilen = fn (array $a) => array_column(app(InventoryCountsGetTool::class)->execute(['count_id' => $count->id] + $a, $this->ctx)->data['zeilen'], 'name');

    expect($zeilen(['suche' => 'erb']))->toBe(['Erbsen'])
        ->and($zeilen(['zustand' => 'frisch']))->toBe(['Butter'])
        ->and($zeilen(['warengruppe' => '03']))->toBe(['Erbsen'])
        ->and($zeilen(['ohne_preis' => true]))->toBe(['Erbsen'])
        ->and($zeilen([]))->toBe(['Butter', 'Erbsen']);
    $liste = app(InventoryCountsGetTool::class)->execute([], $this->ctx)->data['inventuren'][0];
    expect($liste)->toMatchArray(['count_id' => $count->id, 'positionen' => 2, 'team_id' => $this->t, 'standort' => $this->rootTeam->name]);
});

it('B8 · delivery_notes.GET und supplier_invoices.GET haben eine zweite Seite statt eines Deckels bei 500', function () {
    $start = Carbon::parse('2025-01-01');
    $zeilen = fn (string $datum) => collect(range(0, 500))->map(fn ($i) => ['team_id' => $this->t, 'supplier_id' => $this->chefs->id,
        $datum => $start->copy()->addDays($i)->toDateString(), 'created_at' => now(), 'updated_at' => now()])->all();
    DB::table('foodalchemist_delivery_notes')->insert($zeilen('delivered_on'));
    DB::table('foodalchemist_supplier_invoices')->insert($zeilen('invoice_date'));

    $ls = fn (array $a) => app(DeliveryNotesGetTool::class)->execute($a, $this->ctx)->data['lieferscheine'];
    $re = fn (array $a) => app(SupplierInvoicesGetTool::class)->execute($a, $this->ctx)->data['rechnungen'];

    expect(count($ls([])))->toBe(500)
        ->and($ls(['page' => 2]))->toHaveCount(1)
        ->and(count($re([])))->toBe(500)
        ->and($re(['page' => 2]))->toHaveCount(1);
});
