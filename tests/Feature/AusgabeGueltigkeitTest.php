<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 33 · P1 — Gültigkeitsfenster und „läuft am Stichtag".
 *
 * Das Fenster ist die **Bremse gegen ein Portfolio, das nur wächst**: Versenden setzt auf
 * `aktiv`, und nichts läuft von selbst ab. Ohne diese Regel stünden nach zwei Saisons fünf
 * „laufende" Karten je Standort und die Konfliktliste wäre wertlos.
 *
 * Die zweite Hälfte ist genauso wichtig: das Fenster ändert **nur die Anzeige**, nie die Daten.
 * Eine automatische Archivierung war ausdrücklich nicht bestellt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->karte = fn (array $attr = []) => FoodAlchemistSpeisekarte::create($attr + [
        'team_id' => $this->rootTeam->id, 'name' => 'Karte', 'status' => AusgabeStatus::Aktiv->value,
    ]);
});

it('läuft nur, wenn Status UND Fenster stimmen', function () {
    $offen = ($this->karte)(['gueltig_von' => null, 'gueltig_bis' => null]);
    $laufend = ($this->karte)(['gueltig_von' => '2026-07-01', 'gueltig_bis' => '2026-09-30']);
    $geplant = ($this->karte)(['gueltig_von' => '2026-12-01']);
    $abgelaufen = ($this->karte)(['gueltig_bis' => '2026-06-30']);

    $stichtag = '2026-08-15';

    expect($offen->laeuftAm($stichtag))->toBeTrue()          // unbefristet
        ->and($laufend->laeuftAm($stichtag))->toBeTrue()
        ->and($geplant->laeuftAm($stichtag))->toBeFalse()
        ->and($abgelaufen->laeuftAm($stichtag))->toBeFalse();
});

it('schließt die Fenstergrenzen ein', function () {
    $k = ($this->karte)(['gueltig_von' => '2026-08-01', 'gueltig_bis' => '2026-08-31']);

    expect($k->laeuftAm('2026-08-01'))->toBeTrue()
        ->and($k->laeuftAm('2026-08-31'))->toBeTrue()
        ->and($k->laeuftAm('2026-07-31'))->toBeFalse()
        ->and($k->laeuftAm('2026-09-01'))->toBeFalse();
});

it('nennt den Grund, warum etwas nicht läuft', function () {
    $stichtag = '2026-08-15';

    expect(($this->karte)(['gueltig_bis' => '2026-06-30'])->laufZustand($stichtag))->toBe('abgelaufen')
        ->and(($this->karte)(['gueltig_von' => '2026-12-01'])->laufZustand($stichtag))->toBe('geplant')
        ->and(($this->karte)(['status' => 'inaktiv'])->laufZustand($stichtag))->toBe('inaktiv')
        ->and(($this->karte)(['status' => 'entwurf'])->laufZustand($stichtag))->toBe('entwurf')
        ->and(($this->karte)([])->laufZustand($stichtag))->toBe('laeuft');

    expect(($this->karte)(['gueltig_bis' => '2026-06-30'])->laufGrund($stichtag))
        ->toContain('noch nicht archiviert');
    expect(($this->karte)([])->laufGrund($stichtag))->toBeNull();
});

it('lässt den Status unangetastet, wenn das Fenster abläuft', function () {
    // Das ist der Kern des Entscheids „keine Automatik": das Fenster bremst die ANZEIGE,
    // die Daten bleiben, bis ein Mensch archiviert.
    $k = ($this->karte)(['gueltig_bis' => '2026-06-30']);

    expect($k->laeuftAm('2026-08-15'))->toBeFalse()
        ->and($k->laufZustand('2026-08-15'))->toBe('abgelaufen');

    $k->refresh();
    expect($k->statusWert())->toBe(AusgabeStatus::Aktiv)
        ->and(DB::table('foodalchemist_menu_cards')->where('id', $k->id)->value('status'))->toBe('aktiv');
});

it('gibt dem Foodbook ein Fenster — vorher gab es nur das Jahr', function () {
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Sommer', 'status' => 'aktiv']);
    app(FoodbookService::class)->update($this->rootTeam, (int) $fb->id, [
        'gueltig_von' => '2026-06-01', 'gueltig_bis' => '2026-08-31',
    ]);

    $fb->refresh();
    expect($fb->laeuftAm('2026-07-15'))->toBeTrue()
        ->and($fb->laeuftAm('2026-10-01'))->toBeFalse();
});

it('nimmt ein leeres Datumsfeld als unbefristet an', function () {
    // Das Formular liefert '' für ein nicht gesetztes Datum — in einer DATE-Spalte lehnt MySQL
    // das im Strict Mode ab. Der Service macht daraus NULL, auch für MCP-Aufrufe.
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Offen', 'status' => 'aktiv']);
    app(FoodbookService::class)->update($this->rootTeam, (int) $fb->id, [
        'gueltig_von' => '', 'gueltig_bis' => '',
    ]);

    $fb->refresh();
    expect($fb->gueltigVon())->toBeNull()
        ->and($fb->gueltigBis())->toBeNull()
        ->and($fb->laeuftAm('2030-01-01'))->toBeTrue();
});

// ── Speiseplan: Fenster aus den Einträgen ────────────────────────────────────

it('leitet das Speiseplan-Fenster aus den Einträgen ab, statt es zu speichern', function () {
    // Zwei Wahrheiten (gepflegtes Fenster vs. tatsächliche Belegung) würden auseinanderlaufen,
    // sobald jemand einen Eintrag verschiebt. Deshalb hat der Plan keine eigenen Spalten.
    $plan = FoodAlchemistSpeiseplan::create([
        'team_id' => $this->rootTeam->id, 'name' => 'KW 30-31', 'status' => AusgabeStatus::Aktiv->value,
    ]);
    foreach (['2026-07-20', '2026-07-24', '2026-07-31'] as $tag) {
        DB::table('foodalchemist_menu_plan_entries')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $this->rootTeam->id, 'menu_plan_id' => $plan->id,
            'entry_date' => $tag, 'week' => 1, 'weekday' => 1, 'meal' => 'mittag', 'position' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect($plan->gueltigVon()?->toDateString())->toBe('2026-07-20')
        ->and($plan->gueltigBis()?->toDateString())->toBe('2026-07-31')
        ->and($plan->laeuftAm('2026-07-25'))->toBeTrue()
        ->and($plan->laeuftAm('2026-08-05'))->toBeFalse();
});

it('nutzt eager geladene Aggregate statt je Plan nachzuschlagen', function () {
    foreach (range(1, 3) as $i) {
        $plan = FoodAlchemistSpeiseplan::create([
            'team_id' => $this->rootTeam->id, 'name' => 'Plan ' . $i, 'status' => AusgabeStatus::Aktiv->value,
        ]);
        DB::table('foodalchemist_menu_plan_entries')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $this->rootTeam->id, 'menu_plan_id' => $plan->id,
            'entry_date' => '2026-07-0' . $i, 'week' => 1, 'weekday' => 1, 'meal' => 'mittag', 'position' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $plaene = FoodAlchemistSpeiseplan::query()
        ->withMin('entries', 'entry_date')->withMax('entries', 'entry_date')->get();

    // Ohne Eager-Loading kostet jedes gueltigVon()/gueltigBis() eine eigene Abfrage — in einer
    // Portfolio-Liste wäre das der klassische N+1. Hier darf KEINE weitere Query fallen.
    DB::enableQueryLog();
    foreach ($plaene as $p) {
        $p->gueltigVon();
        $p->gueltigBis();
    }
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($plaene->first()->gueltigVon()?->toDateString())->toBe('2026-07-01');
});

it('hat ohne Einträge kein Fenster und läuft damit unbefristet', function () {
    $plan = FoodAlchemistSpeiseplan::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Leer', 'status' => AusgabeStatus::Aktiv->value,
    ]);

    expect($plan->gueltigVon())->toBeNull()
        ->and($plan->laeuftAm('2030-01-01'))->toBeTrue();
});
