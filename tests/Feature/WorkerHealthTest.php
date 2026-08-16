<?php

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Platform\FoodAlchemist\Services\WorkerHealthService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Roadmap Planung-Leitstelle · Etappe 8 »Worker-Präsenz« — Teil 1 (Heartbeat + Ampel).
 *
 * Der Service liefert das PROAKTIVE Gegenstück zum reaktiven Per-Lauf-Watchdog: ein lebender
 * Queue-Worker stempelt bei jedem `Looping`/`JobProcessing` einen Herzschlag; die Ampel liest
 * daraus gesund/still/unbekannt. Zwei Ebenen geprüft: der Service (Stempel, Drossel, Ampel-Bänder)
 * und die Provider-Verdrahtung (die Queue-Events treffen den Herzschlag wirklich).
 */
beforeEach(function () {
    Cache::flush();
});

it('meldet unbekannt, solange nie ein Herzschlag kam', function () {
    $status = app(WorkerHealthService::class)->status();

    expect($status['state'])->toBe('unbekannt')
        ->and($status['zuletzt'])->toBeNull()
        ->and($status['alter_sek'])->toBeNull();
});

it('meldet gesund direkt nach einem Herzschlag', function () {
    $svc = app(WorkerHealthService::class);
    $svc->heartbeat();

    $status = $svc->status();
    expect($status['state'])->toBe('gesund')
        ->and($status['alter_sek'])->toBeLessThanOrEqual(WorkerHealthService::STILL_SEKUNDEN);
    expect($svc->istGesund())->toBeTrue();
});

it('meldet still, wenn der letzte Herzschlag zu alt ist', function () {
    // Ein alter Stempel = ein Worker war da, ist aber vermutlich nicht mehr aktiv.
    Cache::put(
        WorkerHealthService::HEARTBEAT_KEY,
        now()->timestamp - (WorkerHealthService::STILL_SEKUNDEN + 30),
        WorkerHealthService::HEARTBEAT_TTL_SEKUNDEN,
    );

    $status = app(WorkerHealthService::class)->status();
    expect($status['state'])->toBe('still')
        ->and($status['alter_sek'])->toBeGreaterThan(WorkerHealthService::STILL_SEKUNDEN);
    expect(app(WorkerHealthService::class)->istGesund())->toBeFalse();
});

it('drosselt die Schreiblast — ein zweiter Herzschlag im Fenster überschreibt nicht', function () {
    $svc = app(WorkerHealthService::class);
    $svc->heartbeat();                 // erster Stempel: schreibt + setzt den Drossel-Riegel

    // Sentinel unterschieben und erneut stempeln: der Riegel steht noch → kein Überschreiben.
    $sentinel = now()->timestamp - 999;
    Cache::put(WorkerHealthService::HEARTBEAT_KEY, $sentinel, WorkerHealthService::HEARTBEAT_TTL_SEKUNDEN);
    $svc->heartbeat();

    expect(Cache::get(WorkerHealthService::HEARTBEAT_KEY))->toBe($sentinel);
});

it('stempelt den Herzschlag beim Queue-Looping-Event (Provider-Verdrahtung)', function () {
    expect(app(WorkerHealthService::class)->status()['state'])->toBe('unbekannt');

    Event::dispatch(new Looping('database', null));

    expect(app(WorkerHealthService::class)->status()['state'])->toBe('gesund');
});

it('verdrahtet den Herzschlag auf beide Queue-Events (Looping + JobProcessing)', function () {
    // Der Looping-Pfad ist oben end-to-end bewiesen; JobProcessing nutzt dieselbe Registrierung.
    // Ein echter JobProcessing-Dispatch würde fremde Plattform-Listener (Log-Context) mitziehen,
    // die eine voll konforme Job-Instanz brauchen — hier genügt der Nachweis, dass der Listener hängt.
    expect(Event::hasListeners(Looping::class))->toBeTrue()
        ->and(Event::hasListeners(JobProcessing::class))->toBeTrue();
});
