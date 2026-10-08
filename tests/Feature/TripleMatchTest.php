<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Wareneingang\Abgleich;
use Platform\FoodAlchemist\Livewire\Wareneingang\Index as WareneingangIndex;
use Platform\FoodAlchemist\Livewire\Wareneingang\Rechnungen;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierInvoice;
use Platform\FoodAlchemist\Services\LieferantenRechnungService;
use Platform\FoodAlchemist\Services\TripleMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Spec 75b · Triple Match: Rechnung gegen Lieferschein und Bestellung. Freigabe nur mit Rolle
 * Freigeben, Summe = Beleg, jede Abweichung begründet. Danach Prüfwerte an der Bestellzeile.
 */
beforeEach(function () {
    $this->seedWareneingang();
    $this->re = app(LieferantenRechnungService::class);
    $this->tm = app(TripleMatchService::class);

    // Zwei Lieferscheine von Chefs: LS-1 Mehl 8 (2 fehlen) + Zucker 2, LS-2 Mehl 5 (zweite Bestellung)
    $ls1 = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-1', 'lines' => [
        ['order_line_id' => $this->mehlLine->id, 'qty_packs' => 8], ['order_line_id' => $this->zuckerLine->id, 'qty_packs' => 2]]], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $ls1->id, $this->koch->id, []);
    $ls2 = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-2', 'lines' => [
        ['order_line_id' => $this->mehl2Line->id, 'qty_packs' => 5]]], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $ls2->id, $this->koch->id, []);
    $this->dnl = fn (string $nr, int $orderLineId) => (int) \Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNoteLine::where('order_line_id', $orderLineId)
        ->whereHas('deliveryNote', fn ($q) => $q->where('delivery_note_number', $nr))->value('id');
});

it('Sammelrechnung: belegt beide Lieferscheine vor; Freigabe schreibt Menge, Preis und Rechnungskopf an die Bestellungen', function () {
    $v = $this->re->vorbelegen($this->rootTeam, $this->chefs->id);
    expect($v)->toHaveCount(3)->and(collect($v)->pluck('lieferschein')->unique()->values()->all())->toBe(['LS-1', 'LS-2']);

    // Summe: 8×2 + 2×1 + 5×2 = 28 €
    $inv = $this->re->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'invoice_number' => 'RE-900', 'total_net' => '28,00'], $this->koch->id);
    $d = $this->re->detail($this->rootTeam, $inv->id);
    expect($d['summe_positionen'])->toBe(28.0)->and($d['freigebbar'])->toBeTrue()->and($d['unbegruendet'])->toBe(0);

    $this->re->freigeben($this->rootTeam, $inv->id, $this->inhaber->id);

    expect((float) $this->mehlLine->refresh()->invoice_qty_packs)->toBe(8.0)
        ->and((float) $this->mehlLine->invoice_pack_price)->toBe(2.0)
        ->and((float) $this->mehl2Line->refresh()->invoice_qty_packs)->toBe(5.0)
        ->and($this->order1->refresh()->invoice_number)->toBe('RE-900')
        ->and($this->order2->refresh()->invoice_number)->toBe('RE-900')
        ->and($inv->refresh()->status)->toBe(FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN);

    // Lieferscheinpositionen sind abgerechnet → kein zweites Mal vorbelegt
    expect($this->re->vorbelegen($this->rootTeam, $this->chefs->id))->toBe([]);
});

it('Preis innerhalb der Toleranz passt, darüber sperrt die Freigabe bis zur Begründung', function () {
    $mk = fn (string $nr, $preis) => $this->re->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'invoice_number' => $nr,
        'lines' => [['delivery_note_line_id' => ($this->dnl)('LS-1', $this->mehlLine->id), 'qty_packs' => 8, 'pack_price' => $preis]]], $this->koch->id);

    // 2,04 € statt 2,00 €: 0,04 € ≤ 0,05 € je Gebinde → passt
    $ok = $mk('RE-T1', '2,04');
    expect($this->re->detail($this->rootTeam, $ok->id)['unbegruendet'])->toBe(0);
    $this->re->stornieren($this->rootTeam, $ok->id, $this->koch->id);   // in Prüfung = löschen

    // 2,10 € → Preisabweichung
    $teuer = $mk('RE-T2', '2,10');
    $d = $this->re->detail($this->rootTeam, $teuer->id);
    expect($d['zeilen'][0]['befund']['re'])->toBe('preis')
        ->and($d['zeilen'][0]['befund']['delta_eur'])->toBe(0.8)
        ->and($d['freigebbar'])->toBeFalse();
    expect(fn () => $this->re->freigeben($this->rootTeam, $teuer->id, $this->inhaber->id))->toThrow(\RuntimeException::class, 'ohne Begründung');

    $this->re->begruenden($this->rootTeam, $d['zeilen'][0]['id'], 'Preiserhöhung ab 1.10. lt. Mail', $this->koch->id);
    $this->re->freigeben($this->rootTeam, $teuer->id, $this->inhaber->id);
    expect((float) $this->mehlLine->refresh()->invoice_pack_price)->toBe(2.1);
});

it('Summe laut Beleg muss stimmen — Fracht als Nebenkosten gleicht aus', function () {
    $inv = $this->re->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'invoice_number' => 'RE-901', 'total_net' => 33,
        'delivery_note_ids' => []], $this->koch->id);
    expect(fn () => $this->re->freigeben($this->rootTeam, $inv->id, $this->inhaber->id))->toThrow(\RuntimeException::class, 'Summe der Positionen');

    $zeilen = collect($this->re->detail($this->rootTeam, $inv->id)['zeilen'])
        ->map(fn ($z) => ['delivery_note_line_id' => $z['delivery_note_line_id'], 'qty_packs' => $z['qty_packs'], 'pack_price' => $z['pack_price']])
        ->push(['art' => 'fracht', 'line_net' => 5])->all();
    $this->re->speichern($this->rootTeam, ['lines' => $zeilen], $this->koch->id, $inv->id);
    $this->re->freigeben($this->rootTeam, $inv->id, $this->inhaber->id);
    expect($inv->refresh()->status)->toBe(FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN);
});

it('Rechte: Kuratieren erfasst, aber nur Freigeben gibt frei; Storno setzt die Prüfwerte zurück', function () {
    $inv = $this->re->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'invoice_number' => 'RE-902'], $this->koch->id);
    expect(fn () => $this->re->freigeben($this->rootTeam, $inv->id, $this->koch->id))->toThrow(FaRechtFehltException::class, 'Freigeben');

    $buero = $this->makeUser($this->rootTeam, 'Buero', 'member');
    $this->rechte->setzeFreigabe($this->rootTeam, $this->inhaber, $buero->id, true);
    $this->re->freigeben($this->rootTeam, $inv->id, $buero->id);
    expect((float) $this->zuckerLine->refresh()->invoice_qty_packs)->toBe(2.0);

    $this->re->bezahlt($this->rootTeam, $inv->id, '2026-10-20', $buero->id);
    expect($this->order1->refresh()->payment_status)->toBe('paid')
        ->and(fn () => $this->re->stornieren($this->rootTeam, $inv->id, $buero->id))->toThrow(\RuntimeException::class, 'Bezahlte');

    $inv2 = $this->re->speichern($this->rootTeam, ['supplier_id' => $this->hanos->id, 'invoice_number' => 'H-1',
        'lines' => [['order_line_id' => $this->butterLine->id, 'qty_packs' => 3, 'pack_price' => 12, 'begruendung' => 'Lieferung folgt']]], $this->koch->id);
    $this->re->freigeben($this->rootTeam, $inv2->id, $buero->id);
    expect((float) $this->butterLine->refresh()->invoice_qty_packs)->toBe(3.0);
    $this->re->stornieren($this->rootTeam, $inv2->id, $buero->id);
    expect($this->butterLine->refresh()->invoice_qty_packs)->toBeNull();
});

it('Abgleich: berechnet-nicht-geliefert ganz oben, Unterlieferung mit passender Rechnung ist ok, Reklamation aus dem Befund', function () {
    // Butter nie geliefert, aber berechnet
    $this->re->speichern($this->rootTeam, ['supplier_id' => $this->hanos->id, 'invoice_number' => 'H-2',
        'lines' => [['order_line_id' => $this->butterLine->id, 'qty_packs' => 3, 'pack_price' => 12]]], $this->koch->id);
    // Mehl: geliefert 8, berechnet 8 → passt trotz Unterlieferung
    $this->re->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'invoice_number' => 'RE-903',
        'lines' => [['delivery_note_line_id' => ($this->dnl)('LS-1', $this->mehlLine->id), 'qty_packs' => 8, 'pack_price' => 2]]], $this->koch->id);

    $a = $this->tm->abgleich($this->rootTeam);
    $erste = $a['zeilen'][0];
    $mehl = collect($a['zeilen'])->firstWhere('order_line_id', $this->mehlLine->id);
    expect($erste['order_line_id'])->toBe($this->butterLine->id)
        ->and($erste['status'])->toBe('nicht_geliefert')
        ->and($erste['delta_eur'])->toBe(36.0)
        ->and($mehl['status'])->toBe('ok')
        ->and($mehl['ls'])->toBe('zu_wenig')
        ->and($a['kpis']['rechnungen_in_pruefung'])->toBe(2);

    $this->re->reklamieren($this->rootTeam, $this->butterLine->id, ['claim_qty_packs' => 3, 'credit_expected_net' => 36, 'claim_note' => 'nie geliefert'], $this->koch->id);
    expect($this->tm->abgleich($this->rootTeam)['kpis']['gutschriften_erwartet_eur'])->toBe(36.0);
});

it('MCP im Lockstep: supplier_invoices + triple_match, Freigabe ohne Rolle = FORBIDDEN', function () {
    $reg = app(ToolRegistry::class);
    foreach (['GET', 'POST', 'PUT', 'APPROVE', 'PAY', 'STORNO'] as $verb) {
        expect($reg->has('foodalchemist.supplier_invoices.'.$verb))->toBeTrue();
    }
    expect($reg->has('foodalchemist.triple_match.GET'))->toBeTrue()->and($reg->has('foodalchemist.triple_match.PUT'))->toBeTrue()
        ->and($reg->get('foodalchemist.triple_match.GET')->getMetadata()['read_only'])->toBeTrue()
        ->and($reg->get('foodalchemist.triple_match.PUT')->getMetadata()['read_only'])->toBeFalse();

    $koch = new ToolContext($this->koch, $this->rootTeam);
    $post = $reg->get('foodalchemist.supplier_invoices.POST')->execute(['supplier_id' => $this->chefs->id, 'invoice_number' => 'MCP-RE', 'total_net' => 28], $koch);
    expect($post->success)->toBeTrue()->and($post->data['rechnung']['freigebbar'])->toBeTrue();

    $verboten = $reg->get('foodalchemist.supplier_invoices.APPROVE')->execute(['id' => $post->data['rechnung']['id']], $koch);
    expect($verboten->success)->toBeFalse()->and($verboten->errorCode)->toBe('FORBIDDEN');

    $ok = $reg->get('foodalchemist.supplier_invoices.APPROVE')->execute(['id' => $post->data['rechnung']['id']], new ToolContext($this->inhaber, $this->rootTeam));
    expect($ok->success)->toBeTrue()->and($ok->data['rechnung']['status'])->toBe('freigegeben');

    expect($reg->get('foodalchemist.triple_match.PUT')->execute(['toleranz_pct' => 1], $koch)->errorCode)->toBe('FORBIDDEN');
    $tol = $reg->get('foodalchemist.triple_match.PUT')->execute(['toleranz_pct' => '1,0'], new ToolContext($this->inhaber, $this->rootTeam));
    expect($tol->data['toleranz']['pct'])->toBe(1.0);
    $abgleich = $reg->get('foodalchemist.triple_match.GET')->execute(['nur_abweichung' => false], $koch);
    expect($abgleich->success)->toBeTrue()->and($abgleich->data['zeilen'])->not->toBeEmpty();

});

it('UI: Reiter Rechnungen erfasst aus Lieferscheinen, Freigeben nur mit Rolle; Abgleich zeigt den Befund', function () {
    $this->actingAs($this->koch);
    Livewire::test(WareneingangIndex::class)->call('reiterSetzen', 'rechnungen')->assertSeeHtml('data-we-rechnungen');

    Livewire::test(Rechnungen::class)
        ->call('neu')
        ->call('lieferantWaehlen', $this->chefs->id)
        ->assertSee('LS-1')
        ->set('form.nummer', 'UI-RE')
        ->set('form.total', '30')
        ->set('zeilen.0.preis', '2,25')       // Mehl 8 × 2,25 statt 2,00 → Preisabweichung
        ->assertSeeHtml('data-re-summe-')
        ->call('speichern')
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-re-detail')
        ->assertSeeHtml('data-re-befund="preis"')
        ->assertDontSeeHtml('data-re-freigeben');   // Koch = Kuratieren

    $inv = FoodAlchemistSupplierInvoice::where('invoice_number', 'UI-RE')->first();
    expect((float) $inv->lines->sum('line_net'))->toBe(30.0);

    $this->actingAs($this->inhaber);
    $line = $inv->lines->firstWhere('order_line_id', $this->mehlLine->id);
    Livewire::test(Rechnungen::class)
        ->call('umschalten', $inv->id)
        ->assertSeeHtml('data-re-freigeben')
        ->set('begruendung.'.$line->id, 'Tagespreis lt. Fahrer')
        ->call('begruenden', $line->id)
        ->call('freigeben', $inv->id)
        ->assertSet('fehler', null);
    expect($inv->refresh()->status)->toBe('freigegeben')->and((float) $this->mehlLine->refresh()->invoice_pack_price)->toBe(2.25);

    Livewire::test(Abgleich::class)
        ->assertSeeHtml('data-ab-zeile="'.$this->mehlLine->id.'"')
        ->assertSeeHtml('data-ab-status="preis"')
        ->call('reklamieren', $this->mehlLine->id)
        ->assertSeeHtml('data-ab-hinweis');
    expect($this->mehlLine->refresh()->claim_status)->toBe('credit_expected')
        ->and((float) $this->mehlLine->credit_expected_net)->toBe(2.0);
});
