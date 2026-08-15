<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Jobs\FanoutConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
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

it('teilt identische fehlende Basisrezepte im Lauf und bindet das Ergebnis an alle Eltern', function () {
    $run = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'vollkaskade', 'status' => 'running',
    ]);
    $parents = collect([1, 2])->map(function ($sort) use ($run) {
        return FoodAlchemistCascadeRunStep::create([
            'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
            'kind' => 'gericht', 'status' => 'running', 'sort' => $sort,
        ]);
    });
    $recipes = $parents->map(fn ($step, $i) => $this->makeRecipe($this->rootTeam, 'Gericht ' . $i));
    $ingredients = $recipes->map(fn ($recipe) => $this->makeIngredient($recipe, 'Geflügelfond'));
    $workflow = app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class);

    foreach ($parents as $i => $step) {
        $workflow->afterGenerated($this->rootTeam, $step->id, auth()->id(), $recipes[$i], [[
            'index' => 0, 'text' => 'Geflügelfond', 'primaer' => 'basisrezept_anlegen',
        ]], ['auto_dependencies' => true, '_voll_anreichern' => true]);
    }

    $children = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('depth', 1)->get();
    expect($children)->toHaveCount(1)
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency::count())->toBe(2);
    Queue::assertPushed(GenerateRecipeJob::class, 1);
    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->vkModus === false
        && $job->vollAnreichern === true
        && ! array_key_exists('_voll_anreichern', $job->parameter));

    $fond = $this->makeRecipe($this->rootTeam, 'Geflügelfond');
    $workflow->afterGenerated($this->rootTeam, $children->first()->id, auth()->id(), $fond, [], []);
    expect($ingredients[0]->refresh()->referenced_recipe_id)->toBe($fond->id)
        ->and($ingredients[1]->refresh()->referenced_recipe_id)->toBe($fond->id);
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
        ->assertSee('Ganze Stufe freigeben')   // Stufen-Freigabe sichtbar (blade-Render des done-Pfads, Stufe „prüfen")
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

// ── Leitplanken-Trennung (Roadmap Et.2a): Rezept- vs. Menü-Leitplanken ───────
// Der Concept-Fan-out erbt die Session-Leitplanken an die Gericht-Generierung. Die REZEPT-Achsen
// (Niveau/Convenience/…) müssen ankommen, die MENÜ-Achsen (menue_*) steuern nur die Zusammenstellung
// und dürfen NICHT in den Einzel-Gericht-Prompt durchsickern. `materialisiereConceptGericht` läuft über
// denselben Erben (sessionGenerationParams) wie die Speiseplan-Zelle — ein Pfad beweist die Trennung.
// (Setup inline gehalten: die Test-Helfer/$rootTeam sind protected — nur in der an die TestCase
// gebundenen it()-Closure erreichbar, nicht in einer externen Funktion.)

it('Fan-out-Vererbung: REZEPT-Leitplanken (Niveau/Convenience) erreichen die Gericht-Generierung', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Leitplanken', 'brief' => 'x']);
    app(PlanningSessionService::class)->setGenerationParams($this->rootTeam, (int) $session->id, ['level' => 'gehoben', 'convenience' => 'niedrig', 'menue_gaenge' => 3]);
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['status' => 'draft']);
    $slot = $this->makeConceptSlot($concept, ['position' => 1]);
    $recipe = $this->makeRecipe($this->rootTeam, 'Erfunden', ['status' => 'draft', 'is_sales_recipe' => true]);
    $idee = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $concept->id, 'title' => 'Erfunden', 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'queued', 'position' => 1, 'created_via' => 'test', 'source_meta' => ['target_concept_slot_id' => (int) $slot->id]]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    $captured = [];
    $this->mock(RecipeGeneratorService::class, function ($m) use (&$captured, $recipe) {
        $m->shouldReceive('generiere')->once()->andReturnUsing(function ($team, $brief, $params) use (&$captured, $recipe) {
            $captured = $params;

            return ['recipe' => $recipe, 'statistik' => [], 'offene' => []];
        });
    });

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idee->id, (int) $step->id, (int) $session->id);

    expect($captured['level'] ?? null)->toBe('gehoben')
        ->and($captured['convenience'] ?? null)->toBe('niedrig');
});

it('Fan-out-Vererbung: MENÜ-Leitplanken (menue_*) sickern NICHT in den Einzel-Gericht-Prompt', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Leitplanken', 'brief' => 'x']);
    app(PlanningSessionService::class)->setGenerationParams($this->rootTeam, (int) $session->id, [
        'level' => 'gehoben', 'menue_gaenge' => 4, 'menue_preis_ziel_pp' => 45.0, 'menue_quote_vegan_pct' => 30, 'menue_balance' => 'fokussiert',
    ]);
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['status' => 'draft']);
    $slot = $this->makeConceptSlot($concept, ['position' => 1]);
    $recipe = $this->makeRecipe($this->rootTeam, 'Erfunden', ['status' => 'draft', 'is_sales_recipe' => true]);
    $idee = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $concept->id, 'title' => 'Erfunden', 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'queued', 'position' => 1, 'created_via' => 'test', 'source_meta' => ['target_concept_slot_id' => (int) $slot->id]]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    $captured = [];
    $this->mock(RecipeGeneratorService::class, function ($m) use (&$captured, $recipe) {
        $m->shouldReceive('generiere')->once()->andReturnUsing(function ($team, $brief, $params) use (&$captured, $recipe) {
            $captured = $params;

            return ['recipe' => $recipe, 'statistik' => [], 'offene' => []];
        });
    });

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idee->id, (int) $step->id, (int) $session->id);

    // Kein einziger menue_*-Key darf im Rezept-Param-Bündel landen …
    $menueKeys = array_values(array_filter(array_keys($captured), fn ($k) => str_starts_with((string) $k, 'menue_')));
    expect($menueKeys)->toBe([])
        // … die Rezept-Leitplanke bleibt aber erhalten (die Trennung kappt nicht pauschal alles).
        ->and($captured['level'] ?? null)->toBe('gehoben');
});

// ── P3: Voll-Kaskade aus dem Foodbook-Frame ─────────────────────────────────

it('vollkaskade (foodbook): 1 Concept-Step je Frame-Slot + GenerateConceptJob mit Attach ans Kapitel', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Sommer-Foodbook', ['status' => 'draft']);
    $frameSvc = app(PlanningFrameService::class);
    $frame = $frameSvc->frameFor($this->rootTeam, 'foodbook', (int) $fb->id);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeisen', 'slot_type' => 'kapitel', 'target_count' => 3]);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'kapitel', 'target_count' => 2]);

    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'vollkaskade', null, 'voll_kreativ', ['owner_type' => 'foodbook', 'owner_id' => (int) $fb->id]);

    expect($run->scope)->toBe('vollkaskade')
        ->and($run->source_owner_type)->toBe('foodbook')
        ->and((int) $run->source_owner_id)->toBe((int) $fb->id)
        ->and($run->steps()->where('kind', 'concept')->count())->toBe(2);

    Queue::assertPushed(GenerateConceptJob::class, 2);
    Queue::assertPushed(GenerateConceptJob::class, fn ($job) => $job->attachOwnerType === 'foodbook' && (int) $job->attachContainerId > 0 && $job->creativeMode === 'voll_kreativ');
});

it('vollkaskade ohne Frame/Slots wirft ehrlich', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Leer', ['status' => 'draft']);

    expect(fn () => app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'vollkaskade', null, 'voll_kreativ', ['owner_type' => 'foodbook', 'owner_id' => (int) $fb->id]))
        ->toThrow(RuntimeException::class);
    Queue::assertNotPushed(GenerateConceptJob::class);
});

it('vollkaskade mit falschem Owner (nicht foodbook) wirft ehrlich', function () {
    expect(fn () => app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'vollkaskade', null, 'voll_kreativ', ['owner_type' => 'speisekarte', 'owner_id' => 1]))
        ->toThrow(RuntimeException::class);
    Queue::assertNothingPushed();
});

it('Foodbook-Leitstelle: Voll-Kaskade-Go startet die Kaskade + leitet in den Planung-Editor', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Sommer-Foodbook', ['status' => 'draft']);
    $frameSvc = app(PlanningFrameService::class);
    $frame = $frameSvc->frameFor($this->rootTeam, 'foodbook', (int) $fb->id);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeisen', 'slot_type' => 'kapitel', 'target_count' => 2]);
    Queue::fake();

    Livewire::test(\Platform\FoodAlchemist\Livewire\Foodbooks\Index::class)
        ->call('waehle', $fb->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect();   // → Planung-Editor (Review-Session)

    Queue::assertPushed(GenerateConceptJob::class);
    expect(FoodAlchemistCascadeRun::where('source_owner_type', 'foodbook')->where('source_owner_id', $fb->id)->count())->toBe(1);
});

// ── P4: Voll-Kaskade aus der Speisekarte ────────────────────────────────────

it('vollkaskade (speisekarte): Concept-Step je Frame-Slot + GenerateConceptJob mit Rubrik-Attach', function () {
    $karte = app(\Platform\FoodAlchemist\Services\SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Sommerkarte']);
    $frameSvc = app(PlanningFrameService::class);
    $frame = $frameSvc->frameFor($this->rootTeam, 'speisekarte', (int) $karte->id);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeisen', 'slot_type' => 'station', 'target_count' => 2]);

    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'vollkaskade', null, 'voll_kreativ', ['owner_type' => 'speisekarte', 'owner_id' => (int) $karte->id]);

    expect($run->source_owner_type)->toBe('speisekarte')
        ->and($run->steps()->where('kind', 'concept')->count())->toBe(1);
    Queue::assertPushed(GenerateConceptJob::class, fn ($job) => $job->attachOwnerType === 'speisekarte' && (int) $job->attachContainerId > 0);
    // rubrikFuerSlot hat die Rubrik idempotent angelegt (Slot-Label → Rubrik-Titel)
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karte->id)->where('title', 'Vorspeisen')->count())->toBe(1);
});

it('CoverageService kennt die Speisekarte (istSpeisekarte, kein Fehl-Read als Concept)', function () {
    $karte = app(\Platform\FoodAlchemist\Services\SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Karte']);
    $frameSvc = app(PlanningFrameService::class);
    $frame = $frameSvc->frameFor($this->rootTeam, 'speisekarte', (int) $karte->id);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'station', 'target_count' => 3]);

    $cov = app(\Platform\FoodAlchemist\Services\CoverageService::class)->coverage($this->rootTeam, 'speisekarte', (int) $karte->id);

    expect($cov['hat_geruest'])->toBeTrue()
        ->and($cov)->toHaveKey('befunde');
});

it('Speisekarte-Leitstelle: Voll-Kaskade-Go leitet Rahmen aus Rubriken ab + startet + redirected', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SpeisekarteService::class);
    $karte = $svc->create($this->rootTeam, ['name' => 'Sommerkarte']);
    $svc->addRubrik($this->rootTeam, (int) $karte->id, ['title' => 'Vorspeisen']);
    $svc->addRubrik($this->rootTeam, (int) $karte->id, ['title' => 'Hauptgang']);
    Queue::fake();

    Livewire::test(\Platform\FoodAlchemist\Livewire\Speisekarte\Index::class)
        ->call('waehle', $karte->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect();   // → Planung-Editor

    expect(FoodAlchemistCascadeRun::where('source_owner_type', 'speisekarte')->where('source_owner_id', $karte->id)->count())->toBe(1);
    Queue::assertPushed(GenerateConceptJob::class, 2);   // 2 Rubriken → 2 Slots → 2 Konzepte
});

// ── P5: Speiseplan-Voll-Kaskade (Zell-Fan-out) ──────────────────────────────

it('vollkaskade (speiseplan): ein Gericht-Step je leerer Zelle + MaterializeSpeiseplanCellJob', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SpeiseplanService::class);
    $plan = $svc->create($this->rootTeam, ['name' => 'Wochenplan', 'start_date' => '2026-08-03']);
    $plan->load('lines');
    // cycle_weeks × Mo–Fr × Linien, gedeckelt auf SPEISEPLAN_MAX_ZELLEN (Runaway-Schutz).
    $erwartet = min($plan->lines->count() * 5 * max(1, (int) $plan->cycle_weeks), 30);
    Queue::fake();

    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'vollkaskade', null, 'voll_kreativ', ['owner_type' => 'speiseplan', 'owner_id' => (int) $plan->id]);

    expect($run->source_owner_type)->toBe('speiseplan')
        ->and($run->steps()->where('kind', 'gericht')->count())->toBe($erwartet)
        ->and($erwartet)->toBeGreaterThan(0);
    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, $erwartet);
});

it('materialisiereSpeiseplanZelle: erdet die Zelle → Rezept + Speiseplan-Eintrag, Step done (Gen gemockt)', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SpeiseplanService::class);
    $plan = $svc->create($this->rootTeam, ['name' => 'Plan', 'start_date' => '2026-08-03']);
    $plan->load('lines');
    $line = $plan->lines->first();
    $recipe = $this->makeRecipe($this->rootTeam, 'Zell-Gericht', ['status' => 'draft', 'is_sales_recipe' => true]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'vollkaskade', 'source_owner_type' => 'speiseplan', 'source_owner_id' => $plan->id, 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    $this->mock(RecipeGeneratorService::class, fn ($m) => $m->shouldReceive('generiere')->once()
        ->andReturn(['recipe' => $recipe, 'statistik' => [], 'offene' => []]));

    app(PlanningCascadeService::class)->materialisiereSpeiseplanZelle($this->rootTeam, (int) $plan->id, '2026-08-03', 'mittag', (int) $line->id, 'Mittagsgericht', (int) $step->id);

    expect($step->refresh()->status)->toBe('done')
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag::where('menu_plan_id', $plan->id)->where('sales_recipe_id', $recipe->id)->count())->toBe(1);
});

it('Speiseplan-Editor: Voll-Kaskade-Go startet den Zell-Fan-out + redirected', function () {
    $plan = app(\Platform\FoodAlchemist\Services\SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Wochenplan', 'start_date' => '2026-08-03']);
    Queue::fake();

    Livewire::test(\Platform\FoodAlchemist\Livewire\Speiseplan\Editor::class)
        ->call('oeffnenBearbeiten', $plan->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect();   // → Planung-Editor

    expect(FoodAlchemistCascadeRun::where('source_owner_type', 'speiseplan')->where('source_owner_id', $plan->id)->count())->toBe(1);
    Queue::assertPushed(MaterializeSpeiseplanCellJob::class);
});

// ── Gestufte Kaskade (Gate pro Ebene) ───────────────────────────────────────

it('staged: Cockpit-Go ist gestuft (staged=true), opt-out über optionen möglich', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $svc = app(PlanningCascadeService::class);

    $an = $svc->starteKaskade($this->rootTeam, 'gericht', $session, 'voll_kreativ');
    $aus = $svc->starteKaskade($this->rootTeam, 'gericht', $session, 'voll_kreativ', ['staged' => false]);

    expect($an->staged)->toBeTrue()->and($aus->staged)->toBeFalse();
});

it('staged Freigabe (concept): dispatcht FanoutConceptJob + Run läuft wieder', function () {
    $concept = $this->makeConcept($this->rootTeam, 'K', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'review', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'done',
        'ref_type' => 'concept', 'ref_id' => $concept->id,
        'deferred' => ['fanout' => ['mode' => 'voll_kreativ', 'trend_doc_id' => null, 'planning_session_id' => null]],
    ]);

    app(PlanningCascadeService::class)->gibStepFrei($this->rootTeam, (int) $step->id);

    expect($step->refresh()->status)->toBe('freigegeben')
        ->and($concept->refresh()->status)->toBe('active')
        ->and($run->refresh()->status)->toBe('running');
    Queue::assertPushed(FanoutConceptJob::class, fn ($job) => (int) $job->cascadeStepId === (int) $step->id);
});

it('staged Freigabe (gericht): Anreicherung als Job + ki_bilder/ziel_vk aus den Lauf-Params', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Gericht-Draft', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true,
        'params' => ['ki_bilder' => true, 'ziel_vk_eur' => 12.5],
    ]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done',
        'ref_type' => 'recipe', 'ref_id' => $recipe->id,
    ]);

    app(PlanningCascadeService::class)->gibStepFrei($this->rootTeam, (int) $step->id);

    expect($step->refresh()->status)->toBe('freigegeben')
        ->and($recipe->refresh()->status->value)->toBe('approved');
    Queue::assertPushed(EnrichRecipeJob::class, fn ($job) => (int) $job->recipeId === (int) $recipe->id
        && $job->kiBilder === true && (float) $job->zielVk === 12.5);
});

it('staged Verwerfen (gericht): kein Kind-/Anreicherungs-Job', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Gericht-Draft', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done',
        'ref_type' => 'recipe', 'ref_id' => $recipe->id,
        'deferred' => ['children' => ['offene' => [['primaer' => 'basisrezept_anlegen', 'index' => 0, 'text' => 'Fond']], 'params' => [], 'user_id' => 0]],
    ]);

    app(PlanningCascadeService::class)->verwirfStep($this->rootTeam, (int) $step->id);

    expect($step->refresh()->status)->toBe('verworfen');
    Queue::assertNotPushed(EnrichRecipeJob::class);
    Queue::assertNotPushed(GenerateRecipeJob::class);
});

it('gibStufeFrei: gibt alle done-Steps einer Ebene frei', function () {
    $r1 = $this->makeRecipe($this->rootTeam, 'G1', ['status' => 'draft']);
    $r2 = $this->makeRecipe($this->rootTeam, 'G2', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'review', 'staged' => true]);
    $steps = collect([$r1, $r2])->map(fn ($r) => FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done',
        'ref_type' => 'recipe', 'ref_id' => $r->id,
    ]));

    app(PlanningCascadeService::class)->gibStufeFrei($this->rootTeam, (int) $run->id, 'gericht');

    expect($steps[0]->refresh()->status)->toBe('freigegeben')
        ->and($steps[1]->refresh()->status)->toBe('freigegeben');
});

it('neuGenerieren (regeneriereStep): verwirft das Draft und dispatcht die Generierung neu', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Regen', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done',
        'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Regen',
    ]);

    app(PlanningCascadeService::class)->regeneriereStep($this->rootTeam, (int) $step->id);

    expect($step->refresh()->status)->toBe('running')
        ->and($step->refresh()->ref_id)->toBeNull();
    Queue::assertPushed(GenerateRecipeJob::class);
});

it('goKaskade (concept): persistiert Leitplanken inkl. ki_bilder in generation_params', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Concept-Test', 'brief' => 'Sommer-Buffet.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'Sommer-Buffet.')
        ->set('regler.concept.ki_bilder', true)
        ->set('regler.concept.level', 'gehoben')
        ->call('goKaskade', 'concept');

    $session->refresh();
    expect($session->generation_params)->toBeArray()
        ->and($session->generation_params['ki_bilder'] ?? null)->toBeTrue()
        ->and($session->generation_params['level'] ?? null)->toBe('gehoben');
    Queue::assertPushed(GenerateConceptJob::class);
});

// ── Etappe 1: Gericht = Basisrezepte — die Sub-Rezepte sind eine eigene Stufe ───
// Beobachtung Dominique 2026-08-14: die Sub-Rezepte (Consommé/Espuma) lagen nur als 📖-Referenz IN
// der Zutatenliste. Sie gehören als abarbeitbare Basisrezepte-Stufe ins Cockpit — sichtbar, sobald
// das Gericht als Entwurf steht (`geplant`), bzw. als übernommener Reuse-Treffer (`skipped`).

it('staged: aufgeschobene Sub-Rezepte stehen sofort als geplante Basisrezepte-Stufe (noch kein Job)', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht mit Fond', ['status' => 'draft']);
    $this->makeIngredient($gericht, 'Geflügelfond');
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, (int) $step->id, (int) auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Geflügelfond', 'primaer' => 'basisrezept_anlegen']],
        ['_defer_children' => true],
    );

    $kind = FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->first();
    expect($kind)->not->toBeNull()
        ->and($kind->kind)->toBe('rezept')
        ->and($kind->status)->toBe('geplant')
        ->and($kind->label)->toBe('Geflügelfond')
        ->and((int) $kind->depth)->toBe(1)
        ->and($kind->generator_run_id)->toBeNull()
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency::where('child_step_id', $kind->id)->count())->toBe(1);
    Queue::assertNotPushed(GenerateRecipeJob::class);   // geplant heisst: wartet auf den Menschen
});

it('Freigabe des Gerichts schaltet die geplanten Basisrezepte scharf — derselbe Step, keine Dublette', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht mit Fond', ['status' => 'draft']);
    $this->makeIngredient($gericht, 'Geflügelfond');
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, (int) $step->id, (int) auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Geflügelfond', 'primaer' => 'basisrezept_anlegen']],
        ['_defer_children' => true],
    );
    $geplant = FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->firstOrFail();
    $step->update(['status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $gericht->id]);

    app(PlanningCascadeService::class)->gibStepFrei($this->rootTeam, (int) $step->id);

    $kinder = FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->get();
    expect($kinder)->toHaveCount(1)                                        // kein zweiter Step
        ->and((int) $kinder->first()->id)->toBe((int) $geplant->id)        // derselbe Step
        ->and($kinder->first()->status)->toBe('running')
        ->and($kinder->first()->generator_run_id)->not->toBeNull();
    Queue::assertPushed(GenerateRecipeJob::class, 1);
    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->description === 'Geflügelfond' && $job->vkModus === false);
});

it('Reuse-Sichtbarkeit: ein direkt verdrahtetes Sub-Rezept erscheint als übernommene Basisrezepte-Zeile', function () {
    $fond = $this->makeRecipe($this->rootTeam, 'Heller Geflügelfond');
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht mit Bestands-Fond', ['status' => 'draft']);
    $zutat = $this->makeIngredient($gericht, 'Geflügelfond');
    $zutat->update(['referenced_recipe_id' => $fond->id, 'match_method' => 'recipe_ref']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, (int) $step->id, (int) auth()->id(), $gericht, [], ['_defer_children' => true],
    );

    $kind = FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->first();
    expect($kind)->not->toBeNull()
        ->and($kind->kind)->toBe('rezept')
        ->and($kind->status)->toBe('skipped')                     // Reuse-Treffer: nichts zu erzeugen
        ->and($kind->label)->toBe('Heller Geflügelfond')
        ->and((int) $kind->ref_id)->toBe((int) $fond->id);
    Queue::assertNotPushed(GenerateRecipeJob::class);
});

it('Neu-Generieren räumt geplante + übernommene Sub-Zeilen weg — das Bestands-Rezept bleibt', function () {
    $fond = $this->makeRecipe($this->rootTeam, 'Heller Geflügelfond');
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht alt', ['status' => 'draft']);
    $this->makeIngredient($gericht, 'Geflügelfond');
    $zutat2 = $this->makeIngredient($gericht, 'Fond aus dem Bestand', null, '100', 2);
    $zutat2->update(['referenced_recipe_id' => $fond->id, 'match_method' => 'recipe_ref']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht',
        'status' => 'running', 'label' => 'Gericht alt',
    ]);
    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, (int) $step->id, (int) auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Geflügelfond', 'primaer' => 'basisrezept_anlegen']],
        ['_defer_children' => true],
    );
    expect(FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->count())->toBe(2);
    $step->update(['status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $gericht->id]);

    app(PlanningCascadeService::class)->regeneriereStep($this->rootTeam, (int) $step->id);

    expect(FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->count())->toBe(0)
        ->and(FoodAlchemistRecipe::find($fond->id))->not->toBeNull()          // fremdes Artefakt unangetastet
        ->and($step->refresh()->status)->toBe('running');
});

it('stufen(): die Basisrezepte-Stufe ist erreicht, sobald Sub-Rezepte geplant sind (zustand=geplant)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true]);
    $gerichtStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done']);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $gerichtStep->id, 'depth' => 1, 'kind' => 'rezept', 'status' => 'geplant', 'label' => 'Consommé']);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $gerichtStep->id, 'depth' => 1, 'kind' => 'rezept', 'status' => 'skipped', 'label' => 'Espuma-Basis']);

    $stufen = Livewire::test(PlanungIndex::class)->set('laufId', $run->id)->instance()->stufen();

    $basis = collect($stufen)->firstWhere('kind', 'rezept');
    expect($basis)->not->toBeNull()
        ->and($basis['total'])->toBe(2)
        ->and($basis['geplant'])->toBe(1)
        ->and($basis['uebernommen'])->toBe(1)
        ->and($basis['fertig'])->toBe(1)              // übernommen zählt als fertig, geplant nicht
        ->and($basis['zustand'])->toBe('geplant');
});

it('Run-Status: geplante Sub-Rezepte halten den Lauf auf review (Mensch am Zug), nicht auf failed', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running', 'staged' => true]);
    $gerichtStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'freigegeben']);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $gerichtStep->id, 'depth' => 1, 'kind' => 'rezept', 'status' => 'geplant', 'label' => 'Consommé']);

    app(PlanningCascadeService::class)->recomputeRunStatus((int) $run->id);

    expect($run->refresh()->status)->toBe('review');
});

// Etappe 1, Teil 2: geplante Sub-Rezepte einzeln bedienen — „jetzt erzeugen" (vorziehen) + „brauche
// ich nicht" (verwerfen) je Zeile, VOR der Freigabe der Stufe darüber.

it('erzeugeGeplantenStep: schaltet EIN geplantes Sub-Rezept einzeln scharf (geplant → running, ein Job)', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht mit Fond', ['status' => 'draft']);
    $this->makeIngredient($gericht, 'Geflügelfond');
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, (int) $step->id, (int) auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Geflügelfond', 'primaer' => 'basisrezept_anlegen']],
        ['_defer_children' => true],
    );
    $geplant = FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->firstOrFail();
    expect($geplant->status)->toBe('geplant');

    app(PlanningCascadeService::class)->erzeugeGeplantenStep($this->rootTeam, (int) $geplant->id);

    expect($geplant->refresh()->status)->toBe('running')
        ->and($geplant->generator_run_id)->not->toBeNull();
    Queue::assertPushed(GenerateRecipeJob::class, 1);
    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->description === 'Geflügelfond' && $job->vkModus === false);
});

it('verwirfGeplantenStep: ein geplantes Sub-Rezept verwerfen → verworfen, Dependency weg, kein Job', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht mit Fond', ['status' => 'draft']);
    $this->makeIngredient($gericht, 'Geflügelfond');
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, (int) $step->id, (int) auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Geflügelfond', 'primaer' => 'basisrezept_anlegen']],
        ['_defer_children' => true],
    );
    $geplant = FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->firstOrFail();

    app(PlanningCascadeService::class)->verwirfGeplantenStep($this->rootTeam, (int) $geplant->id);

    expect($geplant->refresh()->status)->toBe('verworfen')
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency::where('child_step_id', $geplant->id)->count())->toBe(0);
    Queue::assertNotPushed(GenerateRecipeJob::class);
});

it('verworfenes Sub-Rezept bleibt weg: die Gericht-Freigabe erzeugt es nicht wieder (dedupe-Tombstone)', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht mit Fond', ['status' => 'draft']);
    $this->makeIngredient($gericht, 'Geflügelfond');
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, (int) $step->id, (int) auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Geflügelfond', 'primaer' => 'basisrezept_anlegen']],
        ['_defer_children' => true],
    );
    $geplant = FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->firstOrFail();
    app(PlanningCascadeService::class)->verwirfGeplantenStep($this->rootTeam, (int) $geplant->id);
    $step->update(['status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $gericht->id]);

    app(PlanningCascadeService::class)->gibStepFrei($this->rootTeam, (int) $step->id);

    // kein GenerateRecipeJob fürs verworfene Sub-Rezept: dispatchChildren trifft den dedupe-Tombstone
    // (Status ≠ geplant) und überspringt ihn. (Die Anreicherung des Gerichts ist ein EnrichRecipeJob.)
    Queue::assertNotPushed(GenerateRecipeJob::class);
    $kinder = FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->get();
    expect($kinder)->toHaveCount(1)
        ->and($kinder->first()->status)->toBe('verworfen');
});

it('stufen(): leitet Stufen-Zähler + Zustand aus den Steps ab (nur erreichte Stufen)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'review', 'staged' => true]);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'freigegeben']);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done']);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    $stufen = Livewire::test(PlanungIndex::class)->set('laufId', $run->id)->instance()->stufen();

    expect($stufen)->toHaveCount(2);   // concept + gericht erreicht; rezept nicht (0 Steps)
    $g = collect($stufen)->firstWhere('kind', 'gericht');
    expect($g['total'])->toBe(2)->and($g['zustand'])->toBe('läuft');
});

// ── Auto-Trigger: Menü-Folge-Kohärenz-Gate nach der Fan-out-Erfindung ───────
//
// Roadmap Etappe 1 »Auto-Trigger«: sobald ALLE erfundenen Gericht-Steps eines Concept-Steps durch
// sind (Grounding komplett → Anker vorhanden → scorebar), scored der Motor die Menüfolge automatisch
// (menuCohesion → menuKohaesionWarnung) und persistiert sie am Run (`cohesion_warning`) — ohne den
// manuellen „Kohäsion prüfen"-Klick. Vorher (noch ein Kind offen) darf er NICHT feuern.

// Builder als $this-gebundene Closure (freie Funktionen kommen nicht an die protected TestCase-Helfer).
beforeEach(function () {
    /** Baut einen minimalen Pairing-Graph + zwei verbundene Menü-Gerichte, in ein Konzept verdrahtet. */
    $this->mkVerbundenesMenu = function (): \Platform\FoodAlchemist\Models\FoodAlchemistConcept {
        $ins = function (string $table, array $row): int {
            \Illuminate\Support\Facades\DB::table($table)->insert(array_merge(
                ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'created_at' => now(), 'updated_at' => now()],
                $row
            ));

            return (int) \Illuminate\Support\Facades\DB::getPdo()->lastInsertId();
        };
        $aId = $ins('foodalchemist_vocab_pairing_anchors', ['slug' => 'mkg-pilz', 'display_de' => 'Pilz']);
        $bId = $ins('foodalchemist_vocab_pairing_anchors', ['slug' => 'mkg-rind', 'display_de' => 'Rind']);
        // Kante A↔B → bewertetes Gericht-Paar (rated_pairs ≥ 1 ⇒ das Gate hat eine Aussage).
        $ins('foodalchemist_pairing_anchor_edges',
            ['anchor_a_id' => $aId, 'anchor_b_id' => $bId, 'type' => 'aroma', 'level' => 3, 'weight' => 0.9]);

        $mkGericht = function (string $key, string $gpName, int $ankerId) use ($ins): \Platform\FoodAlchemist\Models\FoodAlchemistRecipe {
            $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::create([
                'team_id' => $this->rootTeam->id, 'gp_key' => 'mkg|' . $key, 'name' => $gpName, 'status' => 'approved',
            ]);
            $ins('foodalchemist_gp_anchor_mappings', [
                'team_id' => $this->rootTeam->id, 'gp_id' => $gp->id, 'anchor_id' => $ankerId,
                'role' => 'kern', 'source' => 'ai_inferred', 'ai_confidence' => null,
            ]);
            $r = $this->makeRecipe($this->rootTeam, $gpName, ['status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 22.0]);
            $this->makeIngredient($r, $gpName, $gp, '150', 1);

            return $r;
        };
        $pilz = $mkGericht('pilz', 'Steinpilz-Consommé', $aId);
        $rind = $mkGericht('rind', 'Rinderfilet', $bId);

        $concept = $this->makeConcept($this->rootTeam, 'Fan-out-Menü', ['status' => 'draft']);
        $this->makeConceptSlot($concept, ['position' => 1, 'sales_recipe_id' => $pilz->id]);
        $this->makeConceptSlot($concept, ['position' => 2, 'sales_recipe_id' => $rind->id]);

        return $concept;
    };
});

it('Auto-Trigger: scored + persistiert die Menü-Warnung erst, wenn ALLE erfundenen Gerichte durch sind', function () {
    $concept = ($this->mkVerbundenesMenu)();
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running', 'staged' => true]);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'freigegeben', 'ref_type' => 'concept', 'ref_id' => $concept->id]);
    $g1 = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'parent_step_id' => $conceptStep->id, 'status' => 'running']);
    $g2 = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'parent_step_id' => $conceptStep->id, 'status' => 'running']);

    $svc = app(PlanningCascadeService::class);

    // Erstes Kind fertig — das zweite läuft noch → Gate schweigt (die Folge ist noch nicht komplett geerdet).
    $svc->markStepDone((int) $g1->id, 'recipe', 111);
    expect($run->refresh()->cohesion_warning)->toBeNull();

    // Letztes Kind fertig → Fan-out abgeschlossen → Gate feuert automatisch und persistiert die Warnung.
    $svc->markStepDone((int) $g2->id, 'recipe', 222);
    $warnung = $run->refresh()->cohesion_warning;
    expect($warnung)->not->toBeNull()
        ->and($warnung)->toHaveKeys(['stufe', 'score', 'text'])
        ->and($warnung['stufe'])->toBeIn(['gut', 'schwach', 'kritisch']);
});

it('Auto-Trigger (eager): der Concept-Step schließt ab, wenn seine erfundenen Gerichte schon durch sind', function () {
    $concept = ($this->mkVerbundenesMenu)();
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running']);
    // Kinder liefen schon inline durch (eager-Pfad: Fan-out VOR meldeKaskade).
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'parent_step_id' => $conceptStep->id, 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => 111]);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'parent_step_id' => $conceptStep->id, 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => 222]);

    // Jetzt meldet der Concept-Step selbst „done" (meldeKaskade nach dem Fan-out) → ref gesetzt → scorebar.
    app(PlanningCascadeService::class)->markStepDone((int) $conceptStep->id, 'concept', (int) $concept->id);

    expect($run->refresh()->cohesion_warning)->not->toBeNull()
        ->and($run->refresh()->cohesion_warning)->toHaveKey('stufe');
});

it('Auto-Trigger schweigt, wenn es keine erfundenen Gerichte gibt (kein Fan-out, keine Folge)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Ohne Fan-out', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running']);

    app(PlanningCascadeService::class)->markStepDone((int) $conceptStep->id, 'concept', (int) $concept->id);

    expect($run->refresh()->cohesion_warning)->toBeNull();   // nichts erfunden → nichts zu beurteilen
});

it('Auto-Trigger rührt freie Gericht-Läufe (kein Concept-Eltern) nicht an', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'HG: Solo | Gericht', ['status' => 'draft', 'is_sales_recipe' => true]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    app(PlanningCascadeService::class)->markStepDone((int) $step->id, 'recipe', (int) $recipe->id);

    expect($run->refresh()->cohesion_warning)->toBeNull()   // kein Menü-Gate für Einzel-Gerichte
        ->and($run->refresh()->status)->toBe('review');
});
