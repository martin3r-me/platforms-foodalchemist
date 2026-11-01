<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Livewire\Planung\RezeptImport;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\PlanningSessionService;
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
    // Lauf mit einem fertigen Gericht-Entwurf (status done), wie ihn die Kaskade hinterlässt.
    $this->lauf = function (string $titel, bool $gestuft = false): array {
        $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => $titel, 'brief' => 'x']);
        $recipe = $this->makeRecipe($this->rootTeam, $titel . ' Gericht', ['status' => 'draft']);
        $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => $gestuft]);
        $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);

        return [$session, $recipe, $step];
    };
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

it('Server-Sperre am Handy: Auftrag, Start, Abbruch, Wiederaufnahme, Freigabe/Anreichern und Schritt-Fotos ja; Verwerfen, Pflege, Ausgabe-Formen nein', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    foreach (['schnellErstellen', 'oeffne', 'speichern', 'goKaskade', 'kiKopf', 'leitplankenAusBriefing', 'briefDiktatUebernehmen',
        'laufAbbrechen', 'laufWiederAufnehmen', 'pruefeLauf', 'waehleKnoten', 'fotoHochladen', 'fotoUebernehmen', '_startUpload',
        'gibFrei', 'gibStufeFrei', 'clusterFreigeben', 'neuAnreichern', 'alleNeuenAnreichern'] as $m) {
        expect(HandyLesemodus::abgewiesen(new PlanungIndex, $m))->toBeFalse($m);
    }
    foreach (['verwirf', 'verwirfGeplant', 'alleVerwerfen', 'planAendern', 'haengeKonzeptNach',
        'favoritPinnen', 'favoritVorschlaegeLaden', 'sourcingLueckenMelden', 'neuGenerieren', 'bilderNeu',
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


it('Freigabe vom Handy (Dominique 30.10.): schreibt dasselbe wie am Computer, Knöpfe sichtbar, Sammel-Freigabe mit Rückfrage', function () {
    Queue::fake();
    // Gestufter Lauf: die Freigabe IST die Anreicherung (EnrichRecipeJob), grün erst danach.
    [$sHandy, $rHandy, $stepHandy] = ($this->lauf)('Handy', true);
    [$sPc, $rPc, $stepPc] = ($this->lauf)('Computer', true);

    handyPr7()->call('oeffne', $sHandy->id)
        ->assertSeeHtml('data-step-freigeben')->assertSeeHtml('data-planung-stufe-frei="gericht"')
        ->assertSeeHtml('Vorschlag freigeben und anreichern?')
        ->call('gibFrei', $stepHandy->id)->assertSet('meldung', 'Freigegeben.');
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\EnrichRecipeJob::class, 1);

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(PlanungIndex::class)->call('oeffne', $sPc->id)
        ->assertDontSeeHtml('Vorschlag freigeben und anreichern?')   // am Computer ohne Rückfrage (unverändert)
        ->call('gibFrei', $stepPc->id)->assertSet('meldung', 'Freigegeben.');

    expect($stepHandy->refresh()->status)->toBe($stepPc->refresh()->status)->toBe('freigegeben')
        ->and($rHandy->refresh()->status->value)->toBe($rPc->refresh()->status->value)->toBe('review')   // freigegeben nach Anreicherung
        // `deferred.enrich.at` ist der Zeitpunkt der Freigabe (Sekundengenau): Handy und Computer geben nacheinander frei und liegen je nach Sekundenwechsel
        // um 1 s auseinander — das war das Wackeln (7 von 12 Läufen rot). Verglichen wird alles außer dem Zeitstempel; der muss nur gesetzt sein.
        ->and(\Illuminate\Support\Arr::except($stepHandy->deferred, 'enrich.at'))->toBe(\Illuminate\Support\Arr::except($stepPc->deferred, 'enrich.at'))
        ->and($stepHandy->deferred['enrich']['at'])->not->toBeEmpty()->and($stepPc->deferred['enrich']['at'])->not->toBeEmpty();
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\EnrichRecipeJob::class, 2);   // Handy und Computer je einmal
});

it('Anreichern vom Handy stößt die Anreicherung an; Verwerfen vom Handy bleibt wirkungslos', function () {
    Queue::fake();
    [$session, $recipe, $step] = ($this->lauf)('Anreichern');
    handyPr7()->call('oeffne', $session->id)->call('gibFrei', $step->id)->call('neuAnreichern', $step->id);
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\EnrichRecipeJob::class);

    [$s2, $r2, $step2] = ($this->lauf)('Verwerfen');
    handyPr7()->call('oeffne', $s2->id)->call('verwirf', $step2->id)->call('alleVerwerfen');
    expect($step2->refresh()->status)->toBe('done')->and($r2->refresh()->trashed())->toBeFalse();
});
