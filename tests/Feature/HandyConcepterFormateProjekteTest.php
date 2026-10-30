<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Concepter\Browser as ConcepterBrowser;
use Platform\FoodAlchemist\Livewire\Concepter\DetailPanel as ConcepterDetail;
use Platform\FoodAlchemist\Livewire\Formate\Browser as FormateBrowser;
use Platform\FoodAlchemist\Livewire\Formate\DetailPanel as FormateDetail;
use Platform\FoodAlchemist\Livewire\Projekte\Detail as ProjektDetail;
use Platform\FoodAlchemist\Livewire\Projekte\Index as ProjektIndex;
use Platform\FoodAlchemist\Livewire\Projekte\KundeDnaPhase;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Handy-Ansicht PR4 · Concepter, Formate, Projekte: am Handy Karten und Lesen, am Computer unverändert.
 * Projekte nur lesen (Dominique: „erstmal nur lesen außer die Planung“) — nur der Phasen-Reiter schaltet um.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $c = app(ConceptService::class);
    $this->concept = $c->create($this->rootTeam, ['name' => 'Sommerbuffet HANDY', 'class' => 'Buffet']);
    $this->paket = $c->createPaket($this->rootTeam, ['name' => 'Fingerfood-Paket HANDY']);
    $this->format = app(FormatService::class)->create($this->rootTeam, ['name' => 'Streetfood HANDY']);
});

function handyTest(string $klasse, array $params = [], array $query = [])
{
    return Livewire::withCookie(HandyLesemodus::COOKIE, '1')->withQueryParams($query)->test($klasse, $params);
}

it('Concepter am Handy: Karten mit Umschalter Concepts | Pakete, kein Anlegen; am Computer die Tabelle', function () {
    handyTest(ConcepterBrowser::class)
        ->assertSeeHtml('data-fa-handy-karte="' . $this->concept->id . '"')->assertSee('Buffet')
        ->assertSeeHtml('data-fa-handy-reiter')
        ->assertDontSee('Neues Concept')->assertDontSeeHtml('<table class="fa-table">')
        ->call('wechselTab', 'pakete')
        ->assertSeeHtml('data-fa-handy-karte="' . $this->paket->id . '"')->assertDontSeeHtml('data-fa-handy-karte="' . $this->concept->id . '"');

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(ConcepterBrowser::class)
        ->assertSee('Neues Concept')->assertSeeHtml('<table class="fa-table">')->assertDontSeeHtml('data-fa-handy-liste');
});

it('Concepter am Handy: Server-Sperre lässt Reiter, Auswahl und Filter laufen, Anlegen, Status, Editor und Detail-Aktionen nicht; ?edit= öffnet keinen Editor', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    foreach (['waehle', 'wechselTab', 'waehleKlasse', 'waehleFacette', 'filterZuruecksetzen', 'nextPage'] as $m) {
        expect(HandyLesemodus::abgewiesen(new ConcepterBrowser, $m))->toBeFalse($m);
    }
    foreach (['neu', 'bearbeite', 'statusSetzen'] as $m) {
        expect(HandyLesemodus::abgewiesen(new ConcepterBrowser, $m))->toBeTrue($m);
    }
    expect(HandyLesemodus::abgewiesen(new ConcepterDetail, '__dispatch', ['concepter-selected', ['type' => 'concepts', 'id' => 1]]))->toBeFalse();
    foreach (['alsVorlage', 'ausVorlage', 'dupliziere', 'loeschen'] as $m) {
        expect(HandyLesemodus::abgewiesen(new ConcepterDetail, $m))->toBeTrue($m);
    }
    request()->cookies->set(HandyLesemodus::COOKIE, '0');

    handyTest(ConcepterBrowser::class, [], ['edit' => $this->concept->id])
        ->assertDispatched('concepter-selected')->assertNotDispatched('concepter-editor.oeffnen');
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->withQueryParams(['edit' => $this->concept->id])->test(ConcepterBrowser::class)
        ->assertDispatched('concepter-editor.oeffnen');
});

it('Formate am Handy: Karten, kein Anlegen, Server-Sperre; am Computer die Tabelle', function () {
    handyTest(FormateBrowser::class)
        ->assertSeeHtml('data-fa-handy-karte="' . $this->format->id . '"')->assertSee('Streetfood HANDY')
        ->assertDontSee('Neues Format')->assertDontSeeHtml('<table class="fa-table">');
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(FormateBrowser::class)
        ->assertSee('Neues Format')->assertSeeHtml('<table class="fa-table">');

    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    foreach (['waehle', 'waehleStatus', 'waehleOrigin', 'waehleFacette', 'nextPage'] as $m) {
        expect(HandyLesemodus::abgewiesen(new FormateBrowser, $m))->toBeFalse($m);
    }
    foreach (['neu', 'bearbeite', 'statusSetzen'] as $m) {
        expect(HandyLesemodus::abgewiesen(new FormateBrowser, $m))->toBeTrue($m);
    }
    expect(HandyLesemodus::abgewiesen(new FormateDetail, 'zeige'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen(new FormateDetail, 'bearbeiten'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen(new FormateDetail, 'loeschen'))->toBeTrue();
});

describe('Projekte (Master-Team)', function () {
    beforeEach(function () {
        $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
        config(['foodalchemist.master_team_id' => $this->master->id]);
        MasterTeam::flush();
        $this->kurator = $this->makeUser($this->master, 'Kurator', 'member');
        $this->firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->master->id, 'name' => 'Soulkitchen HANDY GmbH', 'is_active' => true]);
        $this->projekt = app(ProjektService::class)->anlegen($this->master, $this->kurator, $this->firma->id, 'Kantine HANDY');
        app(ProjektService::class)->standortAnlegen($this->master, $this->kurator, $this->projekt->id, 'Werk Nord HANDY');
        $this->actingAs($this->kurator);
    });

    it('Liste am Handy: Karten statt Tabelle, keine Anlege-Box (auch für Kuratoren); am Computer unverändert', function () {
        handyTest(ProjektIndex::class)
            ->assertSeeHtml('data-fa-handy-karte="' . $this->projekt->id . '"')->assertSee('Kantine HANDY')->assertSee('Soulkitchen HANDY GmbH')
            ->assertDontSeeHtml('data-projekt-anlegen-box')->assertDontSeeHtml('data-projekte-tabelle');
        Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(ProjektIndex::class)
            ->assertSeeHtml('data-projekt-anlegen-box')->assertSeeHtml('data-projekte-tabelle')->assertDontSeeHtml('data-fa-handy-karte');
    });

    it('Detail am Handy: nur lesen — Phasen-Reiter schaltet um, keine Ändern-Knöpfe, Schreiben vom Server abgewiesen', function () {
        $c = handyTest(ProjektDetail::class, ['projekt' => $this->projekt->id])
            ->assertSee('Kantine HANDY')->assertDontSeeHtml('data-projekt-kopf-speichern')
            ->call('phaseSetzen', 'standort')->assertSet('phase', 'standort')
            ->assertSee('Werk Nord HANDY')->assertDontSeeHtml('data-projekt-standort-neu')->assertDontSee('Entfernen')
            ->set('kopf.ziel', 'Vom Handy')->call('kopfSpeichern')            // abgewiesen
            ->set('neuerStandort', 'Handy-Standort')->call('standortAnlegen')  // abgewiesen
            ->call('phaseAlsStand');                                           // abgewiesen
        $p = $this->projekt->fresh()->load('standorte');
        expect($p->ziel)->toBeNull()->and($p->phase)->toBe('zielbild')->and($p->standorte->pluck('name')->all())->toBe(['Werk Nord HANDY']);

        request()->cookies->set(HandyLesemodus::COOKIE, '1');
        expect(HandyLesemodus::abgewiesen(new ProjektDetail, 'phaseSetzen'))->toBeFalse();
        foreach (['kopfSpeichern', 'standortAnlegen', 'pilotSetzen', 'strukturFreigeben', 'foodbookAnlegen', 'lizenzUebernehmen', 'canvasSpeichern', 'listeHinzu'] as $m) {
            expect(HandyLesemodus::abgewiesen(new ProjektDetail, $m))->toBeTrue($m);
        }
        foreach (['canvasSpeichern', 'listeHinzu', 'weltHinzu', 'weltVerbindlichSchalten'] as $m) {
            expect(HandyLesemodus::abgewiesen(new KundeDnaPhase, $m))->toBeTrue($m);   // Kunden-DNA (Phase 3) am Handy nicht pflegbar
        }
        expect(HandyLesemodus::abgewiesen(new ProjektIndex, 'anlegen'))->toBeTrue()
            ->and(HandyLesemodus::abgewiesen(new ProjektIndex, 'firmaWaehlen'))->toBeTrue();
    });

    it('Detail am Computer unverändert: Kurator speichert den Kopf', function () {
        Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(ProjektDetail::class, ['projekt' => $this->projekt->id])
            ->assertSeeHtml('data-projekt-kopf-speichern')
            ->set('kopf.ziel', 'Am Computer')->call('kopfSpeichern')->assertSet('meldung', 'Projektkopf gespeichert.');
        expect($this->projekt->fresh()->ziel)->toBe('Am Computer');
    });
});
