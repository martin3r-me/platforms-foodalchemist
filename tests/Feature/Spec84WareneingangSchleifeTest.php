<?php

use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNote;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNoteLine;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Spec 84 C2 · Charakterisierung der Laufzeit-Schleife im Bestell-Cluster (§5.3), VOR jedem Umbau.
 *
 *   WareneingangService::abschliessen → OrderService::setStatus(Delivered) → fillReceiptFromOrder
 *     → WareneingangService::kurzwegMenge → OrderService::schreibeWareneingangAbleitung
 *
 * Die Schleife hält heute nur, weil `nichtErfassteAufNull` VOR `setStatus` alle unerfassten Zeilen auf 0 setzt. Dann
 * findet `fillReceiptFromOrder` keine Zeile mit `received_qty_packs = null` und ruft `kurzwegMenge` nicht. Diese Tests
 * halten das heutige Verhalten fest (auch das ohne Schutz), damit der Bruch der Schleife später nachweisbar nichts ändert.
 * Sie sind ein Netz, kein Urteil.
 *
 * Bestellung 1 (Chefs): Mehl 10 Sack, Zucker 2 Sack. 1 Sack = 1 kg = 1000 g Lager-Basis.
 */
beforeEach(function () {
    $this->seedWareneingang();
    $this->zugaenge = fn (int $olId) => FoodAlchemistInventoryMovement::where('source', 'wareneingang')
        ->where('order_line_id', $olId)->count();
    $this->lsSumme = fn (int $olId) => round((float) FoodAlchemistDeliveryNoteLine::where('order_line_id', $olId)
        ->whereHas('deliveryNote', fn ($q) => $q->where('status', 'gebucht'))->sum('qty_packs'), 2);
});

it('Spec 84 C2 · Lieferschein bucht nur Mehl und schließt ab: Zucker wird 0 (nicht die bestellte Menge), keine Doppelbuchung', function () {
    $ls = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-C2',
        'lines' => [['order_line_id' => $this->mehlLine->id, 'qty_packs' => 10]]], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $ls->id, $this->koch->id, [$this->order1->id]);

    $order = FoodAlchemistOrder::find($this->order1->id);
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(10.0)
        ->and((float) $this->zuckerLine->refresh()->received_qty_packs)->toBe(0.0)          // Schutz: nicht geliefert = 0
        ->and(($this->bestand)('Mehl'))->toBe(10000.0)
        ->and(($this->bestand)('Zucker'))->toBe(0.0)
        ->and(($this->lsSumme)($this->mehlLine->id))->toBe(10.0)                            // Zeile = Summe der Lieferscheine
        ->and(($this->lsSumme)($this->zuckerLine->id))->toBe(0.0)
        ->and(FoodAlchemistDeliveryNote::where('source', 'editor')->count())->toBe(1);      // die 0 steht auf dem Editor-Beleg
});

it('Spec 84 C2 · ohne Schutz (setStatus geliefert direkt): unerfasste Zeilen werden mit der BESTELLTEN Menge vorbelegt', function () {
    // Heutiges, gewolltes Verhalten des Editor-Knopfs „geliefert" (Spec 75c): Vorbelegen über den Editor-Lieferschein.
    $this->orders->setStatus($this->rootTeam, $this->order1->id, OrderStatus::Delivered);

    expect((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(10.0)
        ->and((float) $this->zuckerLine->refresh()->received_qty_packs)->toBe(2.0)
        ->and(($this->bestand)('Mehl'))->toBe(10000.0)
        ->and(($this->bestand)('Zucker'))->toBe(2000.0)
        ->and(($this->lsSumme)($this->zuckerLine->id))->toBe(2.0)
        ->and(FoodAlchemistOrder::find($this->order1->id)->status)->toBe(OrderStatus::Delivered);
});

it('Spec 84 C2 · Wiedereintritt: kurzwegMenge läuft innerhalb von setStatus, solange die Bestellung noch „gesendet" ist', function () {
    // Der Status wechselt in setStatus erst NACH fillReceiptFromOrder. Würde er vorher gesetzt, würfe kurzwegMenge
    // („nur für gesendete oder bestätigte Bestellungen") — genau diese Reihenfolge hält die Schleife heute zusammen.
    $this->orders->setStatus($this->rootTeam, $this->order1->id, OrderStatus::Delivered);

    expect(($this->zugaenge)($this->zuckerLine->id))->toBeGreaterThan(0);
    expect(fn () => $this->svc->kurzwegMenge($this->rootTeam, $this->zuckerLine->id, 1))
        ->toThrow(\RuntimeException::class, 'nur für gesendete oder bestätigte');            // nach dem Wechsel: zu
});

it('Spec 84 C2 · geliefert zweimal setzen ist ein No-op: keine zweite Vorbelegung, kein zweiter Lagerzugang', function () {
    $this->orders->setStatus($this->rootTeam, $this->order1->id, OrderStatus::Delivered);
    $zugaenge = FoodAlchemistInventoryMovement::where('source', 'wareneingang')->count();
    $bestand = ($this->bestand)('Zucker');

    $this->orders->setStatus($this->rootTeam, $this->order1->id, OrderStatus::Delivered);

    expect(FoodAlchemistInventoryMovement::where('source', 'wareneingang')->count())->toBe($zugaenge)
        ->and(($this->bestand)('Zucker'))->toBe($bestand);
});

it('Spec 84 C2 · Nachlieferung: unerfasst wird 0, der Rest geht in eine neue Bestellung, die alte ist geliefert', function () {
    $ls = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-C2N',
        'lines' => [['order_line_id' => $this->mehlLine->id, 'qty_packs' => 6]]], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $ls->id, $this->koch->id, []);
    $vorher = FoodAlchemistOrder::count();

    $this->svc->nachlieferung($this->rootTeam, $this->order1->id, now()->addDays(6)->toDateString(), $this->koch->id);

    expect(FoodAlchemistOrder::find($this->order1->id)->status)->toBe(OrderStatus::Delivered)
        ->and((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(6.0)
        ->and((float) $this->zuckerLine->refresh()->received_qty_packs)->toBe(0.0)
        ->and(($this->bestand)('Zucker'))->toBe(0.0)
        ->and(FoodAlchemistOrder::count())->toBe($vorher + 1);
});
