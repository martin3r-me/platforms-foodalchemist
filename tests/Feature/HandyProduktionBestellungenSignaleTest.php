<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Jobs\SignalFixJob;
use Platform\FoodAlchemist\Livewire\Orders\DetailPanel as BestellDetail;
use Platform\FoodAlchemist\Livewire\Orders\Index as Bestellungen;
use Platform\FoodAlchemist\Livewire\Produktion\Browser as ProduktionBrowser;
use Platform\FoodAlchemist\Livewire\Produktion\DetailPanel as ProduktionDetail;
use Platform\FoodAlchemist\Livewire\ReviewQueue;
use Platform\FoodAlchemist\Livewire\Signale\DetailPanel as SignalDetail;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Handy-Ansicht PR5 · Produktion, Bestellungen, Signale: am Handy Karten und Lesen, am Computer unverändert.
 * Signale: „abhaken“ (erledigt / wieder öffnen) und KI-Fix nur, wenn config('foodalchemist.handy.aendern_frei') sie nennt
 * (Standard seit Dominique 30.10.: an; zentral abschaltbar).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->auftrag = FoodAlchemistProductionOrder::create(['team_id' => $this->rootTeam->id, 'name' => 'Hochzeit HANDY', 'production_date' => now()->toDateString(), 'status' => 'planned']);
    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Metro HANDY']);
    $this->bestellung = FoodAlchemistOrder::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->lieferant->id, 'status' => 'draft',
        'desired_delivery_date' => now()->addDay()->toDateString()]);
    $this->signal = app(SignalService::class)->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Marge unter Ziel HANDY');
});

function handyPr5(string $klasse, array $query = [])
{
    return Livewire::withCookie(HandyLesemodus::COOKIE, '1')->withQueryParams($query)->test($klasse);
}

it('Produktion am Handy: Karten (Name · Datum · Status), kein Anlegen, kein Tagesplan-Knopf; am Computer die Tabelle', function () {
    handyPr5(ProduktionBrowser::class)
        ->assertSeeHtml('data-fa-handy-karte="' . $this->auftrag->id . '"')->assertSee('Hochzeit HANDY')
        ->assertDontSeeHtml('data-produktion-anlegen')->assertDontSee('Tagesplan öffnen')->assertDontSeeHtml('<table class="fa-table">')
        ->set('suche', 'gibtsnicht')->assertDontSeeHtml('data-fa-handy-karte="' . $this->auftrag->id . '"');   // Suchfeld = $suche
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(ProduktionBrowser::class)
        ->assertSeeHtml('data-produktion-anlegen')->assertDontSeeHtml('data-fa-handy-liste');
});

it('Produktion am Handy: Server-Sperre — Auswahl und Filter ja, Anlegen, Status, Notiz und Materialbedarf nein', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    foreach (['waehle', 'waehleStatus', 'waehleZeitraum', 'waehleAnsicht', 'nextPage'] as $m) {
        expect(HandyLesemodus::abgewiesen(new ProduktionBrowser, $m))->toBeFalse($m);
    }
    expect(HandyLesemodus::abgewiesen(new ProduktionBrowser, 'neuerAuftrag'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen(new ProduktionDetail, 'waehle'))->toBeFalse();
    foreach (['setStatus', 'updateLineNote', 'materialbedarfFreigeben'] as $m) {
        expect(HandyLesemodus::abgewiesen(new ProduktionDetail, $m))->toBeTrue($m);
    }
    request()->cookies->set(HandyLesemodus::COOKIE, '0');

    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(ProduktionDetail::class, ['orderId' => $this->auftrag->id])->call('setStatus', 'cancelled');
    expect($this->auftrag->fresh()->status->value)->toBe('planned');
});

it('Bestellungen am Handy: nur die Bestellungen als Karten nach Liefertag, Antippen öffnet das Detail ohne Editor; keine Runden/Versand', function () {
    handyPr5(Bestellungen::class)
        ->assertSeeHtml('data-fa-handy-bestellungen')
        ->assertSeeHtml('data-fa-handy-karte="' . $this->bestellung->id . '"')->assertSee('Metro HANDY')
        ->assertDontSee('Neue Bestellrunde')->assertDontSee('Weitere Aktionen')->assertDontSeeHtml('<table class="fa-table">')
        ->call('handyOeffnen', $this->bestellung->id)
        ->assertSet('selectedOrderId', $this->bestellung->id)->assertNotDispatched('orders-editor.bearbeiten');
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(Bestellungen::class)
        ->assertSee('Neue Bestellrunde')->assertDontSeeHtml('data-fa-handy-bestellungen');
});

it('Bestellungen am Handy: Server-Sperre — Öffnen, Zeitraum ja; Editor, Versand, Auslösen, Stornieren, Löschen nein', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    foreach (['handyOeffnen', 'waehleZeitraum', 'aktualisiere'] as $m) {
        expect(HandyLesemodus::abgewiesen(new Bestellungen, $m))->toBeFalse($m);
    }
    foreach (['oeffnen', 'neueBestellung', 'rundeVersenden', 'versandfaehigeAusloesen', 'auswahlAusloesen', 'auswahlStornieren', 'leereEntwuerfeLoeschen', 'rundeLoeschen', 'ausgewaehlteBedarfePlanen'] as $m) {
        expect(HandyLesemodus::abgewiesen(new Bestellungen, $m))->toBeTrue($m);
    }
    expect(HandyLesemodus::abgewiesen(new BestellDetail, 'waehle'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen(new BestellDetail, 'bearbeiten'))->toBeTrue();
});

it('Signale am Handy: lesbar ohne Läufe, KI, Ignorieren, Übernehmen und Begriffe-lehren; Server lehnt ab', function () {
    config(['foodalchemist.handy.aendern_frei' => []]);
    handyPr5(ReviewQueue::class)
        ->assertSee('Marge unter Ziel HANDY')
        ->assertDontSeeHtml('data-rq-laeufe')->assertDontSeeHtml('data-rq-befunde')
        ->assertDontSeeHtml('data-rq-sig-erledigt')->assertDontSeeHtml('data-rq-sig-ignorieren')->assertDontSee('KI erledigen lassen')
        ->assertDontSeeHtml('data-rq-terminologie')
        ->assertSeeHtml('data-signal-reinschauen="' . $this->signal->id . '"')                 // Lesen: Betroffene ansehen
        ->call('signalErledigt', $this->signal->id)->call('signalIgnorieren', $this->signal->id)->call('detektorLaufen');
    expect($this->signal->fresh()->status->value)->toBe('offen');

    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    foreach (['setTab', 'setSignalStatus', 'setSignalTyp'] as $m) {
        expect(HandyLesemodus::abgewiesen(new ReviewQueue, $m))->toBeFalse($m);
    }
    foreach (['signalErledigt', 'signalWiederOeffnen', 'signalIgnorieren', 'kiFixAusfuehren', 'toggleKiPanel', 'matchUebernehmen', 'bulkUebernehmen', 'terminologieAlias', 'detektorLaufen', 'befundeLaufen'] as $m) {
        expect(HandyLesemodus::abgewiesen(new ReviewQueue, $m))->toBeTrue($m);
    }
    foreach (['zeige', 'objektWaehlen', 'setSort', 'signalOeffnen'] as $m) {
        expect(HandyLesemodus::abgewiesen(new SignalDetail, $m))->toBeFalse($m);
    }
    foreach (['rezeptUebernahmePruefen', 'rezeptUebernahmeAusfuehren', 'bausteinUebernahmeAusfuehren', 'policySpeichern', 'policyEntfernen', 'policyFormUmschalten'] as $m) {
        expect(HandyLesemodus::abgewiesen(new SignalDetail, $m))->toBeTrue($m);
    }
});

it('Abhaken am Handy über aendern_frei: an → Erledigt und Wieder öffnen sichtbar und erlaubt, Ignorieren weiter nicht; aus → gesperrt', function () {
    config(['foodalchemist.handy.aendern_frei' => ['signalErledigt', 'signalWiederOeffnen']]);
    $c = handyPr5(ReviewQueue::class)
        ->assertSeeHtml('data-rq-sig-erledigt="' . $this->signal->id . '"')->assertDontSeeHtml('data-rq-sig-ignorieren')
        ->call('signalErledigt', $this->signal->id);
    expect($this->signal->fresh()->status->value)->toBe('erledigt');
    $c->call('setSignalStatus', 'erledigt')->assertSeeHtml('data-rq-sig-wieder="' . $this->signal->id . '"')
        ->call('signalWiederOeffnen', $this->signal->id);
    expect($this->signal->fresh()->status->value)->toBe('offen');
    $c->call('signalIgnorieren', $this->signal->id);
    expect($this->signal->fresh()->status->value)->toBe('offen');

    config(['foodalchemist.handy.aendern_frei' => []]);
    handyPr5(ReviewQueue::class)->assertDontSeeHtml('data-rq-sig-erledigt')->call('signalErledigt', $this->signal->id);
    expect($this->signal->fresh()->status->value)->toBe('offen');
});

it('Team-Sicht am Handy: ein fremdes Team sieht weder Aufträge, Bestellungen noch Signale', function () {
    $fremd = Team::create(['name' => 'Fremder Kunde', 'user_id' => 1, 'personal_team' => false]);
    $this->actingAs($this->makeUser($fremd, 'Fremd'));
    handyPr5(ProduktionBrowser::class)->assertDontSee('Hochzeit HANDY');
    handyPr5(Bestellungen::class)->assertDontSee('Metro HANDY');
    handyPr5(ReviewQueue::class)->assertDontSee('Marge unter Ziel HANDY');
});

it('Signale am Computer unverändert: Läufe, Erledigt, Ignorieren, Begriffe lehren sichtbar', function () {
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(ReviewQueue::class)
        ->assertSeeHtml('data-rq-laeufe')->assertSeeHtml('data-rq-sig-erledigt="' . $this->signal->id . '"')->assertSeeHtml('data-rq-sig-ignorieren');
});

it('Standard-Freischaltung am Handy: genau Abhaken, KI-Fix und KI-Werte bestätigen (Dominique 30.10.)', function () {
    expect(config('foodalchemist.handy.aendern_frei'))->toBe(['signalErledigt', 'signalWiederOeffnen', 'toggleKiPanel', 'kiFixAusfuehren', 'bulkUebernehmen', 'bulkVerwerfen', 'handyFotoSpeichern']);   // + Fotos am Handy (#1003 Fotos)
});

it('KI-Fix am Handy (Standard an): Panel über die ganze Breite, „Automatisch beheben“ mit Bestätigung, derselbe Job wie am Desktop; Ignorieren und Läufe weiter gesperrt', function () {
    Queue::fake();
    $this->makeRecipe($this->rootTeam, 'Fond: braun HANDY');
    $det = app(SignalService::class)->erzeuge($this->rootTeam, SignalTyp::EkKetteUnvollstaendig, SignalSeverity::Warnung, 'Basisrezepte ohne EK HANDY', [
        'dedup_key' => 'dq-br-ek-null-handy', 'source' => 'data-quality', 'payload' => ['metrik' => 'br_ek_null', 'anzahl' => 1],
    ]);

    handyPr5(ReviewQueue::class)
        ->assertSee('KI erledigen lassen')
        ->call('toggleKiPanel', $det->id)->assertSet('kiPanelId', $det->id)
        ->assertSeeHtml('data-rq-ki-fix="' . $det->id . '"')->assertSee('Automatisch beheben? Auto-Fix · betrifft 1 Objekte.')
        ->assertDontSeeHtml('data-rq-sig-ignorieren')->assertDontSeeHtml('data-rq-laeufe')
        ->call('kiFixAusfuehren', $det->id)->assertSet('fehler', null)
        ->call('signalIgnorieren', $det->id);
    Queue::assertPushed(SignalFixJob::class, fn ($job) => true);
    expect($det->fresh()->status->value)->toBe('offen');

    // Am Computer: derselbe Knopf ohne Bestätigungstext (unverändert)
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(ReviewQueue::class)->call('toggleKiPanel', $det->id)
        ->assertSeeHtml('data-rq-ki-fix="' . $det->id . '"')->assertDontSee('Automatisch beheben? Auto-Fix');
});

it('KI-Fix am Handy aus (aendern_frei leer): kein Knopf, Server lehnt toggleKiPanel und kiFixAusfuehren ab', function () {
    Queue::fake();
    config(['foodalchemist.handy.aendern_frei' => []]);
    $det = app(SignalService::class)->erzeuge($this->rootTeam, SignalTyp::EkKetteUnvollstaendig, SignalSeverity::Warnung, 'Basisrezepte ohne EK HANDY', [
        'dedup_key' => 'dq-br-ek-null-handy-aus', 'source' => 'data-quality', 'payload' => ['metrik' => 'br_ek_null', 'anzahl' => 1],
    ]);
    handyPr5(ReviewQueue::class)
        ->assertDontSee('KI erledigen lassen')
        ->call('toggleKiPanel', $det->id)->assertSet('kiPanelId', null)
        ->call('kiFixAusfuehren', $det->id);
    Queue::assertNotPushed(SignalFixJob::class);
});

function bulkVorschlag($team, $rezept, string $wert): FoodAlchemistBulkProposal
{
    $run = DB::table('foodalchemist_bulk_runs')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => $team->id, 'status' => 'done', 'created_at' => now(), 'updated_at' => now()]);

    return FoodAlchemistBulkProposal::create(['team_id' => $team->id, 'run_id' => $run, 'recipe_id' => $rezept->id, 'field' => 'description',
        'value' => $wert, 'confidence' => 0.82, 'reasoning' => 'Passt zur Zutatenliste HANDY', 'status' => 'offen']);
}

it('Zum Bestätigen am Handy (Standard an): Karte mit Rezept, Feld, bisher → neu, Begründung; Übernehmen mit Bestätigung schreibt wie am Desktop, Verwerfen ohne', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Senfsauce HANDY', ['description' => 'Alt-Text HANDY']);
    $p1 = bulkVorschlag($this->rootTeam, $r, 'Neu-Text HANDY');
    $c = handyPr5(ReviewQueue::class, ['tab' => 'vorschlaege'])
        ->assertSeeHtml('data-rq-bulk-karte="' . $p1->id . '"')
        ->assertSee('Senfsauce HANDY')->assertSee('Beschreibung')->assertSee('Alt-Text HANDY')->assertSee('Neu-Text HANDY')->assertSee('Passt zur Zutatenliste HANDY')
        ->assertSeeHtml('wire:confirm="' . e('Wert „Neu-Text HANDY“ für Beschreibung an Senfsauce HANDY übernehmen?') . '"')
        ->assertDontSeeHtml('data-rq-match-ok')
        ->call('bulkUebernehmen', $p1->id);
    $r->refresh();
    expect($r->description)->toBe('Neu-Text HANDY')->and($r->description_source)->toBe('ki')
        ->and($p1->fresh()->status->value)->toBe('uebernommen');

    $p2 = bulkVorschlag($this->rootTeam, $r, 'Noch ein Text');
    $c->call('bulkVerwerfen', $p2->id);
    expect($p2->fresh()->status->value)->not->toBe('offen')->and($r->fresh()->description)->toBe('Neu-Text HANDY');
});

it('Zum Bestätigen am Handy aus (ohne bulk* in aendern_frei): keine Knöpfe, Server lehnt ab; match* immer gesperrt', function () {
    config(['foodalchemist.handy.aendern_frei' => ['signalErledigt']]);
    $r = $this->makeRecipe($this->rootTeam, 'Senfsauce HANDY', ['description' => 'Alt-Text HANDY']);
    $p = bulkVorschlag($this->rootTeam, $r, 'Neu-Text HANDY');
    handyPr5(ReviewQueue::class, ['tab' => 'vorschlaege'])
        ->assertSeeHtml('data-rq-bulk-karte="' . $p->id . '"')->assertDontSeeHtml('data-rq-bulk-ok')->assertDontSeeHtml('data-rq-bulk-nein')
        ->call('bulkUebernehmen', $p->id)->call('bulkVerwerfen', $p->id);
    expect($p->fresh()->status->value)->toBe('offen')->and($r->fresh()->description)->toBe('Alt-Text HANDY');

    config(['foodalchemist.handy.aendern_frei' => ['signalErledigt', 'signalWiederOeffnen', 'toggleKiPanel', 'kiFixAusfuehren', 'bulkUebernehmen', 'bulkVerwerfen']]);
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    expect(HandyLesemodus::abgewiesen(new ReviewQueue, 'matchUebernehmen'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen(new ReviewQueue, 'matchVerwerfen'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen(new ReviewQueue, 'bulkUebernehmen'))->toBeFalse();
});

it('Zum Bestätigen Team-Sicht: ein fremdes Team sieht den KI-Vorschlag nicht und kann ihn nicht übernehmen', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Senfsauce HANDY', ['description' => 'Alt-Text HANDY']);
    $p = bulkVorschlag($this->rootTeam, $r, 'Neu-Text HANDY');
    $this->actingAs($this->makeUser(Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]), 'Fremd'));
    handyPr5(ReviewQueue::class, ['tab' => 'vorschlaege'])->assertDontSeeHtml('data-rq-bulk-karte="' . $p->id . '"')
        ->call('bulkUebernehmen', $p->id);
    expect($p->fresh()->status->value)->toBe('offen')->and($r->fresh()->description)->toBe('Alt-Text HANDY');
});
