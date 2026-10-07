<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Livewire\ReviewQueue;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * fa-pass 2026-10-05 — Signale-Seite nach Bereich und Schwere (reine Darstellung).
 * Sperrt: Kritisch steht oben (eigener Abschnitt), die Drift ist keine Einzelmeldung
 * zwischen den Befunden, sondern Abschnitt „Entwicklung" ganz unten und Kennzahl am Bereich.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Bereich User'));
    $this->signals = app(SignalService::class);
});

it('jeder Signal-Typ hat einen Bereich (kein Typ fällt durch)', function () {
    foreach (SignalTyp::cases() as $typ) {
        $b = ReviewQueue::bereichFuer($typ);
        expect($b === 'entwicklung' || array_key_exists($b, ReviewQueue::BEREICHE))->toBeTrue();
    }
    expect(ReviewQueue::bereichFuer(SignalTyp::QualitaetDrift))->toBe('entwicklung')
        ->and(ReviewQueue::bereichFuer(SignalTyp::VeraltetePreise))->toBe('preise')
        ->and(ReviewQueue::bereichFuer(SignalTyp::RezeptAllergenUnbelastbar))->toBe('deklaration')
        ->and(ReviewQueue::bereichFuer(SignalTyp::RezeptMengenLuecke))->toBe('rezepte')
        ->and(ReviewQueue::bereichFuer(SignalTyp::FoodbookStale))->toBe('konzepte');
});

it('ordnet: Kritisch zuerst, dann Bereiche, Entwicklung zuletzt; die Drift zählt am Bereich', function () {
    $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Info, 'Preise alt', ['dedup_key' => 'a']);
    $krit = $this->signals->erzeuge($this->rootTeam, SignalTyp::RezeptMengenLuecke, SignalSeverity::Kritisch, 'Mengen fehlen', ['dedup_key' => 'b']);
    $this->signals->erzeuge($this->rootTeam, SignalTyp::RezeptOhneZubereitung, SignalSeverity::Warnung, 'Ohne Zubereitung', ['dedup_key' => 'c']);
    $drift = $this->signals->erzeuge($this->rootTeam, SignalTyp::QualitaetDrift, SignalSeverity::Warnung, 'Rezept mit Mengen-Lücke: 1 → 5 (+4)', [
        'dedup_key' => 'drift:signals:rezept_mengen_luecke',
        'payload' => ['drift_metric' => 'rezept_mengen_luecke', 'drift_source' => 'signals', 'delta' => 4],
    ]);

    $lw = Livewire::test(ReviewQueue::class)->set('tab', 'signale');
    $sektionen = $lw->viewData('signalSektionen');

    expect(array_column($sektionen, 'key'))->toBe(['kritisch', 'preise', 'rezepte', 'entwicklung'])
        ->and($sektionen[0]['items']->pluck('id')->all())->toBe([$krit->id])
        // das kritische Signal steht nicht doppelt im Bereich
        ->and($sektionen[2]['items']->pluck('title')->all())->toBe(['Ohne Zubereitung'])
        ->and($sektionen[2]['drift'])->toBe(1)
        ->and($sektionen[3]['items']->pluck('id')->all())->toBe([$drift->id]);

    // Die Drift ist aus der Einzelliste raus (Paginator), steht aber unten.
    expect($lw->viewData('signale')->total())->toBe(3);
    $lw->assertSeeHtml('data-rq-sektion="entwicklung"')->assertSee('Mengen fehlen');

    // Der Typ-Filter holt die Drift zurück in die Liste.
    $lw->call('setSignalTyp', SignalTyp::QualitaetDrift->value);
    expect($lw->viewData('signale')->total())->toBe(1)
        ->and(array_column($lw->viewData('signalSektionen'), 'key'))->toBe(['entwicklung']);
});

it('rendert alle Reiter und andere Status ohne Fehler', function () {
    $s = $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Marge zu knapp');
    $lw = Livewire::test(ReviewQueue::class);
    foreach (['ueberblick', 'vorschlaege', 'pflege', 'signale'] as $tab) {
        $lw->call('setTab', $tab)->assertOk()->assertSeeHtml('data-rq-tab="' . $tab . '"');
    }
    $lw->call('setTab', 'ueberblick')->assertSee('Marge zu knapp')->assertSeeHtml('data-rq-bereiche');
    $lw->call('setTab', 'signale')->call('signalErledigt', $s->id)->call('setSignalStatus', 'erledigt')
        ->assertSee('Marge zu knapp')->assertSee('Wieder öffnen');
    $lw->call('setSignalStatus', 'ignoriert')->assertSee('Keine Signale mit Status Ignoriert');
});

it('zeigt eine kritische Drift bei Typ-Filter nur einmal (Zuerst erledigen, nicht zusätzlich Entwicklung)', function () {
    $drift = $this->signals->erzeuge($this->rootTeam, SignalTyp::QualitaetDrift, SignalSeverity::Kritisch, 'Mengen-Lücke stark gestiegen', [
        'dedup_key' => 'drift:signals:rezept_mengen_luecke',
        'payload' => ['drift_metric' => 'rezept_mengen_luecke', 'drift_source' => 'signals', 'delta' => 9],
    ]);

    $lw = Livewire::test(ReviewQueue::class)->set('tab', 'signale')->call('setSignalTyp', SignalTyp::QualitaetDrift->value);
    $sektionen = $lw->viewData('signalSektionen');

    expect(array_column($sektionen, 'key'))->toBe(['kritisch'])
        ->and($sektionen[0]['items']->pluck('id')->all())->toBe([$drift->id]);
});
