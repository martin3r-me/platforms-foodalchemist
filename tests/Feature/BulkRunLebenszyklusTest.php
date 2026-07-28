<?php

use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Jobs\BulkEnrichGpJob;
use Platform\FoodAlchemist\Jobs\BulkEnrichJob;
use Platform\FoodAlchemist\Jobs\ImportArticlesJob;
use Platform\FoodAlchemist\Jobs\MaterializeIdeaJob;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Services\IngestStatusService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 22 · H3b — ein Lauf kann ab hier auch ohne Erfolg enden (V-054).
 *
 * Bis hier war die Statusmenge faktisch `running | done`, und der einzige Schreiber von
 * `done` war der Erfolgspfad: ein abgestürzter Lauf antwortete auf „ist der Quartals-Import
 * durch?" dauerhaft mit „läuft gerade" — schlechter als keine Antwort, weil es einen
 * Menschen genau in dem Fall vom Nachschauen abhält, in dem er nachschauen müsste.
 *
 * Vier Zusicherungen:
 *  1. **Ende ohne Erfolg** — `failed` + Grund im Kontext, und ein **beendeter** Lauf wird
 *     nicht rückdatiert (das Teilergebnis eines durchgelaufenen `handle()` ist echt).
 *  2. **Jeder lauf-führende Job hat einen Fehl-Pfad** — vorher hatte ihn genau einer von
 *     vier; ein Timeout ließ die Zeile hängen.
 *  3. **Verwaist ist ein Urteil beim Lesen, kein Schreibvorgang** — die Spalte bleibt
 *     `running` (kein Reaper ohne Beweis), der Leser erfährt es trotzdem.
 *  4. **MCP-Lockstep** — `ingest.STATUS` nennt `verwaist` und `fehler_grund`; ohne das
 *     bekäme ein LLM, das vor dem erneuten Auslösen den Status liest, eine Dauer-Sperre.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

function h3bLauf(int $teamId, BulkRunType $typ = BulkRunType::Ingest, array $context = []): FoodAlchemistBulkRun
{
    return FoodAlchemistBulkRun::starte($teamId, $typ, 10, $context);
}

it('beendet einen Lauf ohne Erfolg und legt den Grund neben den Gegenstand', function () {
    $run = h3bLauf($this->rootTeam->id, BulkRunType::Ingest, ['datei' => 'hanos_q3.csv']);

    expect(FoodAlchemistBulkRun::markiereGescheitert($run->id, new RuntimeException('Datei nicht lesbar')))->toBeTrue();

    $frisch = FoodAlchemistBulkRun::findOrFail($run->id);
    expect($frisch->status)->toBe(BulkRunStatus::Failed)
        ->and($frisch->status->istOffen())->toBeFalse()
        // Der Gegenstand aus H3a bleibt stehen — der Ausgang kommt daneben, nicht darüber.
        ->and($frisch->context['datei'])->toBe('hanos_q3.csv')
        ->and($frisch->context['fehler'])->toBe('Datei nicht lesbar')
        ->and($frisch->context['fehler_klasse'])->toBe(RuntimeException::class);
});

it('datiert einen beendeten Lauf nicht zurück', function () {
    // Läuft `handle()` durch und stirbt erst der Nachlauf, ist `done` die wahre Antwort.
    $run = h3bLauf($this->rootTeam->id);
    $run->update(['status' => BulkRunStatus::Done, 'done' => 10]);

    expect(FoodAlchemistBulkRun::markiereGescheitert($run->id, new RuntimeException('zu spät')))->toBeFalse()
        ->and(FoodAlchemistBulkRun::findOrFail($run->id)->status)->toBe(BulkRunStatus::Done);

    // Idempotent: `failed()` darf mehrfach kommen, der zweite Aufruf ändert nichts.
    $zweiter = h3bLauf($this->rootTeam->id);
    expect(FoodAlchemistBulkRun::markiereGescheitert($zweiter->id, 'erster'))->toBeTrue()
        ->and(FoodAlchemistBulkRun::markiereGescheitert($zweiter->id, 'zweiter'))->toBeFalse()
        ->and(FoodAlchemistBulkRun::findOrFail($zweiter->id)->context['fehler'])->toBe('erster');
});

it('gibt jedem lauf-führenden Job einen Fehl-Pfad', function () {
    // Vorher: nur ImportArticlesJob hatte einen. Ein Timeout des Bulk-Autopiloten
    // (3600 s) ließ seine Zeile für immer auf `running` stehen.
    $faelle = [
        fn (int $id) => (new ImportArticlesJob($id, $this->rootTeam->id, 1, '/tmp/x.csv'))->failed(new RuntimeException('Timeout Import')),
        fn (int $id) => (new BulkEnrichJob($id, $this->rootTeam->id, [1, 2], ['wording']))->failed(new RuntimeException('Timeout Enrich')),
        fn (int $id) => (new BulkEnrichGpJob($id, $this->rootTeam->id, [1, 2], ['naming']))->failed(new RuntimeException('Timeout GP')),
    ];

    foreach ($faelle as $ausloesen) {
        $run = h3bLauf($this->rootTeam->id);
        $ausloesen($run->id);
        $frisch = FoodAlchemistBulkRun::findOrFail($run->id);
        expect($frisch->status)->toBe(BulkRunStatus::Failed)
            ->and($frisch->context['fehler'])->toContain('Timeout');
    }
});

it('beendet den Lauf auch, wenn das Team unter dem Job weggefallen ist', function () {
    // Der einzige Fehl-Fall, den `handle()` selbst sieht — vorher ein stilles `return`
    // bei den beiden Enrich-Jobs.
    $run = h3bLauf($this->rootTeam->id, BulkRunType::Enrich);
    (new BulkEnrichJob($run->id, 999999, [1], ['wording']))->handle(app(\Platform\FoodAlchemist\Services\BulkEnrichService::class));

    $frisch = FoodAlchemistBulkRun::findOrFail($run->id);
    expect($frisch->status)->toBe(BulkRunStatus::Failed)
        ->and($frisch->context['fehler'])->toContain('999999');
});

it('erklärt einen Lauf ohne Rückmeldung für verwaist, ohne die Spalte anzufassen', function () {
    $frisch = h3bLauf($this->rootTeam->id);
    $alt = h3bLauf($this->rootTeam->id);
    $alt->forceFill(['updated_at' => now()->subHours(FoodAlchemistBulkRun::VERWAIST_NACH_STUNDEN + 1)])->saveQuietly();
    $fertig = h3bLauf($this->rootTeam->id);
    $fertig->forceFill(['status' => BulkRunStatus::Done->value, 'updated_at' => now()->subDays(30)])->saveQuietly();

    expect($frisch->istVerwaist())->toBeFalse()
        ->and($alt->fresh()->istVerwaist())->toBeTrue()
        // Ein abgeschlossener Lauf ist alt, nicht verwaist.
        ->and($fertig->fresh()->istVerwaist())->toBeFalse();

    // Das Urteil steht beim Leser, nicht in der Spalte: kein Reaper ohne Beweis.
    expect($alt->fresh()->status)->toBe(BulkRunStatus::Running)
        ->and($alt->fresh()->zustandLabel())->toBe('abgebrochen (keine Rückmeldung)')
        ->and($frisch->zustandLabel())->toBe(BulkRunStatus::Running->label());
});

it('meldet verwaist und Fehlergrund über ingest.STATUS mit', function () {
    // MCP-Lockstep: sonst liest ein LLM „läuft gerade" und wartet ewig bzw. löst doppelt aus.
    $tot = h3bLauf($this->rootTeam->id, BulkRunType::Ingest, ['datei' => 'toter_lauf.csv']);
    $tot->forceFill(['updated_at' => now()->subHours(FoodAlchemistBulkRun::VERWAIST_NACH_STUNDEN + 1)])->saveQuietly();
    $gescheitert = h3bLauf($this->rootTeam->id, BulkRunType::Ingest, ['datei' => 'kaputt.csv']);
    FoodAlchemistBulkRun::markiereGescheitert($gescheitert->id, new RuntimeException('Spalte fehlt'));

    $laeufe = collect(app(IngestStatusService::class)->status($this->rootTeam, null, 30, 0, 10)['laeufe'])
        ->keyBy('run_id');

    expect($laeufe[$tot->id]['status'])->toBe('running')
        ->and($laeufe[$tot->id]['verwaist'])->toBeTrue()
        ->and($laeufe[$tot->id]['status_label'])->toBe('abgebrochen (keine Rückmeldung)')
        ->and($laeufe[$gescheitert->id]['status'])->toBe('failed')
        ->and($laeufe[$gescheitert->id]['verwaist'])->toBeFalse()
        ->and($laeufe[$gescheitert->id]['fehler_grund'])->toBe('Spalte fehlt');
});

it('markiert eine queued Skizze als fehlgeschlagen, wenn ihr Job stirbt', function () {
    // Derselbe Fehler eine Ebene tiefer: der Job führt keine Lauf-Zeile, aber `queued`
    // heißt in der Oberfläche „wartet auf KI" — und niemand wartet mehr.
    $kapitel = $this->makeChapter($this->makeFoodbook($this->rootTeam, 'H3b'));
    $offen = $this->makeDishIdea($kapitel);
    $durch = $this->makeDishIdea($kapitel, ['generation_status' => 'erstellt']);

    (new MaterializeIdeaJob($this->rootTeam->id, $this->user->id, $offen->id))->failed(new RuntimeException('Timeout KI'));
    (new MaterializeIdeaJob($this->rootTeam->id, $this->user->id, $durch->id))->failed(new RuntimeException('Timeout KI'));

    expect(FoodAlchemistDishIdea::findOrFail($offen->id)->generation_status)->toBe('fehlgeschlagen')
        ->and(FoodAlchemistDishIdea::findOrFail($offen->id)->source_meta['generation_fehler'])->toContain('Job abgebrochen')
        // Was schon durch war, wird nicht nachträglich für gescheitert erklärt.
        ->and(FoodAlchemistDishIdea::findOrFail($durch->id)->generation_status)->toBe('erstellt');
});
