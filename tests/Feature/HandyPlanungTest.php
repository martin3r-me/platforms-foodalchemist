<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Livewire\Planung\RezeptImport;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Handy-Ansicht PR7 · Planung: am Handy bedienbar im Handy-Umfang (Dominique 30.10.) — drei Schritte Auftrag →
 * Ergebnis → Kontext, Auftrag nur Gericht, Basisrezept, Concept und Import; Ergebnis: Lauf abbrechen/wieder aufnehmen
 * und Schritt-Fotos, sonst lesen. Am Computer unverändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

function handyPr7()
{
    return Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(PlanungIndex::class);
}

it('am Handy: drei Schritte statt Reiterleiste, Auftrag mit Gericht/Basisrezept/Concept/Import, kein Composer, keine Ausgabe-Formen', function () {
    $lw = handyPr7()->call('schnellErstellen', 'gericht');
    $session = FoodAlchemistPlanningSession::query()->latest('id')->first();
    expect($session)->not->toBeNull();

    $lw->assertSeeHtml('data-planung-handy-schritte')
        ->assertSeeHtml('data-planung-schritt="auftrag"')->assertSeeHtml('data-planung-schritt="ergebnis"')->assertSeeHtml('data-planung-schritt="kontext"')
        ->assertSeeHtml('data-planung-handy-art="gericht"')->assertSeeHtml('data-planung-handy-art="basisrezept"')
        ->assertSeeHtml('data-planung-handy-art="concept"')->assertSeeHtml('data-planung-handy-art="import"')
        ->assertSeeHtml('data-planung-handy-kontext')->assertSeeHtml('data-planung-handy-computer')
        ->assertDontSeeHtml('data-planung-reiter="composer"')->assertDontSeeHtml('wire:key="planung-tab-composer"')
        ->assertDontSeeHtml('wire:key="planung-tab-foodbook"')->assertDontSeeHtml('data-tab-trendradar')
        ->assertDontSeeHtml('wire:click="foodbookAusBrief"')->assertDontSeeHtml('wire:click="formatAusBrief"')
        ->assertDontSeeHtml('data-frei-foodbook')->assertDontSeeHtml('data-frei-composer')->assertDontSeeHtml('data-frei-trendradar')
        ->assertSeeHtml('data-frei-gericht')->assertSeeHtml('data-frei-import')
        ->assertDontSeeHtml('data-planung-details-verwerfen')->assertDontSeeHtml('data-planung-karte-duplizieren');
});

it('am Computer unverändert: Reiterleiste mit allen Reitern, Ausgabe-Formen, Verwerfen; keine Handy-Schritte', function () {
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(PlanungIndex::class)->call('schnellErstellen', 'gericht')
        ->assertSeeHtml('data-planung-reiter="composer"')->assertSeeHtml('data-planung-reiter="foodbook"')->assertSeeHtml('data-planung-reiter="worker"')
        ->assertSeeHtml('data-frei-foodbook')->assertSeeHtml('data-planung-details-verwerfen')
        ->assertDontSeeHtml('data-planung-handy-schritte')->assertDontSeeHtml('data-planung-handy-arten')->assertDontSeeHtml('data-planung-handy-kontext');
});

it('Server-Sperre am Handy: Auftrag, Start, Abbruch, Wiederaufnahme und Schritt-Fotos ja; Freigabe, Verwerfen, Pflege, Ausgabe-Formen nein', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    foreach (['schnellErstellen', 'oeffne', 'speichern', 'goKaskade', 'kiKopf', 'leitplankenAusBriefing', 'briefDiktatUebernehmen',
        'laufAbbrechen', 'laufWiederAufnehmen', 'pruefeLauf', 'waehleKnoten', 'fotoHochladen', 'fotoUebernehmen', '_startUpload'] as $m) {
        expect(HandyLesemodus::abgewiesen(new PlanungIndex, $m))->toBeFalse($m);
    }
    foreach (['gibFrei', 'gibStufeFrei', 'verwirf', 'verwirfGeplant', 'alleVerwerfen', 'planAendern', 'clusterFreigeben', 'haengeKonzeptNach',
        'favoritPinnen', 'favoritVorschlaegeLaden', 'sourcingLueckenMelden', 'neuGenerieren', 'neuAnreichern', 'alleNeuenAnreichern', 'bilderNeu',
        'vorschlagUeberarbeiten', 'erzeugeGeplant', 'toggleZutaten', 'toggleKommentar', 'ergaenzeSubRezept', 'planungVerwerfen', 'planungDuplizieren', 'alsVorlageSpeichern',
        'loeschenVorlage', 'foodbookAusBrief', 'speisekarteAusBrief', 'speiseplanAusBrief', 'angebotAusBrief', 'formatAusBrief',
        'schnellComposer', 'schnellTrendradar', 'trendradarUebernehmen', 'composerAdd', 'composerUebernehmen', 'konformitaetPruefen'] as $m) {
        expect(HandyLesemodus::abgewiesen(new PlanungIndex, $m))->toBeTrue($m);
    }
    // Import (Foto) ist am Handy voll bedienbar: dieselbe Komponente und Upload-Prüfung wie am Computer.
    foreach (['importExtrahieren', 'importAnlegen', '_startUpload'] as $m) {
        expect(HandyLesemodus::abgewiesen(new RezeptImport, $m))->toBeFalse('RezeptImport::' . $m);
    }
    request()->cookies->set(HandyLesemodus::COOKIE, '0');
});

it('Server-Sperre wirkt: „Planung verwerfen“ vom Handy lässt die Planung stehen; am Computer wird sie verworfen', function () {
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(PlanungIndex::class)->call('schnellErstellen', 'gericht');
    $session = FoodAlchemistPlanningSession::query()->latest('id')->first();

    handyPr7()->call('planungVerwerfen', $session->id);
    expect(FoodAlchemistPlanningSession::withTrashed()->find($session->id)->trashed())->toBeFalse();

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(PlanungIndex::class)->call('planungVerwerfen', $session->id);
    expect(FoodAlchemistPlanningSession::withTrashed()->find($session->id)->trashed())->toBeTrue();   // Verwerfen = archivieren (Soft-Delete)
});

it('Team-Sicht am Handy: eine fremde Planung lässt sich nicht öffnen', function () {
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(PlanungIndex::class)->call('schnellErstellen', 'gericht');
    $session = FoodAlchemistPlanningSession::query()->latest('id')->first();

    $this->actingAs($this->makeUser(Team::create(['name' => 'Fremde Küche', 'user_id' => 1, 'personal_team' => false]), 'Fremd'));
    handyPr7()->call('oeffne', $session->id)->assertDontSeeHtml('data-planung-handy-kontext');
});
