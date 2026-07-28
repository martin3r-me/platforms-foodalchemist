<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Jobs\QualityRunJob;
use Platform\FoodAlchemist\Jobs\RecipeFindingsRunJob;
use Platform\FoodAlchemist\Livewire\ReviewQueue;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Services\QualityRunService;
use Platform\FoodAlchemist\Services\RecipeFindingsBatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Die zwei Qualitäts-Läufe als auslösbare Fläche (2026-07-28).
 *
 * Anlass war ein doppelter, monatelang unbemerkter Ausfall auf demo:
 *
 *  1. Der „Prüfen"-Knopf lag seit 2026-06-17 im `<x-slot:end>` des Core-`x-ui-page-actionbar`
 *     und **rendert dort nicht** — ein Feature-Eingang, den niemand anklicken konnte.
 *  2. Der Detektor war in **keinem** Scheduler registriert (der Command verwies auf den
 *     Host-Console-Kernel, wo es nie geschah) und lief synchron im Livewire-Request.
 *
 * Folge: 20+ Signal-Typen und die komplette Zeitreihe existierten im Code, aber nie in den
 * Daten. Diese Tests nageln genau die drei Eigenschaften fest, deren Fehlen das verursacht
 * hat: der Lauf ist **asynchron**, er hat eine **Quittung**, und er ist **eingeplant**.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->runs = app(QualityRunService::class);
});

it('reiht den Ampel-Lauf als Job ein statt ihn im Request zu fahren', function () {
    Queue::fake();

    $res = $this->runs->starteAmpelLauf($this->rootTeam);

    Queue::assertPushed(QualityRunJob::class, fn ($job) => $job->runId === $res['run_id']
        && $job->teamId === (int) $this->rootTeam->id);

    expect($res['bereits_laufend'])->toBeFalse()
        ->and($res['run_id'])->toBeGreaterThan(0);
});

it('gibt die Quittung VOR dem Worker heraus — der Lauf ist sofort nachlesbar', function () {
    Queue::fake();

    $res = $this->runs->starteAmpelLauf($this->rootTeam);
    $run = FoodAlchemistBulkRun::find($res['run_id']);

    // Ohne diesen Datensatz wäre „ich habe geklickt und nichts passierte" nicht von
    // „der Job wurde nie eingereiht" zu unterscheiden — genau die Lage auf demo.
    expect($run)->not->toBeNull()
        ->and($run->type)->toBe(BulkRunType::Detektor)
        ->and($run->status)->toBe(BulkRunStatus::Running)
        ->and((int) $run->team_id)->toBe((int) $this->rootTeam->id);
});

it('startet keinen zweiten Ampel-Lauf, solange einer läuft (sonst zwei Snapshots pro Reihe)', function () {
    Queue::fake();

    $erst = $this->runs->starteAmpelLauf($this->rootTeam);
    $zweit = $this->runs->starteAmpelLauf($this->rootTeam);

    expect($zweit['bereits_laufend'])->toBeTrue()
        ->and($zweit['run_id'])->toBe($erst['run_id']);

    // Genau EIN Job — der Drift-Vergleich (E3) hielte sonst einen Punkt gegen sich selbst.
    Queue::assertPushed(QualityRunJob::class, 1);
});

it('sperrt den Befunde-Lauf NICHT — die Bremse ist das Limit, nicht die Einreihung', function () {
    Queue::fake();

    $this->runs->starteBefundeLauf($this->rootTeam, 5);
    $this->runs->starteBefundeLauf($this->rootTeam, 5);

    // Zwei Läufe sind hier gewollt: die Fälligkeits-Auswahl arbeitet den Bestand ab.
    Queue::assertPushed(RecipeFindingsRunJob::class, 2);
});

it('deckelt das Befunde-Limit hart — ein manipulierter Aufruf löst keine Volllast aus', function () {
    Queue::fake();

    $res = $this->runs->starteBefundeLauf($this->rootTeam, 99999);

    expect($res['limit'])->toBe(RecipeFindingsBatchService::MAX_LIMIT);

    Queue::assertPushed(RecipeFindingsRunJob::class,
        fn ($job) => $job->limit === RecipeFindingsBatchService::MAX_LIMIT);
});

it('der Ampel-Lauf gilt nicht als KI-Lauf — er kostet kein Provider-Geld', function () {
    // Die Kosten-Trennlinie war als Negativ-Liste (`!== Ingest`) geschrieben: jeder neue
    // Lauf-Typ wurde dadurch stillschweigend zum Kostenträger. Ein täglich eingeplanter,
    // gratis laufender Job hätte die Auswertung dauerhaft verfälscht — ohne einen Fehler.
    expect(BulkRunType::Detektor->istKiLauf())->toBeFalse()
        ->and(BulkRunType::Review->istKiLauf())->toBeTrue()
        ->and(BulkRunType::Ingest->istKiLauf())->toBeFalse();
});

it('jeder Lauf-Typ hat ein Label — kein Typ fällt aus der match-Erschöpfung', function () {
    foreach (BulkRunType::cases() as $typ) {
        expect($typ->label())->not->toBe('');
        expect($typ->istKiLauf())->toBeBool();
    }
});

it('der Detektor ist täglich eingeplant — nicht mehr Sache eines fremden Console-Kernels', function () {
    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

    $treffer = collect($schedule->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'foodalchemist:signale-detektor'));

    expect($treffer)->not->toBeEmpty();
    expect($treffer->first()->expression)->toBe('20 3 * * *');
});

it('der Copilot-Batch ist bewusst NICHT eingeplant — kein nächtliches Provider-Geld', function () {
    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

    $treffer = collect($schedule->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'foodalchemist:recipe-findings'));

    expect($treffer)->toBeEmpty();
});

it('der Cockpit-Knopf reiht ein und meldet die run_id zurück', function () {
    Queue::fake();

    $this->actingAs($this->makeUser($this->rootTeam, 'Lauf User'));

    \Livewire\Livewire::test(ReviewQueue::class)
        ->call('detektorLaufen')
        ->assertSee('Messung gestartet', false);

    Queue::assertPushed(QualityRunJob::class, 1);
});
