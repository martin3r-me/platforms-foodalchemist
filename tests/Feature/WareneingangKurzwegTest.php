<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Orders\Editor as OrdersEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNote;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNoteLine;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierInvoice;
use Platform\FoodAlchemist\Services\LieferantenRechnungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Spec 75c · Kurzwege: Bestell-Editor, alte MCP-Wege und `setStatus(geliefert)` laufen über
 * Editor-Belege — die Bestellzeile bleibt die Summe ihrer Lieferscheine/Rechnungen. Dazu die
 * Freigabe-Box als Übergabe und die Übernahme des Bestands.
 */
beforeEach(function () {
    $this->seedWareneingang();
    $this->re = app(LieferantenRechnungService::class);
    // Invariante: Wareneingang an der Zeile = Summe der gebuchten Lieferschein-Positionen
    $this->summeLs = fn (int $olId) => round((float) FoodAlchemistDeliveryNoteLine::where('order_line_id', $olId)
        ->whereHas('deliveryNote', fn ($q) => $q->where('status', 'gebucht'))->sum('qty_packs'), 2);
    $this->lagerBewegungen = fn () => FoodAlchemistInventoryMovement::where('source', 'wareneingang')->count();
});

it('Editor-Menge läuft über einen Editor-Lieferschein; mit echtem Lieferschein bleibt die Zeile die Summe', function () {
    $this->orders->updateReceiptLine($this->rootTeam, $this->mehlLine->id, 8, 'Fahrer');
    $kurz = FoodAlchemistDeliveryNote::where('source', 'editor')->first();
    expect($kurz)->not->toBeNull()->and($kurz->status)->toBe('gebucht')
        ->and((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(8.0)
        ->and(($this->summeLs)($this->mehlLine->id))->toBe(8.0)
        ->and(($this->bestand)('Mehl'))->toBe(8000.0);

    // Echter Lieferschein über 2 Sack obendrauf → 10
    $ls = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-K1',
        'lines' => [['order_line_id' => $this->mehlLine->id, 'qty_packs' => 2]]], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $ls->id, $this->koch->id, []);
    expect((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(10.0)->and(($this->summeLs)($this->mehlLine->id))->toBe(10.0);

    // Editor korrigiert auf 9 → Editor-Position wird Korrektur 7, Summe bleibt Wahrheit
    $this->orders->updateReceiptLine($this->rootTeam, $this->mehlLine->id, 9);
    expect((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(9.0)
        ->and(($this->summeLs)($this->mehlLine->id))->toBe(9.0)
        ->and(($this->bestand)('Mehl'))->toBe(9000.0)
        ->and(FoodAlchemistDeliveryNote::where('source', 'editor')->count())->toBe(1);

    // Leeren nimmt nur die Editor-Erfassung zurück, der echte Lieferschein bleibt
    $this->orders->updateReceiptLine($this->rootTeam, $this->mehlLine->id, null);
    expect((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(2.0)->and(($this->bestand)('Mehl'))->toBe(2000.0);
});

it('„Geliefert" zweimal und „alles übernehmen" zweimal buchen das Lager nicht doppelt', function () {
    $this->orders->completeReceipt($this->rootTeam, $this->order1->id);
    $this->orders->completeReceipt($this->rootTeam, $this->order1->id);
    expect(($this->bestand)('Mehl'))->toBe(10000.0)->and(($this->bestand)('Zucker'))->toBe(2000.0);

    $this->orders->setStatus($this->rootTeam, $this->order2->id, OrderStatus::Delivered);
    $nachEinmal = ($this->lagerBewegungen)();
    $this->orders->setStatus($this->rootTeam, $this->order2->id, OrderStatus::Delivered);   // gleicher Status = no-op
    expect(($this->lagerBewegungen)())->toBe($nachEinmal)
        ->and(($this->bestand)('Mehl'))->toBe(15000.0)
        ->and(($this->summeLs)($this->mehl2Line->id))->toBe(5.0)
        ->and($this->order2->refresh()->status)->toBe(OrderStatus::Delivered);
});

it('Rechte am alten Weg: Betrachter darf nicht buchen, Mitglied schon — auch per MCP', function () {
    $leser = $this->makeUser($this->rootTeam, 'Leser', 'viewer');
    $this->actingAs($leser);
    expect(fn () => $this->orders->updateReceiptLine($this->rootTeam, $this->mehlLine->id, 5))->toThrow(FaRechtFehltException::class);

    $reg = app(ToolRegistry::class);
    $r = $reg->get('foodalchemist.orders.UPDATE_LINE')->execute(['line_id' => $this->mehlLine->id, 'received_qty_packs' => 5], new ToolContext($leser, $this->rootTeam));
    expect($r->success)->toBeFalse()->and($this->mehlLine->refresh()->received_qty_packs)->toBeNull();

    $ok = $reg->get('foodalchemist.orders.UPDATE_LINE')->execute(['line_id' => $this->mehlLine->id, 'received_qty_packs' => 5], new ToolContext($this->koch, $this->rootTeam));
    expect($ok->success)->toBeTrue()->and((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(5.0);
});

it('Editor-Rechnung: mit Freigeben sofort an der Zeile, als Mitglied „in Prüfung" bis zur Freigabe', function () {
    $this->orders->completeReceipt($this->rootTeam, $this->order1->id);

    // Mitglied: erfasst, Zeile bleibt leer
    $this->actingAs($this->koch);
    $this->orders->updateInvoiceLine($this->rootTeam, $this->mehlLine->id, 10, '2,10', 'Tagespreis');
    $inv = FoodAlchemistSupplierInvoice::where('source', 'editor')->first();
    expect($inv->status)->toBe('erfasst')->and($this->mehlLine->refresh()->invoice_qty_packs)->toBeNull();

    // Inhaber gibt im Wareneingang frei (Preisabweichung begründen)
    $d = $this->re->detail($this->rootTeam, $inv->id);
    $this->re->begruenden($this->rootTeam, $d['zeilen'][0]['id'], 'Tagespreis', $this->inhaber->id);
    $this->re->freigeben($this->rootTeam, $inv->id, $this->inhaber->id);
    expect((float) $this->mehlLine->refresh()->invoice_qty_packs)->toBe(10.0)->and((float) $this->mehlLine->invoice_pack_price)->toBe(2.1);

    // Inhaber direkt im Editor: sofort freigegeben (eigene Editor-Rechnung, da die erste schon freigegeben ist → wiederverwendet)
    $this->actingAs($this->inhaber);
    $this->orders->updateInvoiceLine($this->rootTeam, $this->zuckerLine->id, 2, 1, null);
    expect((float) $this->zuckerLine->refresh()->invoice_qty_packs)->toBe(2.0)
        ->and(FoodAlchemistSupplierInvoice::where('source', 'editor')->count())->toBe(1);
});

it('Freigabe-Box: Mitglied fragt an, nur Freigeben gibt frei; „Freigeben & senden" sendet den Entwurf', function () {
    $draftLine = $this->orders->addManualLine($this->rootTeam, $this->la['Butter']->id, 2, null, null, now()->addDays(9)->toDateString());
    $draft = $draftLine->order()->first();
    expect($draft->status)->toBe(OrderStatus::Draft);

    expect(fn () => $this->orders->updateApproval($this->rootTeam, $draft->id, ['approval_status' => 'approved'], $this->koch->id))
        ->toThrow(FaRechtFehltException::class, 'Freigeben');
    $this->orders->updateApproval($this->rootTeam, $draft->id, ['approval_status' => 'requested'], $this->koch->id);
    expect($draft->refresh()->approval_status)->toBe('requested');

    $this->actingAs($this->koch);
    Livewire::test(OrdersEditor::class)->call('oeffnenBearbeiten', $draft->id)
        ->assertSeeHtml('data-freigabe-box')->assertDontSeeHtml('data-freigabe-freigeben');

    $this->actingAs($this->inhaber);
    Livewire::test(OrdersEditor::class)->call('oeffnenBearbeiten', $draft->id)
        ->assertSeeHtml('data-freigabe-freigeben')
        ->call('freigebenUndSenden');
    expect($draft->refresh()->approval_status)->toBe('approved')->and($draft->status)->toBe(OrderStatus::Sent);
});

it('Bestand übernehmen: Altbestand-Belege für alte Zeilenwerte, idempotent, ohne neue Lagerbuchung', function () {
    // Alter Stand: Werte direkt an der Zeile, ohne Belege (wie vor Spec 75)
    $this->mehlLine->forceFill(['received_qty_packs' => 9, 'received_at' => now()->subDays(3), 'invoice_qty_packs' => 9, 'invoice_pack_price' => 2.0])->save();
    $this->order1->forceFill(['invoice_number' => 'ALT-1', 'invoice_date' => now()->subDays(2)->toDateString(), 'payment_status' => 'paid', 'invoice_paid_at' => now()->toDateString()])->save();
    $bewegungen = FoodAlchemistInventoryMovement::count();

    Artisan::call('foodalchemist:wareneingang-altbestand');
    expect(FoodAlchemistDeliveryNote::where('source', 'altbestand')->count())->toBe(0);   // Trockenlauf

    Artisan::call('foodalchemist:wareneingang-altbestand', ['--apply' => true]);
    Artisan::call('foodalchemist:wareneingang-altbestand', ['--apply' => true]);       // zweiter Lauf: nichts mehr offen
    $ls = FoodAlchemistDeliveryNote::where('source', 'altbestand')->get();
    $inv = FoodAlchemistSupplierInvoice::where('source', 'altbestand')->get();
    expect($ls)->toHaveCount(1)->and($inv)->toHaveCount(1)
        ->and(($this->summeLs)($this->mehlLine->id))->toBe(9.0)
        ->and($inv->first()->status)->toBe('bezahlt')->and($inv->first()->invoice_number)->toBe('ALT-1')
        ->and(FoodAlchemistInventoryMovement::count())->toBe($bewegungen)
        ->and((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(9.0);
});

it('Team-Hierarchie: Inhaber und Mitglied des Haupt-Teams buchen im Betriebs-Team (Kind), ohne dort Mitglied zu sein', function () {
    // Bestellung des Kind-Teams (Betrieb); Inhaber/Koch sind nur im Root-Team Mitglied
    $kindLine = $this->orders->addManualLine($this->childA, $this->la['Mehl']->id, 4, null, null, now()->addDays(5)->toDateString());
    $this->orders->setStatus($this->childA, (int) $kindLine->order_id, OrderStatus::Sent);
    expect(DB::table('team_user')->where('team_id', $this->childA->id)->exists())->toBeFalse();

    $this->actingAs($this->inhaber);
    $this->orders->updateReceiptLine($this->childA, $kindLine->id, 3);
    expect((float) $kindLine->refresh()->received_qty_packs)->toBe(3.0);

    // Mitglied des Haupt-Teams = Kuratieren auch im Kind-Team: echter Lieferschein im Betrieb
    $ls = $this->svc->speichern($this->childA, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'KIND-1',
        'lines' => [['order_line_id' => $kindLine->id, 'qty_packs' => 1]]], $this->koch->id);
    $this->svc->buchen($this->childA, $ls->id, $this->koch->id);
    expect((float) $kindLine->refresh()->received_qty_packs)->toBe(4.0)
        ->and($kindLine->order()->first()->status)->toBe(OrderStatus::Delivered);
});

it('Ohne angemeldeten Benutzer (Queue, Kommando) prüft der Kurzweg nicht — geliefert setzen scheitert nicht an „Lesen"', function () {
    auth()->logout();
    expect(auth()->user())->toBeNull();

    $this->orders->setStatus($this->rootTeam, $this->order1->id, OrderStatus::Delivered);
    $this->orders->updateInvoiceLine($this->rootTeam, $this->zuckerLine->id, 2, 1, null);

    expect($this->order1->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(10.0)
        ->and((float) $this->zuckerLine->refresh()->invoice_qty_packs)->toBe(2.0)   // ohne Benutzer = System → sofort freigegeben
        ->and(($this->bestand)('Mehl'))->toBe(10000.0);
});
