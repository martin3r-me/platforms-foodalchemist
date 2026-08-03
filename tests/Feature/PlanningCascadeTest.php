<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Planungs-Kaskade P0 — der geteilte Motor an den beiden Blatt-Stufen (rezept|gericht).
 *
 * Beweisziele:
 *  1. „Go" erzeugt einen Run + genau EINEN Step und dispatcht den bestehenden GenerateRecipeJob
 *     mit dem korrekten vkModus UND dem Rückkanal (cascade_step_id) + Lineage (planning_session_id).
 *  2. Der Rückkanal (markStepDone/markStepFailed) leitet den Run-Status korrekt ab (review|failed).
 *  3. Der Job-Hook meldet nur bei gesetztem cascade_step_id zurück (Bestandspfade unberührt).
 *  4. Das Cockpit startet den Lauf in-place (kein Redirect) und pollt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
});

it('Go (gericht): legt Run+Step an und dispatcht den Job als VK mit Rückkanal + Lineage', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Geschmortes Rind', 'brief' => 'Schmorgericht mit Wurzelgemüse.']);

    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'gericht', $session, 'voll_kreativ');

    expect($run->scope)->toBe('gericht')
        ->and($run->status)->toBe('running')
        ->and((int) $run->planning_session_id)->toBe((int) $session->id);

    $step = $run->steps()->first();
    expect($step)->not->toBeNull()
        ->and($step->kind)->toBe('gericht')
        ->and($step->status)->toBe('running')
        ->and($step->generator_run_id)->not->toBeNull();

    Queue::assertPushed(GenerateRecipeJob::class, function ($job) use ($step, $session) {
        return $job->vkModus === true
            && (int) ($job->parameter['cascade_step_id'] ?? 0) === (int) $step->id
            && (int) ($job->parameter['planning_session_id'] ?? 0) === (int) $session->id;
    });
});

it('Go (rezept): dispatcht als Basisrezept (vkModus=false), Step-kind=rezept', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Geschmortes Rind', 'brief' => 'Schmorgericht mit Wurzelgemuese.']);

    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'rezept', $session, 'datenbank');

    expect($run->steps()->first()->kind)->toBe('rezept');
    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->vkModus === false);
});

it('Go (concept): legt Concept-Step an und dispatcht GenerateConceptJob mit Rückkanal + Lineage', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer-Buffet', 'brief' => 'Leichtes Sommer-Buffet, mediterran.']);

    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'concept', $session, 'voll_kreativ');

    expect($run->scope)->toBe('concept')->and($run->status)->toBe('running');
    $step = $run->steps()->first();
    expect($step->kind)->toBe('concept')->and($step->status)->toBe('running')->and($step->generator_run_id)->not->toBeNull();

    Queue::assertPushed(GenerateConceptJob::class, function ($job) use ($step, $session) {
        return (int) $job->cascadeStepId === (int) $step->id
            && (int) $job->planningSessionId === (int) $session->id;
    });
});

it('Concept-Job-Hook: failed() meldet an den Step zurück → Run failed', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer-Buffet', 'brief' => 'Leichtes Sommer-Buffet.']);
    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'concept', $session, 'voll_kreativ');
    $step = $run->steps()->first();

    $job = new GenerateConceptJob('run-c', $this->rootTeam->id, 1, 'brief', null, null, (int) $step->id);
    $job->failed(new RuntimeException('boom'));

    expect($step->refresh()->status)->toBe('failed')->and($run->refresh()->status)->toBe('failed');
});

it('vollkaskade ist noch nicht orchestriert und wirft ehrlich (P3)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Geschmortes Rind', 'brief' => 'Schmorgericht mit Wurzelgemuese.']);

    expect(fn () => app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'vollkaskade', $session, 'voll_kreativ'))
        ->toThrow(RuntimeException::class);
    Queue::assertNothingPushed();
});

it('markStepDone: hält das Artefakt fest und hebt den Run auf review', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Geschmortes Rind', 'brief' => 'Schmorgericht mit Wurzelgemuese.']);
    $svc = app(PlanningCascadeService::class);
    $run = $svc->starteKaskade($this->rootTeam, 'gericht', $session, 'voll_kreativ');
    $step = $run->steps()->first();

    $svc->markStepDone((int) $step->id, 'recipe', 4242);

    $step->refresh();
    $run->refresh();
    expect($step->status)->toBe('done')
        ->and($step->ref_type)->toBe('recipe')
        ->and((int) $step->ref_id)->toBe(4242)
        ->and($run->status)->toBe('review');
});

it('markStepFailed: einziger Step scheitert → Run failed', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Geschmortes Rind', 'brief' => 'Schmorgericht mit Wurzelgemuese.']);
    $svc = app(PlanningCascadeService::class);
    $run = $svc->starteKaskade($this->rootTeam, 'gericht', $session, 'voll_kreativ');
    $step = $run->steps()->first();

    $svc->markStepFailed((int) $step->id, 'Provider nicht erreichbar');

    expect($step->refresh()->status)->toBe('failed')
        ->and($run->refresh()->status)->toBe('failed');
});

it('Job-Hook: failed() meldet an den Step zurück, wenn cascade_step_id gesetzt ist', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Geschmortes Rind', 'brief' => 'Schmorgericht mit Wurzelgemuese.']);
    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'gericht', $session, 'voll_kreativ');
    $step = $run->steps()->first();

    $job = new GenerateRecipeJob('run-x', $this->rootTeam->id, 1, 'brief', ['cascade_step_id' => (int) $step->id], true, false);
    $job->failed(new RuntimeException('boom'));

    expect($step->refresh()->status)->toBe('failed')
        ->and($run->refresh()->status)->toBe('failed');
});

it('Job-Hook: ohne cascade_step_id bleibt ein fremder Step unberührt (Bestandspfad)', function () {
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => 999, 'kind' => 'gericht', 'status' => 'queued',
    ]);

    $job = new GenerateRecipeJob('run-y', $this->rootTeam->id, 1, 'brief', [], true, false);
    $job->failed(new RuntimeException('boom'));

    expect($step->refresh()->status)->toBe('queued');
});

it('Cockpit: goKaskade startet den Lauf in-place (kein Redirect) und pollt', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Geschmortes Rind', 'brief' => 'Schmorgericht mit Wurzelgemuese.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('goKaskade', 'gericht')
        ->assertSet('laeuft', true)
        ->assertNotSet('laufId', null)
        ->assertNoRedirect();

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->vkModus === true);
    expect(FoodAlchemistCascadeRun::where('planning_session_id', $session->id)->count())->toBe(1);
});
