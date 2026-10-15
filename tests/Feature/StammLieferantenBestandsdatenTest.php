<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryCount;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryCountLine;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\InventurService;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\StammPaketService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lieferanten-Freischaltung trifft NUR globale Stamm-Zeilen (team_id NULL, stamm_paket). Eigene Zeilen eines Mandanten
 * verschwinden nie; bestehende Bestellungen/Inventuren laden weiter, wenn ihr Lieferant später abgewählt wird.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    StammPaket::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->svc = fn () => app(StammPaketService::class);
    ($this->svc)()->buchen($this->soul, $this->admin);

    $this->hanos = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos', 'stamm_class' => 'stamm_paket']);
    $this->globalLa = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->hanos->id, 'designation' => 'Mehl 1kg', 'article_number' => 'H1',
        'qty' => 1, 'unit_code' => 'kg', 'stamm_class' => 'stamm_paket']);
    $this->eigenLa = FoodAlchemistSupplierItem::create(['team_id' => $this->soul->id, 'supplier_id' => $this->hanos->id, 'designation' => 'Eigen', 'article_number' => 'E1',
        'qty' => 1, 'unit_code' => 'kg']);
    $this->preisGlobal = FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => $this->globalLa->id, 'price' => 3.0, 'status' => '0']);
    $this->preisEigen = FoodAlchemistPrice::create(['team_id' => $this->soul->id, 'supplier_item_id' => $this->globalLa->id, 'price' => 2.0, 'status' => '0']);
    $this->preisEigenAnEigen = FoodAlchemistPrice::create(['team_id' => $this->soul->id, 'supplier_item_id' => $this->eigenLa->id, 'price' => 1.5, 'status' => '0']);
});

it('★ Eigene Artikel und eigene Preise verschwinden nie, auch ohne freigeschalteten Lieferanten; globale Zeilen schon', function () {
    $items = FoodAlchemistSupplierItem::query()->visibleToTeam($this->soul)->pluck('article_number')->sort()->values()->all();
    expect($items)->toBe(['E1']);                                         // eigener Artikel da, globaler Paketartikel zu

    $preise = fn () => FoodAlchemistPrice::query()->stammSichtbar($this->soul)->pluck('id')->sort()->values()->all();
    expect($preise())->toBe([$this->preisEigen->id, $this->preisEigenAnEigen->id]);   // eigene Preise bleiben, globaler Preis fehlt

    $pe = app(PriceService::class);
    $q = $pe->stammFilter(FoodAlchemistPrice::query(), 'foodalchemist_prices', $this->soul)->pluck('id')->sort()->values()->all();
    expect($q)->toBe([$this->preisEigen->id, $this->preisEigenAnEigen->id]);

    ($this->svc)()->setzeLieferanten($this->soul, [$this->hanos->id], $this->admin);   // Gegenprobe: mit Freigabe kommt das Globale dazu
    expect($preise())->toContain($this->preisGlobal->id);
    expect(FoodAlchemistSupplierItem::query()->visibleToTeam($this->soul)->pluck('article_number')->sort()->values()->all())->toBe(['E1', 'H1']);
});

it('★ Bestehende Bestellung lädt weiter, wenn der Lieferant ihres Artikels später abgewählt wird', function () {
    ($this->svc)()->setzeLieferanten($this->soul, [$this->hanos->id], $this->admin);
    $order = FoodAlchemistOrder::create(['team_id' => $this->soul->id, 'supplier_id' => $this->hanos->id, 'status' => 'draft', 'reference' => 'Alt']);
    FoodAlchemistOrderLine::create(['team_id' => $this->soul->id, 'order_id' => $order->id, 'position' => 1, 'supplier_item_id' => $this->globalLa->id,
        'designation' => 'Mehl 1kg', 'unit_code' => 'kg', 'pack_qty' => 1, 'pack_price' => 3.0, 'qty_packs' => 2, 'line_total' => 6.0]);

    ($this->svc)()->setzeLieferanten($this->soul, 'keine', $this->admin);        // Lieferant abgewählt

    $detail = app(OrderService::class)->detail($this->soul, $order->id);
    expect($detail['lines'] ?? $detail['zeilen'] ?? null)->not->toBeNull();
    $zeilen = $detail['lines'] ?? $detail['zeilen'];
    expect(collect($zeilen)->pluck('designation')->all())->toBe(['Mehl 1kg']);
    // Neu bestellen darf der Mandant den abgewählten Artikel nicht mehr (kein 500, sondern saubere Meldung)
    expect(fn () => app(OrderService::class)->addManualLine($this->soul, $this->globalLa->id, 1))->toThrow(RuntimeException::class, 'nicht gefunden');
});

it('★ Bestehende Inventur lädt, zählt und bewertet weiter, wenn der Lieferant des Artikels später abgewählt wird', function () {
    ($this->svc)()->setzeLieferanten($this->soul, [$this->hanos->id], $this->admin);
    $lager = FoodAlchemistInventoryLocation::create(['team_id' => $this->soul->id, 'name' => 'Hauptlager', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
    $count = FoodAlchemistInventoryCount::create(['team_id' => $this->soul->id, 'inventory_location_id' => $lager->id, 'count_date' => '2026-10-15', 'status' => 'open']);
    $line = FoodAlchemistInventoryCountLine::create(['team_id' => $this->soul->id, 'inventory_count_id' => $count->id, 'supplier_item_id' => $this->globalLa->id,
        'base_unit' => 'g', 'qty_expected' => 1000, 'position' => 1]);

    ($this->svc)()->setzeLieferanten($this->soul, 'keine', $this->admin);

    $inv = app(InventurService::class);
    expect($inv->detail($this->soul, $count->id)->lines)->toHaveCount(1);
    expect($inv->zaehlen($this->soul, $line->id, 2)->qty_counted)->not->toBeNull();
    expect(fn () => $inv->preisJeBasis($this->soul, null, $this->globalLa->id, 'g'))->not->toThrow(Throwable::class);
});
