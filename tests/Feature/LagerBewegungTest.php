<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Lager\Index as LagerIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LagerBewegungService;
use Platform\FoodAlchemist\Services\WareneinsatzAbweichungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 67 · Lagerbewegungen von Hand: Zugang/Abgang mit Grund, Umlagerung, Storno, Bewertung,
 * benannter Schwund im Controlling.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(LagerBewegungService::class);
    $t = $this->rootTeam->id;

    $lief = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Chefs']);
    $this->butter = $this->makeGp($this->rootTeam, 'Butter');
    $la = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $lief->id, 'designation' => 'Butter 250 g', 'article_number' => 'B1',
        'qty' => 0.25, 'unit_code' => 'kg', 'ordering_unit' => 'PCE', 'packaging_unit' => 'KT', 'qty_ordering_per_packaging' => 40]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'gp_id' => $this->butter->id]);
    FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'price' => 2.50, 'status' => '0']);   // 10 €/kg
    $this->butter->update(['lead_la_supplier_item_id' => $la->id]);

    $this->haupt = FoodAlchemistInventoryLocation::create(['team_id' => $t, 'name' => 'Hauptlager', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
    $this->kueche = FoodAlchemistInventoryLocation::create(['team_id' => $t, 'name' => 'Küche', 'type' => 'warehouse', 'is_default' => false, 'is_active' => true]);
    FoodAlchemistInventoryStock::create(['team_id' => $t, 'inventory_location_id' => $this->haupt->id, 'gp_id' => $this->butter->id, 'qty_base' => 20000, 'base_unit' => 'g']);
    $this->bestand = fn ($ort) => (float) FoodAlchemistInventoryStock::where('gp_id', $this->butter->id)->where('inventory_location_id', $ort->id)->value('qty_base');
});

it('Abgang mit Grund: Bestand sinkt, Wert zum aktuellen EK eingefroren; ohne Grund abgelehnt', function () {
    [$m] = $this->svc->buchen($this->rootTeam, ['art' => 'abgang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'menge' => '1,5', 'grund' => 'verderb'], $this->user->id);
    expect(($this->bestand)($this->haupt))->toBe(18500.0)
        ->and($m->direction)->toBe('out')->and($m->reason)->toBe('verderb')
        ->and((float) $m->value_eur)->toBe(15.0)->and($m->booked_by)->toBe($this->user->id);

    expect(fn () => $this->svc->buchen($this->rootTeam, ['art' => 'abgang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'menge' => '1']))
        ->toThrow(\RuntimeException::class, 'Grund');
    expect(fn () => $this->svc->buchen($this->rootTeam, ['art' => 'abgang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'menge' => '0', 'grund' => 'bruch']))
        ->toThrow(\RuntimeException::class, 'größer als 0');
});

it('Zugang in Karton + Einheit, Preis von Hand; Umlagerung bucht zwei Hälften', function () {
    [$z] = $this->svc->buchen($this->rootTeam, ['art' => 'zugang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id,
        'kartons' => '1', 'einheiten' => '4', 'grund' => 'marktkauf', 'preis' => '8'], $this->user->id);
    expect((float) $z->qty_base)->toBe(11000.0)                 // 40×250 + 4×250
        ->and((float) $z->value_eur)->toBe(88.0)
        ->and(($this->bestand)($this->haupt))->toBe(31000.0);

    $ms = $this->svc->buchen($this->rootTeam, ['art' => 'umlagerung', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'ziel_location_id' => $this->kueche->id, 'menge' => '5']);
    expect($ms)->toHaveCount(2)->and($ms[0]->transfer_ref)->toBe($ms[1]->transfer_ref)
        ->and(($this->bestand)($this->haupt))->toBe(26000.0)->and(($this->bestand)($this->kueche))->toBe(5000.0);
    expect(fn () => $this->svc->buchen($this->rootTeam, ['art' => 'umlagerung', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'ziel_location_id' => $this->haupt->id, 'menge' => '1']))
        ->toThrow(\RuntimeException::class, 'gleich');
});

it('Storno: Gegenbuchung, Umlagerung beide Hälften, kein Doppel-Storno, Wareneingang nicht stornierbar', function () {
    [$ab] = $this->svc->buchen($this->rootTeam, ['art' => 'abgang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'menge' => '2', 'grund' => 'bruch']);
    $st = $this->svc->stornieren($this->rootTeam, $ab->id);
    expect(($this->bestand)($this->haupt))->toBe(20000.0)->and($st[0]->storno_of_id)->toBe($ab->id)->and($st[0]->direction)->toBe('in');
    expect(fn () => $this->svc->stornieren($this->rootTeam, $ab->id))->toThrow(\RuntimeException::class, 'bereits storniert');

    $um = $this->svc->buchen($this->rootTeam, ['art' => 'umlagerung', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'ziel_location_id' => $this->kueche->id, 'menge' => '3']);
    expect($this->svc->stornieren($this->rootTeam, $um[1]->id))->toHaveCount(2)
        ->and(($this->bestand)($this->haupt))->toBe(20000.0)->and(($this->bestand)($this->kueche))->toBe(0.0);

    $we = FoodAlchemistInventoryMovement::create(['team_id' => $this->rootTeam->id, 'inventory_location_id' => $this->haupt->id, 'gp_id' => $this->butter->id,
        'direction' => 'in', 'qty_base' => 1000, 'base_unit' => 'g', 'source' => 'wareneingang', 'source_hash' => 'we-1']);
    expect(fn () => $this->svc->stornieren($this->rootTeam, $we->id))->toThrow(\RuntimeException::class, 'Hand-Buchungen');
    expect(fn () => $this->svc->stornieren($this->childA, $ab->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('Controlling: Abgänge je Grund im Zeitraum, Storno verrechnet, Teil der Abweichung benannt', function () {
    $tag = '2026-07-10';
    $this->svc->buchen($this->rootTeam, ['art' => 'abgang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'menge' => '3', 'grund' => 'verderb', 'datum' => $tag]);
    $this->svc->buchen($this->rootTeam, ['art' => 'abgang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'menge' => '1', 'grund' => 'personal', 'datum' => $tag]);
    [$irrtum] = $this->svc->buchen($this->rootTeam, ['art' => 'abgang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'menge' => '2', 'grund' => 'bruch', 'datum' => $tag]);
    $this->svc->stornieren($this->rootTeam, $irrtum->id);
    $this->svc->buchen($this->rootTeam, ['art' => 'abgang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'menge' => '9', 'grund' => 'verderb', 'datum' => '2026-08-02']);

    $w = $this->svc->abgaengeWert($this->rootTeam, '2026-07-01', '2026-08-31');
    expect($w['je_grund']['verderb'])->toBe(120.0)        // 30 + 90 (August liegt im Zeitraum)
        ->and($w['je_grund']['bruch'])->toBe(0.0)
        ->and($w['schwund'])->toBe(120.0)->and($w['personal'])->toBe(10.0);
    expect($this->svc->abgaengeWert($this->rootTeam, '2026-07-01', '2026-07-31')['je_grund']['verderb'])->toBe(30.0);

    FoodAlchemistSalesFact::create(['team_id' => $this->rootTeam->id, 'raw_label' => 'x', 'qty_sold' => 1, 'revenue_net' => 1000, 'sold_at' => $tag, 'source' => 'csv_import', 'source_hash' => 's1']);
    FoodAlchemistPurchaseTransaction::create(['team_id' => $this->rootTeam->id, 'designation_raw' => 'x', 'qty' => 1, 'line_total' => 400, 'purchased_at' => $tag, 'source' => 'necta_import']);
    $a = app(WareneinsatzAbweichungService::class)->analyse($this->rootTeam, '2026-07-01', '2026-07-31');
    expect($a['abgaenge']['schwund'])->toBe(30.0)->and($a['abgaenge']['personal'])->toBe(10.0);
});

it('MCP: inventory_movements.POST (Gebinde) + STORNO, inventory.GET zeigt Grund und Wert', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $post = $reg->get('foodalchemist.inventory_movements.POST')->execute(['art' => 'abgang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'einheiten' => 8, 'grund' => 'probe'], $ctx);
    expect($post->success)->toBeTrue()->and($post->data['bewegungen'][0]['wert_eur'])->toBe(20.0);
    expect($reg->get('foodalchemist.inventory_movements.POST')->execute(['art' => 'abgang', 'gp_id' => $this->butter->id, 'location_id' => $this->haupt->id, 'menge' => 1, 'grund' => 'unsinn'], $ctx)->success)->toBeFalse();

    $get = $reg->get('foodalchemist.inventory.GET')->execute(['bewegungen' => true], $ctx);
    expect($get->data['bewegungen'][0])->toMatchArray(['grund' => 'probe', 'wert_eur' => 20.0, 'quelle' => 'abgang']);

    $st = $reg->get('foodalchemist.inventory_movements.STORNO')->execute(['movement_id' => $post->data['bewegungen'][0]['movement_id']], $ctx);
    expect($st->success)->toBeTrue()->and(($this->bestand)($this->haupt))->toBe(20000.0);
});

it('Oberfläche: Abgang buchen über den Reiter Bewegungen, Storno-Knopf', function () {
    $lw = Livewire::test(LagerIndex::class)->call('reiterSetzen', 'bewegungen')
        ->call('buchungOeffnen', 'abgang')
        ->set('buchungGpSuche', 'Butt')->assertSee('Butter')
        ->call('buchungGpWaehlen', $this->butter->id)
        ->assertSee('1 Karton = 40 Stück')
        ->set('buchung.location_id', $this->haupt->id)
        ->set('buchung.einheiten', '4')->set('buchung.grund', 'verderb')
        ->call('bewegungBuchen')
        ->assertSet('fehler', null)->assertSet('buchungOffen', false)
        ->assertSee('Abgang gebucht: Butter · 10,00 €')
        ->assertSee('Verderb');
    expect(($this->bestand)($this->haupt))->toBe(19000.0);

    $id = FoodAlchemistInventoryMovement::where('source', 'abgang')->value('id');
    $lw->call('stornieren', $id)->assertSee('storniert');
    expect(($this->bestand)($this->haupt))->toBe(20000.0);

    $lw->call('buchungOeffnen', 'umlagerung')->call('buchungGpWaehlen', $this->butter->id)
        ->set('buchung.location_id', $this->haupt->id)->set('buchung.ziel_location_id', $this->kueche->id)->set('buchung.menge', '2')
        ->call('bewegungBuchen')->assertSet('fehler', null);
    expect(($this->bestand)($this->kueche))->toBe(2000.0);
});

it('Wareneingang bucht in den Lagerort des Stammplatzes und schlägt beim ersten Eingang einen Stellplatz vor', function () {
    $ein = app(\Platform\FoodAlchemist\Services\LagerEinrichtungService::class);
    $kuehlHaupt = $ein->stellplatzAnlegen($this->rootTeam, $this->haupt->id, 'Kühlhaus', 'kuehl');
    $this->butter->forceFill(['condition' => 'frisch', 'commodity_group_code' => '06'])->save();
    $la = $this->butter->leadLa;

    $order = \Platform\FoodAlchemist\Models\FoodAlchemistOrder::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $la->supplier_id, 'status' => 'sent']);
    $line = \Platform\FoodAlchemist\Models\FoodAlchemistOrderLine::create(['team_id' => $this->rootTeam->id, 'order_id' => $order->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->butter->id,
        'qty_packs' => 4, 'pack_qty' => 0.25, 'unit_code' => 'kg', 'received_qty_packs' => 4, 'received_at' => now()]);
    $svc = app(\Platform\FoodAlchemist\Services\InventoryService::class);

    // ohne Stammplatz → Standardlager, Vorschlag „Kühlhaus" wird gesetzt
    $svc->syncReceiptLine($line);
    expect(($this->bestand)($this->haupt))->toBe(21000.0)
        ->and($ein->stammplaetze($this->rootTeam, $this->haupt->id)[$this->butter->id] ?? null)->toBe($kuehlHaupt->id);

    // Stammplatz in der Küche → nächster Wareneingang landet dort
    $ein->zuordnen($this->rootTeam, $this->haupt->id, [$this->butter->id], null);
    $kueche = $ein->stellplatzAnlegen($this->rootTeam, $this->kueche->id, 'Küchen-Kühlschrank', 'kuehl');
    $ein->zuordnen($this->rootTeam, $this->kueche->id, [$this->butter->id], $kueche->id);
    $line2 = $line->replicate();
    $line2->uuid = null;   // replicate kopiert die uuid mit
    $line2->save();
    $svc->syncReceiptLine($line2);
    expect(($this->bestand)($this->kueche))->toBe(1000.0);

    // Korrektur der ersten Buchung bleibt am Ort der Erstbuchung (Hauptlager)
    $line->update(['received_qty_packs' => 2]);
    $svc->syncReceiptLine($line->refresh());
    expect(($this->bestand)($this->haupt))->toBe(20500.0)->and(($this->bestand)($this->kueche))->toBe(1000.0);
});

it('GP-Modal Reiter Lager: Bestand je Lagerort, Stammplatz ohne Bearbeiten setzbar', function () {
    $ein = app(\Platform\FoodAlchemist\Services\LagerEinrichtungService::class);
    $bin = $ein->stellplatzAnlegen($this->rootTeam, $this->haupt->id, 'Kühlhaus', 'kuehl');
    Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\DetailPanel::class, ['gpId' => $this->butter->id, 'embedded' => true, 'section' => 'lager'])
        ->assertSee('Hauptlager')->assertSee('20 kg')->assertSee('Küche')
        ->call('stammplatzSetzen', $this->haupt->id, (string) $bin->id)
        ->assertSee('Kühlhaus');
    expect($ein->stammplaetze($this->rootTeam, $this->haupt->id)[$this->butter->id])->toBe($bin->id);
});

it('Neues/klassifiziertes Grundprodukt bekommt automatisch den Vorschlags-Stellplatz; Handzuordnung bleibt', function () {
    $ein = app(\Platform\FoodAlchemist\Services\LagerEinrichtungService::class);
    $tk = $ein->stellplatzAnlegen($this->rootTeam, $this->haupt->id, 'TK-Raum', 'tk');
    $kuehl = $ein->stellplatzAnlegen($this->rootTeam, $this->haupt->id, 'Kühlhaus', 'kuehl');

    $erbsen = $this->makeGp($this->rootTeam, 'Erbsen');
    expect($ein->stammplaetze($this->rootTeam, $this->haupt->id))->not->toHaveKey($erbsen->id);   // ohne Zustand kein Vorschlag
    $erbsen->update(['condition' => 'TK', 'commodity_group_code' => '01']);
    expect($ein->stammplaetze($this->rootTeam, $this->haupt->id)[$erbsen->id])->toBe($tk->id);

    $ein->zuordnen($this->rootTeam, $this->haupt->id, [$erbsen->id], $kuehl->id);   // Hand
    $erbsen->update(['condition' => 'frisch']);
    expect($ein->stammplaetze($this->rootTeam, $this->haupt->id)[$erbsen->id])->toBe($kuehl->id);
});

it('MCP: storage_bins.GET mit gp_id liefert Bestand + Stellplatz je Lagerort, inventory.GET filtert nach gp_id', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $ein = app(\Platform\FoodAlchemist\Services\LagerEinrichtungService::class);
    $bin = $ein->stellplatzAnlegen($this->rootTeam, $this->haupt->id, 'Kühlhaus', 'kuehl');
    $ein->zuordnen($this->rootTeam, $this->haupt->id, [$this->butter->id], $bin->id);

    $r = $reg->get('foodalchemist.storage_bins.GET')->execute(['gp_id' => $this->butter->id], $ctx);
    expect($r->success)->toBeTrue()
        ->and($r->data['lagerorte'][0])->toMatchArray(['lagerort' => 'Hauptlager', 'stellplatz' => 'Kühlhaus', 'bestand' => [['menge' => 20.0, 'einheit' => 'kg']]])
        ->and($r->data['lagerorte'][1]['lagerort'])->toBe('Küche');
    expect($reg->get('foodalchemist.storage_bins.GET')->execute([], $ctx)->success)->toBeFalse();

    $inv = $reg->get('foodalchemist.inventory.GET')->execute(['gp_id' => $this->butter->id], $ctx);
    expect($inv->data['bestand'])->toHaveCount(1)->and($inv->data['bestand'][0]['lieferant'])->toBe('Chefs');
});
