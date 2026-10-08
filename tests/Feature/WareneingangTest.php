<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Settings\Zugriffsrechte;
use Platform\FoodAlchemist\Livewire\Wareneingang\Index as WareneingangIndex;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNote;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\WareneingangService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Spec 75a · Wareneingang über Lieferscheine + Spec 61 Rechte-Basis.
 * Fixture: Chefs liefert Mehl und Zucker (1-kg-Sack), Hanos liefert Butter. Zwei offene
 * Chefs-Bestellungen an zwei Liefertagen → ein Lieferschein darf beide bedienen (n:m).
 */
beforeEach(function () {
    $this->seedWareneingang();
});

it('Vorbelegen: alle offenen Positionen des Lieferanten über beide Bestellungen, fremde Lieferanten nicht', function () {
    $v = $this->svc->vorbelegen($this->rootTeam, $this->chefs->id);

    expect(collect($v)->pluck('order_line_id')->all())->toEqualCanonicalizing([$this->mehlLine->id, $this->zuckerLine->id, $this->mehl2Line->id])
        ->and(collect($v)->pluck('order_id')->unique()->count())->toBe(2)
        ->and(collect($v)->firstWhere('order_line_id', $this->mehlLine->id)['offen'])->toBe(10.0);

    $erwartet = $this->svc->erwarteteLieferungen($this->rootTeam);
    expect(collect($erwartet)->pluck('order_id')->all())->toContain($this->order1->id, $this->order2->id);
});

it('Buchen: Unterlieferung bleibt offen, Lager bucht nur das Gelieferte; zweiter Lieferschein schließt ab', function () {
    $v = $this->svc->vorbelegen($this->rootTeam, $this->chefs->id, [$this->order1->id]);
    $ls = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-100',
        'lines' => ($this->positionen)($v, [$this->mehlLine->id => 8])], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $ls->id, $this->koch->id);

    expect((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(8.0)
        ->and((float) $this->zuckerLine->refresh()->received_qty_packs)->toBe(2.0)
        ->and(($this->bestand)('Mehl'))->toBe(8000.0)
        ->and($this->order1->refresh()->status)->toBe(OrderStatus::Sent)   // 2 Sack fehlen noch
        ->and($this->svc->detail($this->rootTeam, $ls->id)['abweichungen'])->toBe(1);

    // Rest kommt am nächsten Tag: Vorschlag kennt nur noch die offenen 2 Sack
    $rest = $this->svc->vorbelegen($this->rootTeam, $this->chefs->id, [$this->order1->id]);
    expect(collect($rest)->firstWhere('order_line_id', $this->mehlLine->id)['qty_packs'])->toBe(2.0);
    $ls2 = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-101',
        'lines' => [['order_line_id' => $this->mehlLine->id, 'qty_packs' => 2]]], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $ls2->id, $this->koch->id);

    expect((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(10.0)
        ->and(($this->bestand)('Mehl'))->toBe(10000.0)
        ->and($this->order1->refresh()->status)->toBe(OrderStatus::Delivered);
});

it('Ein Lieferschein bedient zwei Bestellungen desselben Lieferanten (n:m)', function () {
    $ls = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-200'], $this->koch->id);
    expect($ls->lines()->count())->toBe(3);   // ohne `lines`: alle offenen Positionen vorbelegt

    $this->svc->buchen($this->rootTeam, $ls->id, $this->koch->id);
    expect($this->order1->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and($this->order2->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and(($this->bestand)('Mehl'))->toBe(15000.0)
        ->and($this->svc->detail($this->rootTeam, $ls->id)['bestellungen'])->toHaveCount(2);
});

it('Fremder Lieferant, doppelte Position und doppelte Lieferscheinnummer werden abgelehnt', function () {
    expect(fn () => $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id,
        'lines' => [['order_line_id' => $this->butterLine->id, 'qty_packs' => 3]]], $this->koch->id))
        ->toThrow(\RuntimeException::class, 'anderen Lieferanten');
    expect(fn () => $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'lines' => [
        ['order_line_id' => $this->mehlLine->id, 'qty_packs' => 3], ['order_line_id' => $this->mehlLine->id, 'qty_packs' => 1]]], $this->koch->id))
        ->toThrow(\RuntimeException::class, 'doppelt');

    $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-1',
        'lines' => [['order_line_id' => $this->mehlLine->id, 'qty_packs' => 1]]], $this->koch->id);
    expect(fn () => $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-1',
        'lines' => [['order_line_id' => $this->zuckerLine->id, 'qty_packs' => 1]]], $this->koch->id))
        ->toThrow(\RuntimeException::class, 'schon erfasst');
});

it('Storno nimmt Wareneingang und Lager zurück; nach Abschluss der Bestellung gesperrt', function () {
    $ls = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-300',
        'lines' => [['order_line_id' => $this->mehlLine->id, 'qty_packs' => 4]]], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $ls->id, $this->koch->id);
    expect(($this->bestand)('Mehl'))->toBe(4000.0);

    $this->svc->stornieren($this->rootTeam, $ls->id, $this->koch->id);
    expect($this->mehlLine->refresh()->received_qty_packs)->toBeNull()
        ->and(($this->bestand)('Mehl'))->toBe(0.0)
        ->and($ls->refresh()->status)->toBe(FoodAlchemistDeliveryNote::STATUS_STORNIERT);

    $voll = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-301'], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $voll->id, $this->koch->id);
    expect(fn () => $this->svc->stornieren($this->rootTeam, $voll->id, $this->koch->id))
        ->toThrow(\RuntimeException::class, 'abgeschlossen');
});

it('Ware ohne Bestellung bucht einen Lagerzugang und lässt sich stornieren', function () {
    $ls = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-400',
        'lines' => [['gp_id' => $this->gp['Zucker']->id, 'menge' => '1,5', 'note' => 'Fahrer brachte extra']]], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $ls->id, $this->koch->id);

    $m = FoodAlchemistInventoryMovement::where('gp_id', $this->gp['Zucker']->id)->where('source', 'zugang')->first();
    expect($m)->not->toBeNull()
        ->and($m->reason)->toBe('ohne_bestellung')
        ->and((float) $m->qty_base)->toBe(1500.0)
        ->and(($this->bestand)('Zucker'))->toBe(1500.0)
        ->and($this->zuckerLine->refresh()->received_qty_packs)->toBeNull();

    $this->svc->stornieren($this->rootTeam, $ls->id, $this->koch->id);
    expect(($this->bestand)('Zucker'))->toBe(0.0);
});

it('Abschließen ohne Lieferscheinposition bucht 0 statt der Bestellmenge; Nachlieferung auf neue Bestellung', function () {
    $ls = $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'LS-500',
        'lines' => [['order_line_id' => $this->mehlLine->id, 'qty_packs' => 7]]], $this->koch->id);
    $this->svc->buchen($this->rootTeam, $ls->id, $this->koch->id);

    $res = $this->svc->nachlieferung($this->rootTeam, $this->order1->id, now()->addDays(6)->toDateString(), $this->koch->id);
    $nach = FoodAlchemistOrder::find($res['order_id']);

    expect($this->order1->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and((float) $this->zuckerLine->refresh()->received_qty_packs)->toBe(0.0)   // nie gekommen, nicht eingebucht
        ->and(($this->bestand)('Zucker'))->toBe(0.0)
        ->and($nach->status)->toBe(OrderStatus::Draft)
        ->and($res['total_qty_packs'])->toBe(5.0);   // 3 Sack Mehl + 2 Sack Zucker
});

it('Rechte aus der Plattform-Rolle: Betrachter liest, Mitglied bucht, Admin im Unter-Team, KI nie Freigeben', function () {
    $leser = $this->makeUser($this->rootTeam, 'Leser', 'viewer');
    $fremd = $this->makeUser($this->rootTeam, 'Fremd', null);   // gar kein Mitglied

    expect($this->rechte->rolle($leser, $this->rootTeam))->toBe(FaRolle::Lesen)
        ->and($this->rechte->rolle($fremd, $this->rootTeam))->toBe(FaRolle::Lesen)
        ->and($this->rechte->rolle($this->koch, $this->rootTeam))->toBe(FaRolle::Kuratieren)
        ->and($this->rechte->rolle($this->inhaber, $this->rootTeam))->toBe(FaRolle::Admin)
        ->and(fn () => $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id], $leser->id))
        ->toThrow(FaRechtFehltException::class, 'Kuratieren');
    expect(fn () => $this->svc->speichern($this->rootTeam, ['supplier_id' => $this->chefs->id], null))
        ->toThrow(FaRechtFehltException::class);

    // Rolle im Haupt-Team gilt im Unter-Team mindestens (vererbt nach unten)
    expect($this->rechte->rolle($this->koch, $this->childA))->toBe(FaRolle::Kuratieren)
        ->and($this->rechte->rolle($this->inhaber, $this->childA))->toBe(FaRolle::Admin);

    // Freigabe-Häkchen: nur FA-Admin setzt es, nur für Mitglieder
    expect(fn () => $this->rechte->setzeFreigabe($this->rootTeam, $this->koch, $this->koch->id, true))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->rechte->setzeFreigabe($this->rootTeam, $this->inhaber, $leser->id, true))->toThrow(\RuntimeException::class, 'Betrachter');
    $this->rechte->setzeFreigabe($this->rootTeam, $this->inhaber, $this->koch->id, true);
    expect($this->rechte->rolle($this->koch, $this->rootTeam))->toBe(FaRolle::Freigeben);

    $ki = $this->makeUser($this->rootTeam, 'KI', 'admin');
    $ki->forceFill(['type' => 'ai_user'])->save();
    expect($this->rechte->rolle($ki, $this->rootTeam))->toBe(FaRolle::Kuratieren)
        ->and(fn () => $this->rechte->setzeFreigabe($this->rootTeam, $this->inhaber, $ki->id, true))->toThrow(\RuntimeException::class, 'KI');
});

it('MCP im Lockstep: delivery_notes + team_roles registriert, Ende-zu-Ende mit FORBIDDEN für Lesen', function () {
    $reg = app(ToolRegistry::class);
    foreach (['GET', 'POST', 'PUT', 'BOOK', 'STORNO', 'BACKORDER'] as $verb) {
        expect($reg->has('foodalchemist.delivery_notes.'.$verb))->toBeTrue();
    }
    expect($reg->has('foodalchemist.team_roles.GET'))->toBeTrue()->and($reg->has('foodalchemist.team_roles.PUT'))->toBeTrue();

    $ctx = new ToolContext($this->koch, $this->rootTeam);
    $vorschlag = $reg->get('foodalchemist.delivery_notes.GET')->execute(['ansicht' => 'vorschlag', 'supplier_id' => $this->chefs->id], $ctx);
    expect($vorschlag->success)->toBeTrue()->and($vorschlag->data['positionen'])->toHaveCount(3);

    $post = $reg->get('foodalchemist.delivery_notes.POST')->execute(['supplier_id' => $this->chefs->id, 'delivery_note_number' => 'MCP-1',
        'lines' => [['order_line_id' => $this->mehlLine->id, 'qty_packs' => 10], ['order_line_id' => $this->zuckerLine->id, 'qty_packs' => 2]], 'buchen' => true], $ctx);
    expect($post->success)->toBeTrue()
        ->and($post->data['lieferschein']['status'])->toBe('gebucht')
        ->and($this->order1->refresh()->status)->toBe(OrderStatus::Delivered);

    $leser = $this->makeUser($this->rootTeam, 'Leser MCP', 'viewer');
    $verboten = $reg->get('foodalchemist.delivery_notes.POST')->execute(['supplier_id' => $this->chefs->id], new ToolContext($leser, $this->rootTeam));
    expect($verboten->success)->toBeFalse()->and($verboten->errorCode)->toBe('FORBIDDEN');

    $rollen = $reg->get('foodalchemist.team_roles.PUT')->execute(['user_id' => $this->koch->id, 'darf_freigeben' => true], new ToolContext($this->inhaber, $this->rootTeam));
    expect($rollen->success)->toBeTrue()->and($rollen->data['rolle'])->toBe('freigeben');
    expect($reg->get('foodalchemist.team_roles.PUT')->execute(['user_id' => $leser->id, 'darf_freigeben' => true], $ctx)->errorCode)->toBe('FORBIDDEN');
    $liste = $reg->get('foodalchemist.team_roles.GET')->execute([], $ctx);
    expect(collect($liste->data['mitglieder'])->firstWhere('user_id', $this->inhaber->id)['plattform_rolle'])->toBe('owner');
});

it('UI: Seite erfasst einen Lieferschein mit Vorbelegung, Beleg-Foto und bucht ihn', function () {
    Storage::fake('public');
    Storage::fake('local');
    $this->actingAs($this->koch);

    Livewire::test(WareneingangIndex::class)
        ->assertSee('Erwartete Lieferungen')
        ->assertSee('ord-'.$this->order1->id)
        ->call('erfassen', $this->chefs->id)
        ->assertSet('form.supplier_id', $this->chefs->id)
        ->assertSee('Mehl 1kg')
        ->set('form.nummer', 'UI-1')
        ->set('zeilen.'.$this->mehlLine->id.'.qty', '9')
        ->assertSet('abschliessen.'.$this->order1->id, false)   // 1 Sack fehlt → nicht automatisch abschließen
        ->set('zeilen.'.$this->mehl2Line->id.'.an', false)
        ->set('anhang', UploadedFile::fake()->image('lieferschein.jpg'))
        ->call('buchen')
        ->assertSet('fehler', null)
        ->assertSet('reiter', 'lieferscheine')
        ->assertSee('UI-1');

    $ls = FoodAlchemistDeliveryNote::where('delivery_note_number', 'UI-1')->first();
    expect($ls->status)->toBe('gebucht')
        ->and($ls->attachment_name)->toBe('lieferschein.jpg')
        ->and((float) $this->mehlLine->refresh()->received_qty_packs)->toBe(9.0)
        ->and($this->mehl2Line->refresh()->received_qty_packs)->toBeNull()
        ->and($this->order1->refresh()->status)->toBe(OrderStatus::Sent);
});

it('UI: Lesen sieht die Seite, aber keine Schreib-Knöpfe; Zugriffsrechte nur für FA-Admin änderbar', function () {
    $leser = $this->makeUser($this->rootTeam, 'Leser UI', 'viewer');
    $this->actingAs($leser);

    Livewire::test(WareneingangIndex::class)
        ->assertSeeHtml('data-we-leserecht')
        ->assertDontSeeHtml('data-we-neu')
        ->call('erfassen', $this->chefs->id)
        ->call('buchen')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Kuratieren'));

    Livewire::test(Zugriffsrechte::class)->assertDontSeeHtml('data-recht-haken')->assertSee('Betrachter')
        ->call('freigabeSetzen', $this->koch->id, true)->assertSet('fehler', fn ($f) => str_contains((string) $f, 'FA-Admin'));

    $this->actingAs($this->inhaber);
    Livewire::test(Zugriffsrechte::class)->assertSeeHtml('data-recht-haken="'.$this->koch->id.'"')
        ->assertDontSeeHtml('data-recht-haken="'.$leser->id.'"')
        ->call('freigabeSetzen', $this->koch->id, true)->assertSet('fehler', null);
    expect($this->rechte->rolle($this->koch, $this->rootTeam))->toBe(FaRolle::Freigeben);
});
