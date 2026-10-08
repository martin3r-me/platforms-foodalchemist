<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Lager\Index as LagerIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryCountLine;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistStorageBinItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\InventurService;
use Platform\FoodAlchemist\Services\LagerEinrichtungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 66b · Lager einrichten: Stellplätze + Laufweg, Stammplatz-Vorschlag aus Zustand/Warengruppe,
 * Zählen in Karton/Einheit/lose, smarte Filter, „nicht gezählt = 0".
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->inv = app(InventurService::class);
    $this->ein = app(LagerEinrichtungService::class);
    $t = $this->rootTeam->id;

    $lief = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Chefs']);
    // $la: [qty, unit_code, ordering_unit, packaging_unit, qty_ordering_per_packaging]
    $this->gpMit = function (string $name, string $zustand, string $wg, float $preis, array $la) use ($lief, $t) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->forceFill(['condition' => $zustand, 'commodity_group_code' => $wg])->save();
        $item = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $lief->id, 'designation' => $name, 'article_number' => 'A-' . $name,
            'qty' => $la[0], 'unit_code' => $la[1], 'ordering_unit' => $la[2], 'packaging_unit' => $la[3], 'qty_ordering_per_packaging' => $la[4]]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $item->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $item->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $item->id]);

        return $gp->refresh();
    };
    // Saft: Kiste = 6 Flaschen à 1 l · Butter: Karton = 40 Stück à 0,25 kg · Erbsen TK: Beutel 2,5 kg, kein Karton · Mehl: lose kg
    $this->saft = ($this->gpMit)('Orangensaft', 'frisch', '15', 12.00, [1, 'l', 'FL', 'KI', 6]);
    $this->butter = ($this->gpMit)('Butter', 'frisch', '06', 2.00, [0.25, 'kg', 'PCE', 'KT', 40]);
    $this->erbsen = ($this->gpMit)('Erbsen', 'TK', '01', 5.00, [2.5, 'kg', 'BTL', 'BTL', 1]);
    $this->mehl = ($this->gpMit)('Mehl', 'trocken', '07', 1.00, [1, 'kg', 'KG', 'SACK', 1]);

    $this->lager = FoodAlchemistInventoryLocation::create(['team_id' => $t, 'name' => 'Hauptlager', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
    foreach ([[$this->saft, 'ml', 12000], [$this->butter, 'g', 20000], [$this->erbsen, 'g', 5000], [$this->mehl, 'g', 10000]] as [$gp, $u, $q]) {
        FoodAlchemistInventoryStock::create(['team_id' => $t, 'inventory_location_id' => $this->lager->id, 'gp_id' => $gp->id, 'qty_base' => $q, 'base_unit' => $u]);
    }
});

it('Vorschlag: Zone aus Zustand + Warengruppe, erster Stellplatz der Zone, bestehende Zuordnung bleibt', function () {
    $trocken = $this->ein->stellplatzAnlegen($this->rootTeam, $this->lager->id, 'Trockenlager Regal 1', 'trocken');
    $kuehl = $this->ein->stellplatzAnlegen($this->rootTeam, $this->lager->id, 'Kühlhaus', 'kuehl');
    $tk = $this->ein->stellplatzAnlegen($this->rootTeam, $this->lager->id, 'TK-Raum', 'tk');
    // Mehl ist schon von Hand im Kühlhaus — bleibt dort
    $this->ein->zuordnen($this->rootTeam, $this->lager->id, [$this->mehl->id], $kuehl->id);

    expect($this->ein->vorschlagUebernehmen($this->rootTeam, $this->lager->id))->toBe(3);
    $stamm = $this->ein->stammplaetze($this->rootTeam, $this->lager->id);
    expect($stamm[$this->butter->id])->toBe($kuehl->id)
        ->and($stamm[$this->erbsen->id])->toBe($tk->id)
        ->and($stamm[$this->saft->id])->toBe($trocken->id)        // Getränke ohne Getränke-Platz → Trocken
        ->and($stamm[$this->mehl->id])->toBe($kuehl->id)
        ->and(FoodAlchemistStorageBinItem::where('gp_id', $this->butter->id)->value('source'))->toBe('vorschlag');
});

it('Zählliste: Laufweg nach Stellplatz-Reihenfolge, Gebinde aus dem Lead-Artikel', function () {
    $a = $this->ein->stellplatzAnlegen($this->rootTeam, $this->lager->id, 'A Kühlhaus', 'kuehl');
    $b = $this->ein->stellplatzAnlegen($this->rootTeam, $this->lager->id, 'B TK', 'tk');
    $this->ein->zuordnen($this->rootTeam, $this->lager->id, [$this->erbsen->id], $a->id);
    $this->ein->zuordnen($this->rootTeam, $this->lager->id, [$this->butter->id], $b->id);
    $this->ein->stellplatzVerschieben($this->rootTeam, $b->id, -1);   // B vor A laufen

    $c = $this->inv->anlegen($this->rootTeam, $this->lager->id, '2026-09-30');
    $lines = $c->lines()->orderBy('position')->with('gp')->get();
    expect($lines->pluck('gp.name')->all())->toBe(['Butter', 'Erbsen', 'Mehl', 'Orangensaft']);

    $butter = $lines->firstWhere('gp_id', $this->butter->id);
    expect($butter->pack_label)->toBe('Karton')->and((float) $butter->pack_units)->toBe(40.0)
        ->and($butter->unit_label)->toBe('Stück')->and((float) $butter->unit_base)->toBe(250.0)
        ->and($butter->storage_bin_id)->toBe($b->id);
    $erbsen = $lines->firstWhere('gp_id', $this->erbsen->id);
    expect($erbsen->pack_units)->toBeNull()->and($erbsen->unit_label)->toBe('Beutel')->and((float) $erbsen->unit_base)->toBe(2500.0);
    expect($lines->firstWhere('gp_id', $this->mehl->id)->hatGebinde())->toBeFalse();   // Bestelleinheit kg = kein Gebinde
    $saft = $lines->firstWhere('gp_id', $this->saft->id);
    expect($saft->pack_label)->toBe('Kiste')->and((float) $saft->unit_base)->toBe(1000.0);
});

it('Zählen in Karton + Einheit + lose summiert in die Basiseinheit; Freitext setzt Aufteilung zurück', function () {
    $c = $this->inv->anlegen($this->rootTeam, $this->lager->id, '2026-09-30');
    $butter = $c->lines()->where('gp_id', $this->butter->id)->first();
    $z = $this->inv->zaehlenGebinde($this->rootTeam, $butter->id, '1', '12', '0,4');   // 40×250 + 12×250 + 400 g
    expect((float) $z->qty_counted)->toBe(13400.0)->and((float) $z->counted_units)->toBe(12.0);

    $saft = $c->lines()->where('gp_id', $this->saft->id)->first();
    expect((float) $this->inv->zaehlenGebinde($this->rootTeam, $saft->id, '2', '', '')->qty_counted)->toBe(12000.0);

    $z = $this->inv->zaehlen($this->rootTeam, $butter->id, '10');
    expect((float) $z->qty_counted)->toBe(10000.0)->and($z->counted_packs)->toBeNull();

    expect(fn () => $this->inv->zaehlenGebinde($this->rootTeam, $butter->id, '-1', '', ''))->toThrow(\RuntimeException::class);
    expect($this->inv->zaehlenGebinde($this->rootTeam, $butter->id, '', '', '')->qty_counted)->toBeNull();
});

it('Buchen mit „nicht gezählt = 0" setzt offene Positionen auf null Bestand', function () {
    $c = $this->inv->anlegen($this->rootTeam, $this->lager->id, '2026-09-30');
    $this->inv->zaehlen($this->rootTeam, $c->lines()->where('gp_id', $this->mehl->id)->value('id'), '8');
    $g = $this->inv->buchen($this->rootTeam, $c->id, null, true);
    expect($g->uncounted_zeroed)->toBeTrue()
        ->and((float) FoodAlchemistInventoryStock::where('gp_id', $this->butter->id)->value('qty_base'))->toBe(0.0)
        ->and((float) FoodAlchemistInventoryStock::where('gp_id', $this->mehl->id)->value('qty_base'))->toBe(8000.0);
});

it('Filter: Stellplatz, ohne Stellplatz, Zustand, Warengruppe, Status Differenz', function () {
    $kuehl = $this->ein->stellplatzAnlegen($this->rootTeam, $this->lager->id, 'Kühlhaus', 'kuehl');
    $this->ein->zuordnen($this->rootTeam, $this->lager->id, [$this->butter->id], $kuehl->id);
    $rows = $this->inv->bestand($this->rootTeam);
    $namen = fn (array $f) => array_column($this->inv->filterBestand($rows, $f), 'name');
    expect($namen(['stellplatz' => $kuehl->id]))->toBe(['Butter'])
        ->and($namen(['stellplatz' => 'ohne']))->toBe(['Erbsen', 'Mehl', 'Orangensaft'])
        ->and($namen(['zustand' => 'TK']))->toBe(['Erbsen'])
        ->and($namen(['warengruppe' => '15']))->toBe(['Orangensaft'])
        ->and($namen(['ladenhueter' => true]))->toHaveCount(4);   // nie bewegt

    $c = $this->inv->detail($this->rootTeam, $this->inv->anlegen($this->rootTeam, $this->lager->id, '2026-09-30')->id);
    $this->inv->zaehlen($this->rootTeam, $c->lines->firstWhere('gp_id', $this->mehl->id)->id, '10');     // = Soll
    $this->inv->zaehlen($this->rootTeam, $c->lines->firstWhere('gp_id', $this->butter->id)->id, '15');   // −25 %
    $c = $this->inv->detail($this->rootTeam, $c->id);
    expect($this->inv->filterZeilen($c->lines, ['status' => 'differenz'])->pluck('gp.name')->all())->toBe(['Butter'])
        ->and($this->inv->filterZeilen($c->lines, ['status' => 'offen'])->count())->toBe(2);
});

it('Team-strikt: fremdes Team kann weder Stellplätze ändern noch Grundprodukte zuordnen', function () {
    $bin = $this->ein->stellplatzAnlegen($this->rootTeam, $this->lager->id, 'Kühlhaus', 'kuehl');
    expect(fn () => $this->ein->stellplatzAendern($this->childA, $bin->id, ['name' => 'x']))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => $this->ein->zuordnen($this->childA, $this->lager->id, [$this->butter->id], $bin->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => $this->ein->stellplatzAnlegen($this->rootTeam, $this->lager->id, 'X', 'keller'))->toThrow(\RuntimeException::class, 'Unbekannte Zone');
});

it('MCP: storage_bins POST/PUT/ASSIGN/GET/DELETE + Inventur mit Gebinde zählen und „nicht gezählt = 0" buchen', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $ort = $this->lager->id;

    $kuehl = $reg->get('foodalchemist.storage_bins.POST')->execute(['location_id' => $ort, 'name' => 'Kühlhaus', 'zone' => 'kuehl'], $ctx);
    $tk = $reg->get('foodalchemist.storage_bins.POST')->execute(['location_id' => $ort, 'name' => 'TK', 'zone' => 'tk'], $ctx);
    expect($kuehl->success)->toBeTrue()
        ->and($reg->get('foodalchemist.storage_bins.POST')->execute(['location_id' => $ort, 'name' => 'X', 'zone' => 'keller'], $ctx)->success)->toBeFalse();

    $put = $reg->get('foodalchemist.storage_bins.PUT')->execute(['bin_id' => $tk->data['bin_id'], 'name' => 'TK-Raum', 'verschieben' => -1], $ctx);
    expect($put->data['name'])->toBe('TK-Raum')->and($put->data['reihenfolge'])->toBe(10);

    $auto = $reg->get('foodalchemist.storage_bins.ASSIGN')->execute(['location_id' => $ort, 'automatisch' => true], $ctx);
    expect($auto->data['einsortiert'])->toBe(2);   // Butter → Kühlhaus, Erbsen → TK (kein Trocken-/Getränke-Platz)
    $hand = $reg->get('foodalchemist.storage_bins.ASSIGN')->execute(['location_id' => $ort, 'gp_ids' => [$this->mehl->id], 'bin_id' => $kuehl->data['bin_id']], $ctx);
    expect($hand->data['geaendert'])->toBe(1);

    $get = $reg->get('foodalchemist.storage_bins.GET')->execute(['location_id' => $ort, 'artikel' => true, 'nur_ohne_stellplatz' => true], $ctx);
    expect(array_column($get->data['artikel'], 'name'))->toBe(['Orangensaft'])
        ->and(collect($get->data['stellplaetze'])->pluck('name')->all())->toBe(['TK-Raum', 'Kühlhaus']);

    $c = $reg->get('foodalchemist.inventory_counts.POST')->execute(['location_id' => $ort, 'stichtag' => '2026-09-30'], $ctx);
    $detail = $reg->get('foodalchemist.inventory_counts.GET')->execute(['count_id' => $c->data['count_id']], $ctx);
    expect(array_column($detail->data['zeilen'], 'name'))->toBe(['Erbsen', 'Butter', 'Mehl', 'Orangensaft']);   // Laufweg
    $butter = collect($detail->data['zeilen'])->firstWhere('name', 'Butter');
    expect($butter['stellplatz'])->toBe('Kühlhaus')->and($butter['gebinde'])->toMatchArray(['karton' => 'Karton', 'einheiten_je_karton' => 40.0, 'inhalt_je_einheit' => 0.25]);

    $reg->get('foodalchemist.inventory_counts.PUT')->execute(['count_id' => $c->data['count_id'], 'zaehlungen' => [['line_id' => $butter['line_id'], 'kartons' => 2, 'einheiten' => 4]]], $ctx);
    $offen = $reg->get('foodalchemist.inventory_counts.GET')->execute(['count_id' => $c->data['count_id'], 'status' => 'offen'], $ctx);
    expect($offen->data['zeilen'])->toHaveCount(3);

    $book = $reg->get('foodalchemist.inventory_counts.BOOK')->execute(['count_id' => $c->data['count_id'], 'nicht_gezaehlt_null' => true], $ctx);
    expect($book->data['nicht_gezaehlt_als_null'])->toBeTrue()
        ->and((float) FoodAlchemistInventoryStock::where('gp_id', $this->butter->id)->value('qty_base'))->toBe(21000.0)
        ->and((float) FoodAlchemistInventoryStock::where('gp_id', $this->mehl->id)->value('qty_base'))->toBe(0.0);

    $inv = $reg->get('foodalchemist.inventory.GET')->execute(['stellplatz' => (string) $kuehl->data['bin_id']], $ctx);
    expect(array_column($inv->data['bestand'], 'name'))->toBe(['Butter']);   // Mehl mit 0 fällt aus dem Bestand

    expect($reg->get('foodalchemist.storage_bins.DELETE')->execute(['bin_id' => $kuehl->data['bin_id']], new ToolContext($this->makeUser($this->childA), $this->childA))->success)->toBeFalse()
        ->and($reg->get('foodalchemist.storage_bins.DELETE')->execute(['bin_id' => $kuehl->data['bin_id']], $ctx)->success)->toBeTrue()
        ->and(FoodAlchemistStorageBinItem::where('storage_bin_id', $kuehl->data['bin_id'])->count())->toBe(0);
});

it('Oberfläche: Einrichten — Stellplatz anlegen, automatisch einsortieren, Massen-Zuordnung, Gebinde zählen, Druck je Platz', function () {
    $lw = Livewire::test(LagerIndex::class)
        ->call('reiterSetzen', 'einrichten')
        ->assertSet('lagerortId', $this->lager->id)
        ->set('neuPlatzName', 'Kühlhaus')->set('neuPlatzZone', 'kuehl')->call('platzAnlegen')
        ->set('neuPlatzName', 'Trocken')->set('neuPlatzZone', 'trocken')->call('platzAnlegen')
        ->assertSet('fehler', null)
        ->assertSee('Vorschlag: Kühlhaus')
        ->call('vorschlagUebernehmen')
        ->assertSee('einsortiert');
    $stamm = $this->ein->stammplaetze($this->rootTeam, $this->lager->id);
    expect($stamm)->toHaveCount(3);   // Erbsen (TK) ohne TK-Platz bleibt offen

    $trocken = \Platform\FoodAlchemist\Models\FoodAlchemistStorageBin::where('name', 'Trocken')->value('id');
    $lw->set('filter.stellplatz', 'ohne')->call('alleMarkieren')->assertSet('auswahl', [$this->erbsen->id])
        ->set('zielPlatzId', (string) $trocken)->call('auswahlZuordnen')->assertSee('1 Grundprodukt(e) zugeordnet');

    $lw->call('reiterSetzen', 'inventur')->set('neuLagerortId', $this->lager->id)->set('neuDatum', '2026-09-30')->call('inventurAnlegen')
        ->assertSee('1 Karton = 40 Stück');
    $zeile = FoodAlchemistInventoryCountLine::where('inventory_count_id', $lw->get('inventurId'))->where('gp_id', $this->butter->id)->first();
    $lw->call('zaehlenGebinde', $zeile->id, '1', '', '0,5')->assertSee('= 10,5 kg');

    $this->get(route('foodalchemist.lager.zaehlliste', ['count' => $lw->get('inventurId'), 'stellplatz' => $zeile->storage_bin_id]))
        ->assertOk()->assertSee('Butter')->assertDontSee('Erbsen');
});
