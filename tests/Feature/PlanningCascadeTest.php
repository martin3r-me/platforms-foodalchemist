<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
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

// ── P2: Freigabe / Verwerfen (Gate 2) ───────────────────────────────────────
// (Run+Step inline erzeugt — keine globale Helfer-Funktion in der Testdatei, Parallel-Worker-Regel.)

it('Freigabe (gericht): Rezept → approved, Step freigegeben, Run done', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Draft-Gericht', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);

    app(PlanningCascadeService::class)->gibStepFrei($this->rootTeam, (int) $step->id);

    expect($recipe->refresh()->status->value)->toBe('approved')     // RecipeStatus-Enum-Cast
        ->and($step->refresh()->status)->toBe('freigegeben')
        ->and($run->refresh()->status)->toBe('done');
});

it('Verwerfen (gericht): Draft-Rezept soft-deleted, Step verworfen, Run failed', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Weg damit', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);

    app(PlanningCascadeService::class)->verwirfStep($this->rootTeam, (int) $step->id);

    expect(FoodAlchemistRecipe::find($recipe->id))->toBeNull()                        // soft-deleted
        ->and(FoodAlchemistRecipe::withTrashed()->find($recipe->id))->not->toBeNull()
        ->and($step->refresh()->status)->toBe('verworfen')
        ->and($run->refresh()->status)->toBe('failed');
});

it('Freigabe (concept): Konzept → active', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Draft-Konzept', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'done', 'ref_type' => 'concept', 'ref_id' => $concept->id]);

    app(PlanningCascadeService::class)->gibStepFrei($this->rootTeam, (int) $step->id);

    expect($concept->refresh()->status)->toBe('active')
        ->and($step->refresh()->status)->toBe('freigegeben');
});

it('Bulk-Freigabe hebt alle offenen Entwürfe auf freigegeben, Run done', function () {
    $r1 = $this->makeRecipe($this->rootTeam, 'A', ['status' => 'draft']);
    $r2 = $this->makeRecipe($this->rootTeam, 'B', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    foreach ([$r1, $r2] as $r) {
        FoodAlchemistCascadeRunStep::create([
            'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
            'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $r->id,
        ]);
    }

    app(PlanningCascadeService::class)->gibRunFrei($this->rootTeam, (int) $run->id);

    expect($run->refresh()->status)->toBe('done')
        ->and($run->steps()->where('status', 'freigegeben')->count())->toBe(2)
        ->and($r1->refresh()->status->value)->toBe('approved');
});

it('Freigabe ist team-gescoped: geerbter Step wird abgewiesen (D1)', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Tenancy', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);

    // childA erbt den Root-Katalog (sichtbar), besitzt ihn aber nicht → Freigabe verboten.
    expect(fn () => app(PlanningCascadeService::class)->gibStepFrei($this->childA, (int) $step->id))
        ->toThrow(RuntimeException::class);
    expect($recipe->refresh()->status->value)->toBe('draft');   // nichts passiert
});

it('Cockpit: Editor rendert die Freigabe-UI und gibt einen Entwurf über die Komponente frei', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Freigabe-Test', 'brief' => 'x']);
    $recipe = $this->makeRecipe($this->rootTeam, 'Cockpit-Draft', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)         // ladeLetztenLauf → laufId=run (Freigabe-UI rendert)
        ->assertSee('warten auf Freigabe')     // Bulk-Kopf sichtbar (blade-Render des done-Pfads)
        ->call('gibFrei', $step->id)
        ->assertSet('meldung', 'Freigegeben.');

    expect($step->refresh()->status)->toBe('freigegeben')
        ->and($recipe->refresh()->status->value)->toBe('approved');
});

// ── P1b: Erfinden (Concept-Divergenz + Fan-out) ─────────────────────────────
// LLM-Aufrufe (Divergenz, Rezept-Gen) sind gemockt — Verdrahtung/Graceful lokal, Output demo-verifiziert.

it('Go (concept, voll_kreativ): reicht creative_mode an GenerateConceptJob durch', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer', 'brief' => 'Sommer-Buffet.']);

    app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'concept', $session, 'voll_kreativ');

    Queue::assertPushed(GenerateConceptJob::class, fn ($job) => $job->creativeMode === 'voll_kreativ');
});

it('Fan-out: je leerem Slot ein Kind-Step (kind=gericht) + MaterializeConceptIdeaJob (Divergenz gemockt)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Buffet', ['status' => 'draft']);
    $s1 = $this->makeConceptSlot($concept, ['position' => 1]);
    $s2 = $this->makeConceptSlot($concept, ['position' => 2]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $concept->id]);

    $mkIdee = fn (string $t) => FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $concept->id, 'title' => $t, 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'entwurf', 'position' => 1, 'created_via' => 'test']);
    $i1 = $mkIdee('Erfunden A');
    $i2 = $mkIdee('Erfunden B');
    $this->mock(IdeenService::class, fn ($m) => $m->shouldReceive('kiDivergenzConcept')->once()
        ->andReturn(['angelegt' => [$i1, $i2], 'roh' => 2, 'confidence' => 0.8, 'call_log_id' => null]));

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $concept->id, 'voll_kreativ');

    $kinder = $run->steps()->where('kind', 'gericht')->where('parent_step_id', $conceptStep->id)->get();
    expect($kinder)->toHaveCount(2)
        ->and((int) ($i1->refresh()->source_meta['target_concept_slot_id'] ?? 0))->toBe((int) $s1->id)
        ->and((int) ($i2->refresh()->source_meta['target_concept_slot_id'] ?? 0))->toBe((int) $s2->id);
    Queue::assertPushed(MaterializeConceptIdeaJob::class, 2);
});

it('Fan-out ist graceful: ohne LLM-Provider 0 erfundene Gerichte, kein Job (Konzept bleibt)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Buffet', ['status' => 'draft']);
    $this->makeConceptSlot($concept, ['position' => 1]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $concept->id]);

    // Kein Mock → echte Divergenz trifft im Test-Env keinen LLM-Provider → wirft → wird graceful gefangen.
    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $concept->id, 'voll_kreativ');

    expect($run->steps()->where('kind', 'gericht')->count())->toBe(0);
    Queue::assertNotPushed(MaterializeConceptIdeaJob::class);
});

it('materialisiereConceptGericht: erdet die Idee, verdrahtet ins Slot, Step done (Gen gemockt)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Buffet', ['status' => 'draft']);
    $slot = $this->makeConceptSlot($concept, ['position' => 1]);
    $recipe = $this->makeRecipe($this->rootTeam, 'Erdung', ['status' => 'draft', 'is_sales_recipe' => true]);
    $idee = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $concept->id, 'title' => 'Erfunden', 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'queued', 'position' => 1, 'created_via' => 'test', 'source_meta' => ['target_concept_slot_id' => (int) $slot->id]]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    $this->mock(RecipeGeneratorService::class, fn ($m) => $m->shouldReceive('generiere')->once()
        ->andReturn(['recipe' => $recipe, 'statistik' => [], 'offene' => []]));

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idee->id, (int) $step->id);

    expect((int) $slot->refresh()->sales_recipe_id)->toBe((int) $recipe->id)
        ->and($step->refresh()->status)->toBe('done')
        ->and((int) $step->refresh()->ref_id)->toBe((int) $recipe->id)
        ->and($idee->refresh()->generation_status)->toBe('erstellt');
});

it('materialisiereConceptGericht: Generierungs-Fehler → Step failed, Idee fehlgeschlagen', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Buffet', ['status' => 'draft']);
    $slot = $this->makeConceptSlot($concept, ['position' => 1]);
    $idee = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $concept->id, 'title' => 'Erfunden', 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'queued', 'position' => 1, 'created_via' => 'test', 'source_meta' => ['target_concept_slot_id' => (int) $slot->id]]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    $this->mock(RecipeGeneratorService::class, fn ($m) => $m->shouldReceive('generiere')->once()
        ->andThrow(new RuntimeException('KI kaputt')));

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idee->id, (int) $step->id);

    expect($step->refresh()->status)->toBe('failed')
        ->and($idee->refresh()->generation_status)->toBe('fehlgeschlagen')
        ->and($slot->refresh()->sales_recipe_id)->toBeNull();   // nichts verdrahtet
});

it('Wissen/Trend: erfundenes Rezept erbt die Trend-Herkunft der Planung (Lineage durchgereicht)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Trend-Plan', 'brief' => 'x', 'source_knowledge_document_id' => 4242]);
    $concept = $this->makeConcept($this->rootTeam, 'Buffet', ['status' => 'draft']);
    $slot = $this->makeConceptSlot($concept, ['position' => 1]);
    $recipe = $this->makeRecipe($this->rootTeam, 'Erfunden', ['status' => 'draft', 'is_sales_recipe' => true]);
    $idee = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $concept->id, 'title' => 'Erfunden', 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'queued', 'position' => 1, 'created_via' => 'test', 'source_meta' => ['target_concept_slot_id' => (int) $slot->id]]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    $this->mock(RecipeGeneratorService::class, fn ($m) => $m->shouldReceive('generiere')->once()
        ->andReturn(['recipe' => $recipe, 'statistik' => [], 'offene' => []]));

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idee->id, (int) $step->id, (int) $session->id);

    expect((int) $recipe->refresh()->source_knowledge_document_id)->toBe(4242)   // Trend-Herkunft geerbt
        ->and($recipe->refresh()->created_via)->toBe('plan_go');
});
