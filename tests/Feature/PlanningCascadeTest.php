<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
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

// ── Etappe 2b: geplanter Pfad — existing_concept_id (KI-Kopf → geprüfter Draft → Go) ──────

it('Go (concept, existing_concept_id): kein GenerateConceptJob — Step zeigt auf den geprüften Draft, staged → review + deferred.fanout', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Geprüfter Plan', ['status' => 'draft']);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer-Buffet', 'brief' => 'Leichtes Sommer-Buffet.']);

    $run = app(PlanningCascadeService::class)->starteKaskade(
        $this->rootTeam, 'concept', $session, 'voll_kreativ', ['existing_concept_id' => (int) $concept->id]
    );

    $step = $run->steps()->first();
    expect($step->kind)->toBe('concept')
        ->and($step->status)->toBe('done')
        ->and($step->ref_type)->toBe('concept')
        ->and((int) $step->ref_id)->toBe((int) $concept->id)
        ->and($step->deferred['fanout']['mode'] ?? null)->toBe('voll_kreativ')
        ->and((int) ($step->deferred['fanout']['planning_session_id'] ?? 0))->toBe((int) $session->id)
        ->and($run->refresh()->status)->toBe('review')
        ->and((bool) $run->staged)->toBeTrue();
    // Nicht neu generiert; staged → der Gericht-Fan-out feuert erst bei der Freigabe (FanoutConceptJob).
    Queue::assertNotPushed(GenerateConceptJob::class);
    Queue::assertNotPushed(FanoutConceptJob::class);
});

it('Fan-out-Job-Hook: failed() meldet an den Concept-Step zurück → Run failed + sichtbarer Grund (Fehler-Transparenz)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Geprüfter Plan', ['status' => 'draft']);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = app(PlanningCascadeService::class)->starteKaskade(
        $this->rootTeam, 'concept', $session, 'voll_kreativ', ['existing_concept_id' => (int) $concept->id]
    );
    $step = $run->steps()->first();
    expect($step->kind)->toBe('concept');   // Vorbedingung: der Fan-out-Job hängt am Concept-Step

    // Ohne den Haken schluckte der Fan-out seine Fehler still (kein Rückkanal, `finally` recomputet blind).
    $job = new FanoutConceptJob($this->rootTeam->id, 1, (int) $step->id);
    $job->failed(new RuntimeException('provider down'));

    expect($step->refresh()->status)->toBe('failed')
        ->and($step->error)->toContain('Gericht-Fan-out abgebrochen')
        ->and($step->error)->toContain('provider down')
        ->and($run->refresh()->status)->toBe('failed');
});

it('Go (concept, existing_concept_id, staged=false): fächert sofort auf (FanoutConceptJob im Worker), Run läuft', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Geprüfter Plan', ['status' => 'draft']);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);

    $run = app(PlanningCascadeService::class)->starteKaskade(
        $this->rootTeam, 'concept', $session, 'voll_kreativ',
        ['existing_concept_id' => (int) $concept->id, 'staged' => false]
    );

    $step = $run->steps()->first();
    expect($step->status)->toBe('done')
        ->and((int) $step->ref_id)->toBe((int) $concept->id)
        ->and($run->refresh()->status)->toBe('running');
    Queue::assertNotPushed(GenerateConceptJob::class);
    Queue::assertPushed(FanoutConceptJob::class, fn ($job) => (int) $job->cascadeStepId === (int) $step->id);
});

it('Go (concept, existing_concept_id, datenbank): Reuse-Modus fächert nicht auf — Step done ohne deferred.fanout', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Geprüfter Plan', ['status' => 'draft']);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);

    $run = app(PlanningCascadeService::class)->starteKaskade(
        $this->rootTeam, 'concept', $session, 'datenbank', ['existing_concept_id' => (int) $concept->id]
    );

    $step = $run->steps()->first();
    expect($step->status)->toBe('done')
        ->and($step->deferred)->toBeNull()
        ->and($run->refresh()->status)->toBe('review');
    Queue::assertNotPushed(GenerateConceptJob::class);
    Queue::assertNotPushed(FanoutConceptJob::class);
});

it('Go (concept, existing_concept_id): Fremd-Team-Concept wirft (isOwnedBy) und legt keinen Rumpf-Lauf an', function () {
    $fremd = $this->makeConcept($this->childB, 'Fremder Plan', ['status' => 'draft']);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $vorher = FoodAlchemistCascadeRun::count();

    expect(fn () => app(PlanningCascadeService::class)->starteKaskade(
        $this->rootTeam, 'concept', $session, 'voll_kreativ', ['existing_concept_id' => (int) $fremd->id]
    ))->toThrow(RuntimeException::class);

    // Ownership-Guard VOR der Run-Anlage → kein verwaister Lauf, kein Job.
    expect(FoodAlchemistCascadeRun::count())->toBe($vorher);
    Queue::assertNotPushed(GenerateConceptJob::class);
    Queue::assertNotPushed(FanoutConceptJob::class);
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

it('#124 markFanoutFailed: Fan-out-Abbruch lässt den freigegebenen Concept-Step live (nur fanout_error)', function () {
    $svc = app(PlanningCascadeService::class);
    $concept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommermenü', 'status' => 'active']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept',
        'status' => 'freigegeben', 'ref_type' => 'concept', 'ref_id' => $concept->id,
        'deferred' => ['fanout' => ['mode' => 'voll_kreativ']],
    ]);

    $svc->markFanoutFailed((int) $step->id, 'Divergenz-Timeout');

    $step->refresh();
    expect($step->status)->toBe('freigegeben')                                 // Concept bleibt live, NICHT failed
        ->and($step->deferred['fanout_error'] ?? null)->toContain('Divergenz-Timeout')
        ->and(isset($step->deferred['fanout']))->toBeFalse();                  // verbrauchte Fan-out-Args weg
});

it('#124 markFanoutFailed: NICHT-freigegebener Step → echter Step-Fehler (Fallback)', function () {
    $svc = app(PlanningCascadeService::class);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running', 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running',
    ]);

    $svc->markFanoutFailed((int) $step->id, 'Concept nie live');

    expect($step->refresh()->status)->toBe('failed');                          // war nie freigegeben → echter Fehler
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

// ── Etappe 8 — Idempotenz/Resume: verwaiste in-flight Steps reapen ───────────────────────────
// Stirbt ein Generator-Job hart (OOM/Timeout/Worker-Kill) OHNE failed()-Rückkanal, bleibt sein Step
// ewig queued/running und der Run steckt in `running` (Sackgasse: verwerfen/freigeben setzen done/
// failed voraus). reapeVerwaisteSteps markiert NUR wirklich verwaiste (alte) in-flight Steps als
// failed → der Run wird wieder bewertet und handlungsfähig. Junge Steps bleiben unangetastet.

it('reapeVerwaisteSteps: ein verwaister running-Step wird failed und der Run wieder bewertet', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    // Raw-Update (kein Timestamp-Touch): der Job liegt seit 45 Min ohne Rückmeldung.
    FoodAlchemistCascadeRunStep::where('id', $step->id)->update(['updated_at' => now()->subMinutes(45)]);

    $n = app(PlanningCascadeService::class)->reapeVerwaisteSteps($this->rootTeam, (int) $run->id);

    expect($n)->toBe(1)
        ->and($step->refresh()->status)->toBe('failed')
        ->and($step->error)->toContain('verwaist')
        ->and($run->refresh()->status)->toBe('failed');   // einziger Step gescheitert
});

it('reapeVerwaisteSteps: ein junger in-flight Step bleibt unangetastet (kein Abwürgen)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    // frisch dispatcht (updated_at = jetzt) → noch nicht verwaist, evtl. noch lebender Job
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    $n = app(PlanningCascadeService::class)->reapeVerwaisteSteps($this->rootTeam, (int) $run->id);

    expect($n)->toBe(0)
        ->and($step->refresh()->status)->toBe('running')
        ->and($run->refresh()->status)->toBe('running');
});

it('reapeVerwaisteSteps: gemischt — nur der verwaiste Step wird gereapt, done/geplant unberührt (Run → review)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $done = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => 1]);
    $geplant = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'geplant']);
    $alt = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'queued']);
    FoodAlchemistCascadeRunStep::where('id', $alt->id)->update(['updated_at' => now()->subMinutes(45)]);

    $n = app(PlanningCascadeService::class)->reapeVerwaisteSteps($this->rootTeam, (int) $run->id);

    expect($n)->toBe(1)
        ->and($alt->refresh()->status)->toBe('failed')
        ->and($done->refresh()->status)->toBe('done')
        ->and($geplant->refresh()->status)->toBe('geplant')
        ->and($run->refresh()->status)->toBe('review');   // done/geplant übrig → review, nicht failed
});

// T2 (Real-Abnahme): ein Basisrezept, das die KI nicht erkannt hat, von Hand nachziehen.
it('T2: ergaenzeManuellenSubStep legt einen geplanten rezept-Sub-Step unter der Wurzel an (idempotent, team-owned)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true, 'brief' => 'Teller']);
    $gericht = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'freigegeben', 'label' => 'Teller', 'depth' => 0, 'sort' => 1, 'parent_step_id' => null]);

    $svc = app(PlanningCascadeService::class);
    $step = $svc->ergaenzeManuellenSubStep($this->rootTeam, (int) $run->id, 'Schweinejus');

    expect($step)->not->toBeNull()
        ->and($step->kind)->toBe('rezept')
        ->and($step->status)->toBe('geplant')
        ->and((int) $step->parent_step_id)->toBe((int) $gericht->id)   // hängt unter der Wurzel
        ->and($step->label)->toBe('Schweinejus')
        ->and((int) $step->depth)->toBe(1)
        ->and($run->refresh()->status)->toBe('review');                // geplant → review (Stufe zeigt ihn)

    // Idempotent (dedupe manual:name): gleicher Name → derselbe Step, kein zweiter.
    $again = $svc->ergaenzeManuellenSubStep($this->rootTeam, (int) $run->id, 'Schweinejus');
    expect((int) $again->id)->toBe((int) $step->id)
        ->and(FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('kind', 'rezept')->count())->toBe(1);

    // Tenancy (D1): ein Kind-Team besitzt den Root-Lauf nicht → null, kein Step angelegt.
    expect($svc->ergaenzeManuellenSubStep($this->childA, (int) $run->id, 'Fremd-Jus'))->toBeNull()
        ->and(FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('label', 'Fremd-Jus')->exists())->toBeFalse();
});

// ── Etappe 8 — Idempotenz/Resume Teil 2: gescheiterte Steps gebündelt re-dispatchen ──────────────
// Teil 1 macht harte Hänger zu `failed`. Teil 2 (setzeLaufFort) nimmt ALLE failed-Steps auf einmal
// wieder auf — statt sie einzeln über regeneriereStep neu zu generieren. IDEMPOTENT gegen Doppel-
// Jobs: nur failed-Steps werden angefasst (in-flight/done/geplant bleiben unberührt); da der Step
// sofort auf `running` flippt, dispatcht ein zweiter Aufruf keinen Doppel-Job.

it('setzeLaufFort: alle failed-Steps werden gebündelt re-dispatcht, andere Kinds unberührt', function () {
    $g1 = $this->makeRecipe($this->rootTeam, 'Fail-Gericht 1', ['status' => 'draft']);
    $g2 = $this->makeRecipe($this->rootTeam, 'Fail-Gericht 2', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'failed', 'staged' => true]);
    $f1 = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'failed', 'ref_type' => 'recipe', 'ref_id' => $g1->id, 'label' => 'Fail-Gericht 1']);
    $f2 = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'failed', 'ref_type' => 'recipe', 'ref_id' => $g2->id, 'label' => 'Fail-Rezept 2']);
    // Diese beiden dürfen NICHT re-dispatcht werden (idempotent / kein Doppel-Job):
    $laeuft = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'label' => 'läuft noch']);
    $fertig = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => 1]);

    $n = app(PlanningCascadeService::class)->setzeLaufFort($this->rootTeam, (int) $run->id);

    expect($n)->toBe(2)
        ->and($f1->refresh()->status)->toBe('running')   // re-dispatcht → running, Draft verworfen
        ->and($f1->refresh()->ref_id)->toBeNull()
        ->and($f2->refresh()->status)->toBe('running')
        ->and($laeuft->refresh()->status)->toBe('running')  // in-flight unberührt
        ->and($fertig->refresh()->status)->toBe('done');    // done unberührt
    Queue::assertPushed(GenerateRecipeJob::class, 2);        // genau zwei Jobs, keine Dublette
});

it('setzeLaufFort: ohne failed-Step passiert nichts (kein Job, Rückgabe 0)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true]);
    $done = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => 1]);

    $n = app(PlanningCascadeService::class)->setzeLaufFort($this->rootTeam, (int) $run->id);

    expect($n)->toBe(0)
        ->and($done->refresh()->status)->toBe('done');
    Queue::assertNotPushed(GenerateRecipeJob::class);
});

it('setzeLaufFort: der zweite Aufruf ist idempotent — kein Doppel-Job (Steps schon running)', function () {
    $g1 = $this->makeRecipe($this->rootTeam, 'Resume-Idempotenz', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'failed', 'staged' => true]);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'failed', 'ref_type' => 'recipe', 'ref_id' => $g1->id, 'label' => 'Resume-Idempotenz']);

    $svc = app(PlanningCascadeService::class);
    $erst = $svc->setzeLaufFort($this->rootTeam, (int) $run->id);
    $zweit = $svc->setzeLaufFort($this->rootTeam, (int) $run->id);  // Doppel-Klick, Step ist jetzt running

    expect($erst)->toBe(1)
        ->and($zweit)->toBe(0);   // beim zweiten Mal ist nichts mehr failed → kein Re-Dispatch
    Queue::assertPushed(GenerateRecipeJob::class, 1);
});

// ── Etappe 7 — Bild-Status Teil 2: explizite Fehler-Persistenz (deferred.bilder) ─────────────
// Der EnrichRecipeJob hält das KI-Foto-Ergebnis jetzt sichtbar am Step fest (status done|failed + n),
// statt es still fail-soft zu schlucken. Ein einzelner fehlgeschlagener Call macht die Erzeugung
// als Ganzes »failed« (Teil-Erfolge trägt `n`); ein Voll-Erfolg »done«.

it('EnrichRecipeJob: Bild-Fehler wird als deferred.bilder=failed am Step persistiert', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-Fehler', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);

    $this->mock(RecipeOneShotService::class, fn ($m) => $m->shouldReceive('anreichern')->once());
    $this->mock(RecipeImageService::class, fn ($m) => $m->shouldReceive('erzeugeFuerRezept')->once()
        ->andReturn(['erzeugt' => 0, 'fehler' => 1, 'letzter_fehler' => 'API down']));

    (new EnrichRecipeJob($this->rootTeam->id, (int) auth()->id(), (int) $recipe->id, null, true, (int) $step->id))
        ->handle(app(RecipeOneShotService::class));

    $bilder = $step->refresh()->deferred['bilder'] ?? null;
    expect($bilder['status'] ?? null)->toBe('failed')
        ->and($bilder['n'] ?? null)->toBe(0)
        ->and($bilder['error'] ?? '')->toContain('API down')
        ->and($step->deferred['enrich']['status'] ?? null)->toBe('done');   // Anreicherung bleibt unabhängig
});

it('EnrichRecipeJob: Bild-Erfolg wird als deferred.bilder=done mit n persistiert', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-OK', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);

    $this->mock(RecipeOneShotService::class, fn ($m) => $m->shouldReceive('anreichern')->once());
    $this->mock(RecipeImageService::class, fn ($m) => $m->shouldReceive('erzeugeFuerRezept')->once()
        ->andReturn(['erzeugt' => 3, 'fehler' => 0, 'letzter_fehler' => null]));

    (new EnrichRecipeJob($this->rootTeam->id, (int) auth()->id(), (int) $recipe->id, null, true, (int) $step->id))
        ->handle(app(RecipeOneShotService::class));

    $bilder = $step->refresh()->deferred['bilder'] ?? null;
    expect($bilder['status'] ?? null)->toBe('done')
        ->and($bilder['n'] ?? null)->toBe(3)
        ->and($bilder['error'] ?? null)->toBeNull();   // null-error wird nicht persistiert
});

it('EnrichRecipeJob: wirft die Bild-Erzeugung, bleibt fail-soft und persistiert deferred.bilder=failed', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-Boom', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);

    $this->mock(RecipeOneShotService::class, fn ($m) => $m->shouldReceive('anreichern')->once());
    $this->mock(RecipeImageService::class, fn ($m) => $m->shouldReceive('erzeugeFuerRezept')->once()
        ->andThrow(new RuntimeException('boom')));

    // Fail-soft: der Job darf NICHT rethrowen (die Freigabe/Anreicherung ist längst durch).
    (new EnrichRecipeJob($this->rootTeam->id, (int) auth()->id(), (int) $recipe->id, null, true, (int) $step->id))
        ->handle(app(RecipeOneShotService::class));

    $bilder = $step->refresh()->deferred['bilder'] ?? null;
    expect($bilder['status'] ?? null)->toBe('failed')
        ->and($bilder['error'] ?? '')->toContain('boom')
        ->and($step->deferred['enrich']['status'] ?? null)->toBe('done');
});

it('EnrichRecipeJob: ohne ki_bilder-Toggle wird KEIN deferred.bilder geschrieben', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Kein-Bild', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);

    $this->mock(RecipeOneShotService::class, fn ($m) => $m->shouldReceive('anreichern')->once());
    $this->mock(RecipeImageService::class, fn ($m) => $m->shouldNotReceive('erzeugeFuerRezept'));

    (new EnrichRecipeJob($this->rootTeam->id, (int) auth()->id(), (int) $recipe->id, null, false, (int) $step->id))
        ->handle(app(RecipeOneShotService::class));

    expect($step->refresh()->deferred['bilder'] ?? null)->toBeNull()
        ->and($step->deferred['enrich']['status'] ?? null)->toBe('done');
});

// ── Etappe 8 — Fehler-Transparenz (Images): harter Job-Abbruch der richtigen Phase zuordnen ──
// failed() (Timeout/OOM, nicht vom inneren catch gefangen) darf einen Abbruch NACH abgeschlossener
// Anreicherung nicht der Anreicherung unterschieben (enrich=done überschreiben), sondern der
// Bild-Phase (deferred.bilder=failed). Sonst zeigt das Cockpit fälschlich „Anreicherung fehlgeschlagen".

it('EnrichRecipeJob failed(): harter Abbruch NACH enrich=done wird der Bild-Phase zugeordnet', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-Timeout', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht',
        'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id,
        'deferred' => ['enrich' => ['status' => 'done', 'at' => now()->toIso8601String()]],
    ]);

    // Voll-Modus (nurBilder=false), Bilder angefordert (kiBilder=true), Anreicherung längst done →
    // ein harter Job-Abbruch (Timeout/OOM in der Bild-Phase) gehört an deferred.bilder.
    (new EnrichRecipeJob($this->rootTeam->id, (int) auth()->id(), (int) $recipe->id, null, true, (int) $step->id))
        ->failed(new RuntimeException('image timeout'));

    $step->refresh();
    expect($step->deferred['bilder']['status'] ?? null)->toBe('failed')
        ->and($step->deferred['bilder']['error'] ?? '')->toContain('image timeout')
        ->and($step->deferred['enrich']['status'] ?? null)->toBe('done');   // NICHT überschrieben
});

it('EnrichRecipeJob failed(): harter Abbruch bei laufender Anreicherung bleibt bei enrich=failed', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Enrich-Timeout', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht',
        'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id,
        'deferred' => ['enrich' => ['status' => 'running', 'at' => now()->toIso8601String()]],
    ]);

    // Anreicherung war noch nicht durch → der Abbruch betrifft die Anreicherung selbst, keine erfundene Bild-Zeile.
    (new EnrichRecipeJob($this->rootTeam->id, (int) auth()->id(), (int) $recipe->id, null, true, (int) $step->id))
        ->failed(new RuntimeException('enrich timeout'));

    $step->refresh();
    expect($step->deferred['enrich']['status'] ?? null)->toBe('failed')
        ->and($step->deferred['enrich']['error'] ?? '')->toContain('enrich timeout')
        ->and($step->deferred['bilder'] ?? null)->toBeNull();
});

it('EnrichRecipeJob failed(): ohne ki_bilder bleibt der harte Abbruch bei enrich=failed (keine Bild-Phase)', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Kein-Bild-Timeout', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht',
        'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id,
        'deferred' => ['enrich' => ['status' => 'done', 'at' => now()->toIso8601String()]],
    ]);

    // enrich=done, aber KEINE Bilder angefordert → es gab keine Bild-Phase; der Abbruch gehört an enrich.
    (new EnrichRecipeJob($this->rootTeam->id, (int) auth()->id(), (int) $recipe->id, null, false, (int) $step->id))
        ->failed(new RuntimeException('boom'));

    $step->refresh();
    expect($step->deferred['enrich']['status'] ?? null)->toBe('failed')
        ->and($step->deferred['bilder'] ?? null)->toBeNull();
});

// ── Etappe 7 — Bild-Status Teil 2b: „neu erzeugen" (Fotos allein re-triggern, ohne Voll-Anreicherung) ──
// Der EnrichRecipeJob läuft im `nurBilder`-Modus: er ruft KEINE Anreicherung, ersetzt die alten
// KI-Fotos (loescheKiFotos) und erzeugt sie neu; deferred.enrich bleibt unangetastet.

it('EnrichRecipeJob nurBilder: erzeugt NUR die Fotos neu (kein anreichern, ersetzt Alt-Fotos) und persistiert deferred.bilder', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Nur-Bilder', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'freigegeben',
        'ref_type' => 'recipe', 'ref_id' => $recipe->id,
        'deferred' => ['enrich' => ['status' => 'done'], 'bilder' => ['status' => 'failed', 'error' => 'alt', 'n' => 0]],
    ]);

    $this->mock(RecipeOneShotService::class, fn ($m) => $m->shouldReceive('anreichern')->never());
    $this->mock(RecipeImageService::class, function ($m) {
        $m->shouldReceive('loescheKiFotos')->once()->andReturn(1);   // Alt-Fotos ersetzt, nicht angehäuft
        $m->shouldReceive('erzeugeFuerRezept')->once()->andReturn(['erzeugt' => 3, 'fehler' => 0, 'letzter_fehler' => null]);
    });

    (new EnrichRecipeJob($this->rootTeam->id, (int) auth()->id(), (int) $recipe->id, null, false, (int) $step->id, true))
        ->handle(app(RecipeOneShotService::class));

    $step->refresh();
    expect($step->deferred['bilder']['status'] ?? null)->toBe('done')
        ->and($step->deferred['bilder']['n'] ?? null)->toBe(3)
        ->and($step->deferred['enrich']['status'] ?? null)->toBe('done');   // Anreicherung unangetastet
});

it('reBilder: dispatcht den EnrichRecipeJob im nurBilder-Modus und setzt deferred.bilder=queued', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Re-Bilder', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'params' => ['ki_bilder' => true]]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'freigegeben',
        'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'deferred' => ['enrich' => ['status' => 'done'], 'bilder' => ['status' => 'failed']],
    ]);

    app(PlanningCascadeService::class)->reBilder($this->rootTeam, (int) $step->id);

    expect($step->refresh()->deferred['bilder']['status'] ?? null)->toBe('queued');
    Queue::assertPushed(EnrichRecipeJob::class, fn ($job) => (int) $job->recipeId === (int) $recipe->id && $job->nurBilder === true);
});

it('reBilder: ein Concept-Step (kein Rezept) löst KEINEN Job aus', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'freigegeben', 'ref_type' => 'concept', 'ref_id' => 1,
    ]);

    app(PlanningCascadeService::class)->reBilder($this->rootTeam, (int) $step->id);

    Queue::assertNotPushed(EnrichRecipeJob::class);
});

it('loescheKiFotos: entfernt KI-Fotos (Call-Log), lässt manuelle Uploads stehen', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Foto-Purge', ['status' => 'draft']);
    // Zwei Fotos: eins mit Kosten-Call (KI-erzeugt), eins ohne (manueller Upload).
    $ki = FoodAlchemistRecipeStepPhoto::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'pfad' => 'ki.jpg']);
    $manuell = FoodAlchemistRecipeStepPhoto::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'pfad' => 'manuell.jpg']);
    DB::table('foodalchemist_ai_call_log')->insert([
        'uuid' => (string) Str::orderedUuid(), 'team_id' => $this->rootTeam->id, 'user_id' => null,
        'feature' => RecipeImageService::FEATURE_PRODUKTFOTO, 'tier' => 'I', 'model' => 'gpt-image-1.5',
        'prompt_hash' => 'x', 'response_summary' => 'x', 'tokens_in' => 0, 'tokens_out' => 0,
        'target_table' => 'foodalchemist_recipe_step_photos', 'target_id' => $ki->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $n = app(RecipeImageService::class)->loescheKiFotos($this->rootTeam, $recipe);

    expect($n)->toBe(1)
        ->and(FoodAlchemistRecipeStepPhoto::find($ki->id))->toBeNull()          // KI-Foto (soft-)gelöscht
        ->and(FoodAlchemistRecipeStepPhoto::find($manuell->id))->not->toBeNull(); // manueller Upload bleibt
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

// D1-Audit (Etappe 8, Multi-Tenancy »Writes isOwnedBy konsequent«): jede step-mutierende
// Kaskaden-Methode muss einen GEERBTEN (visibleToTeam, aber nicht besessenen) Step über {@see
// ownedStep} abweisen. gibStepFrei ist oben einzeln gepinnt; dieser Datensatz sperrt die übrigen
// Schreib-Endpunkte gegen Cross-Tenant-Writes — sonst bliebe ein Refactor, der ownedStep in einer
// dieser Methoden umgeht, ununterscheidbar von korrektem Code. ownedStep steht in JEDER dieser
// Methoden als erste Anweisung → der Wurf greift VOR jeder Zustands-/Argument-Nutzung.
it('D1-Audit: geerbter Step wird von jeder Schreib-Methode abgewiesen (isOwnedBy, konsequent)', function (Closure $write) {
    $recipe = $this->makeRecipe($this->rootTeam, 'D1-Audit', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id,
    ]);
    $svc = app(PlanningCascadeService::class);

    // childA erbt den Root-Katalog (sichtbar), besitzt ihn aber NICHT → Schreiben verboten.
    expect(fn () => $write($svc, $this->childA, (int) $step->id))->toThrow(RuntimeException::class);

    // Nichts passiert: Step-Status + Rezept unangetastet, kein Job dispatcht.
    expect($step->refresh()->status)->toBe('done')
        ->and($recipe->refresh()->status->value)->toBe('draft');
    Queue::assertNothingPushed();
})->with([
    'reBilder' => [fn ($svc, $team, $id) => $svc->reBilder($team, $id)],
    'reAnreichern' => [fn ($svc, $team, $id) => $svc->reAnreichern($team, $id)],
    'regeneriereStep' => [fn ($svc, $team, $id) => $svc->regeneriereStep($team, $id)],
    'verwirfStep' => [fn ($svc, $team, $id) => $svc->verwirfStep($team, $id)],
    'erzeugeGeplantenStep' => [fn ($svc, $team, $id) => $svc->erzeugeGeplantenStep($team, $id)],
    'verwirfGeplantenStep' => [fn ($svc, $team, $id) => $svc->verwirfGeplantenStep($team, $id)],
    'uebernimmManuellesFotoFuerStep' => [fn ($svc, $team, $id) => $svc->uebernimmManuellesFotoFuerStep($team, $id, \Illuminate\Http\UploadedFile::fake()->create('d1.jpg', 10))],
    'uebernimmVorhandenesFotoFuerStep' => [fn ($svc, $team, $id) => $svc->uebernimmVorhandenesFotoFuerStep($team, $id, 1)],
]);

// D1-Slice 3 (Etappe 8, Multi-Tenancy »Writes isOwnedBy konsequent«): die RUN-Ebene-Bulk-Methoden
// (runId statt stepId) dürfen einen SICHTBAREN, aber nicht besessenen Lauf (childA sieht den vererbten
// Root-Lauf lesend) nicht anfassen — sauberer No-op statt lautem Wurf im ersten per-Step-ownedStep.
// Bei reapeVerwaisteSteps ist das KEINE Kosmetik, sondern ein echter Cross-Tenant-Write-Riegel:
// markStepFailed(stepId) trägt (anders als regeneriereStep/gibStepFrei) KEINEN ownedStep-Guard →
// ohne die Run-Ownership-Prüfung könnte childA die verwaisten Root-Steps auf `failed` setzen.
it('D1-Slice 3: geerbter (sichtbarer, nicht besessener) Lauf ist für jede Run-Bulk-Methode ein No-op', function (Closure $call) {
    $recipe = $this->makeRecipe($this->rootTeam, 'D1-Run-Bulk', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    // Je ein Step in einem Zustand, den IRGENDEINE der Methoden anfassen WÜRDE (done→Freigabe,
    // failed→Verwerfen/Resume, alt-running→Reap): so würde ein fehlender Guard sichtbar durchschlagen.
    $done = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);
    $failed = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'failed', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);
    $verwaist = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    FoodAlchemistCascadeRunStep::where('id', $verwaist->id)->update(['updated_at' => now()->subMinutes(45)]);   // verwaist (>30 Min)
    $svc = app(PlanningCascadeService::class);

    // childA erbt den Root-Lauf lesend (sichtbar), besitzt ihn aber NICHT → jede Bulk-Methode verpufft.
    $call($svc, $this->childA, (int) $run->id);

    expect($done->refresh()->status)->toBe('done')           // keine Freigabe
        ->and($failed->refresh()->status)->toBe('failed')     // kein Verwerfen/Resume
        ->and($verwaist->refresh()->status)->toBe('running')  // KEIN Reap-Write (Leak geschlossen)
        ->and($recipe->refresh()->status->value)->toBe('draft');
    Queue::assertNothingPushed();
})->with([
    'gibStufeFrei'        => [fn ($svc, $team, $id) => $svc->gibStufeFrei($team, $id, 'gericht')],
    'gibRunFrei'          => [fn ($svc, $team, $id) => $svc->gibRunFrei($team, $id)],
    'verwirfRun'          => [fn ($svc, $team, $id) => $svc->verwirfRun($team, $id)],
    'setzeLaufFort'       => [fn ($svc, $team, $id) => $svc->setzeLaufFort($team, $id)],
    'reapeVerwaisteSteps' => [fn ($svc, $team, $id) => $svc->reapeVerwaisteSteps($team, $id)],
]);

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

it('Fan-out-Cap: über 30 leere Slots → KI wird nur nach 30 Ideen gefragt + gedeckelt_slots_offen vermerkt', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Riesen-Buffet', ['status' => 'draft']);
    foreach (range(1, 33) as $pos) {                       // 33 leere Slots → 3 über dem Deckel (30)
        $this->makeConceptSlot($concept, ['position' => $pos]);
    }
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running', 'params' => ['ziel_vk_eur' => 12.5]]);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $concept->id]);

    $mkIdee = fn (string $t) => FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $concept->id, 'title' => $t, 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'entwurf', 'position' => 1, 'created_via' => 'test']);
    $this->mock(IdeenService::class, fn ($m) => $m->shouldReceive('kiDivergenzConcept')
        ->once()
        ->withArgs(fn ($team, $conceptId, $anzahl, $extra = null, $trend = null) => $anzahl === 30)   // gedeckelt, nicht 33
        ->andReturn(['angelegt' => [$mkIdee('A'), $mkIdee('B')], 'roh' => 2, 'confidence' => 0.8, 'call_log_id' => null]));

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $concept->id, 'voll_kreativ');

    expect((int) ($run->refresh()->params['gedeckelt_slots_offen'] ?? 0))->toBe(3)   // kein stiller Deckel
        ->and($run->params['ziel_vk_eur'] ?? null)->toBe(12.5);                       // Bestands-Params bleiben (merge)
});

it('Fan-out-Cap: bis 30 leere Slots kein Deckel-Vermerk (Bestandsverhalten)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Buffet', ['status' => 'draft']);
    $this->makeConceptSlot($concept, ['position' => 1]);
    $this->makeConceptSlot($concept, ['position' => 2]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $concept->id]);

    $this->mock(IdeenService::class, fn ($m) => $m->shouldReceive('kiDivergenzConcept')
        ->once()
        ->withArgs(fn ($team, $conceptId, $anzahl, $extra = null, $trend = null) => $anzahl === 2)   // exakt die 2 Slots
        ->andReturn(['angelegt' => [], 'roh' => 0, 'confidence' => 0.0, 'call_log_id' => null]));

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $concept->id, 'voll_kreativ');

    expect($run->refresh()->params['gedeckelt_slots_offen'] ?? null)->toBeNull();
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

// ── Et.6 (Roadmap Z.205): Zielpreis-Korridor Concept → Gericht (aus Frame) ──
// Ein erfundenes Concept-Gericht bekommt einen Ziel-VK aus dem Concept-Frame — bevorzugt der
// per-Gericht-Preis-Anker des Frame-Slots (via Rolle=Label), sonst der Kopf-Zielpreis je Person
// gleichmäßig auf die Positionen verteilt. Nachweis über den erfassten Rezept-Param `ziel_vk_eur`.

it('Zielpreis-Frame: Frame-Slot-Preis-Anker (per Gericht) landet als ziel_vk_eur am erfundenen Gericht', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['status' => 'draft']);
    $slot = $this->makeConceptSlot($concept, ['position' => 1, 'role' => 'Hauptgang']);
    $frameSvc = app(PlanningFrameService::class);
    $frame = $frameSvc->frameFor($this->rootTeam, 'concept', (int) $concept->id);
    $frameSvc->setHead($this->rootTeam, $frame, ['target_price_pp' => 90.0]);   // Kopf da, aber Anker gewinnt
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 1, 'price_anchor' => 12.0]);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1, 'price_anchor' => 28.5]);

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

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idee->id, (int) $step->id);

    // Anker des Hauptgang-Slots (28,50) — nicht der Kopf-Zielpreis, nicht der Vorspeisen-Anker.
    expect($captured['ziel_vk_eur'] ?? null)->toBe(28.5);
});

it('Zielpreis-Frame: ohne Slot-Anker fällt der Ziel-VK auf den Kopf-Zielpreis je Person / Positionen zurück', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['status' => 'draft']);
    $slot = $this->makeConceptSlot($concept, ['position' => 1, 'role' => 'Hauptgang']);
    $frameSvc = app(PlanningFrameService::class);
    $frame = $frameSvc->frameFor($this->rootTeam, 'concept', (int) $concept->id);
    $frameSvc->setHead($this->rootTeam, $frame, ['target_price_pp' => 60.0]);
    // 3 Positionen, KEIN Anker → Gleichverteilung 60 / 3 = 20.
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeise', 'slot_type' => 'gang', 'target_count' => 1]);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Dessert', 'slot_type' => 'gang', 'target_count' => 1]);

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

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idee->id, (int) $step->id);

    expect($captured['ziel_vk_eur'] ?? null)->toBe(20.0);
});

it('Zielpreis-Frame: ohne Frame / ohne Preis-Angabe bleibt ziel_vk_eur ungesetzt (Bestandsverhalten)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['status' => 'draft']);
    $slot = $this->makeConceptSlot($concept, ['position' => 1, 'role' => 'Hauptgang']);
    // KEIN Frame angelegt.
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

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idee->id, (int) $step->id);

    expect(array_key_exists('ziel_vk_eur', $captured))->toBeFalse();
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
        ->and((bool) $run->staged)->toBeFalse()   // Ausgabe-Voll-Kaskade = eager (Sammel-Review), nicht gestuft
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
        ->and((bool) $run->staged)->toBeFalse()   // eager (Sammel-Review)
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
        ->and((bool) $run->staged)->toBeFalse()   // eager (Sammel-Review), wie Foodbook/Speisekarte
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

it('bewusste Unterscheidung: Cockpit-Scope gestuft (staged=true) vs. Ausgabe-Voll-Kaskade eager (staged=false)', function () {
    // Dokumentiert die Etappe-5-Entscheidung an EINER Stelle: die Cockpit-Ebenen (rezept|gericht|concept)
    // laufen gestuft — Gate + Freigabe je Ebene; die aus den Ausgabe-Modulen getriggerte Voll-Kaskade
    // (foodbook/speisekarte/speiseplan) läuft eager (Sammel-Review). Der Wert wird explizit gesetzt,
    // nicht dem DB-Default überlassen (Schutz gegen ein Ändern des Defaults).
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $svc = app(PlanningCascadeService::class);

    $cockpit = $svc->starteKaskade($this->rootTeam, 'gericht', $session, 'voll_kreativ');

    $fb = $this->makeFoodbook($this->rootTeam, 'Ausgabe-Foodbook', ['status' => 'draft']);
    $frameSvc = app(PlanningFrameService::class);
    $frame = $frameSvc->frameFor($this->rootTeam, 'foodbook', (int) $fb->id);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeisen', 'slot_type' => 'kapitel', 'target_count' => 1]);
    $ausgabe = $svc->starteKaskade($this->rootTeam, 'vollkaskade', null, 'voll_kreativ', ['owner_type' => 'foodbook', 'owner_id' => (int) $fb->id]);

    expect((bool) $cockpit->staged)->toBeTrue()
        ->and((bool) $ausgabe->staged)->toBeFalse();
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

// ── L1 — Reuse-Achse (Kreativ-Modus → bestand) ───────────────────────────────────────────────

it('L1 Reuse-Gate (hybrid): existierendes Basisrezept wird gebunden statt neu erzeugt (kein Job, skipped-Sichtzeile)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht mit Fond', ['status' => 'draft']);
    $zutat = $this->makeIngredient($gericht, 'Geflügelfond');
    // Bestand: dasselbe Basisrezept existiert schon.
    $bestand = $this->makeRecipe($this->rootTeam, 'Geflügelfond');

    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, $step->id, auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Geflügelfond', 'primaer' => 'basisrezept_anlegen']],
        ['auto_dependencies' => true, 'bestand' => 'hybrid'],
    );

    // Gebunden an den Bestand, KEIN Erzeugungs-Job, Reuse-Sichtzeile (skipped) statt geplant.
    expect($zutat->refresh()->referenced_recipe_id)->toBe($bestand->id);
    Queue::assertNotPushed(GenerateRecipeJob::class);
    $kinder = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('depth', 1)->get();
    expect($kinder)->toHaveCount(1)
        ->and($kinder->first()->status)->toBe('skipped')
        ->and((int) $kinder->first()->ref_id)->toBe($bestand->id);
});

it('L1 nur_bestand ohne Treffer: KEIN neues Rezept, Zeile bleibt offen (Hard-Stop)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht ohne Bestand', ['status' => 'draft']);
    $zutat = $this->makeIngredient($gericht, 'Exotische Spezialpaste');

    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, $step->id, auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Exotische Spezialpaste', 'primaer' => 'basisrezept_anlegen']],
        ['auto_dependencies' => true, 'bestand' => 'nur_bestand'],
    );

    Queue::assertNotPushed(GenerateRecipeJob::class);
    expect(FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('depth', 1)->count())->toBe(0)
        ->and($zutat->refresh()->referenced_recipe_id)->toBeNull();   // offen, kein Neu-Rezept
});

it('L1 komplett_neu: Reuse-Gate übersprungen — trotz Bestand wird neu geplant/erzeugt', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht komplett neu', ['status' => 'draft']);
    $this->makeIngredient($gericht, 'Geflügelfond');
    $this->makeRecipe($this->rootTeam, 'Geflügelfond');   // Bestand existiert — wird bewusst ignoriert

    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, $step->id, auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Geflügelfond', 'primaer' => 'basisrezept_anlegen']],
        ['auto_dependencies' => true, 'bestand' => 'komplett_neu'],
    );

    // Neu erzeugt (Job) statt Bestand gebunden.
    Queue::assertPushed(GenerateRecipeJob::class, 1);
    $kinder = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('depth', 1)->get();
    expect($kinder)->toHaveCount(1)
        ->and($kinder->first()->status)->toBe('running');
});

it('L1 goKaskade: Kreativ-Modus datenbank leitet bestand=nur_bestand ab (in den Job-Params)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'DB-Modus', 'brief' => 'x']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.gericht.brief', 'Ein Gericht.')
        ->set('eingabe.gericht.creative_mode', 'datenbank')
        ->call('goKaskade', 'gericht')
        ->assertSet('laeuft', true);

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => ($job->parameter['bestand'] ?? null) === 'nur_bestand');
    expect($session->refresh()->generation_params['bestand'] ?? null)->toBe('nur_bestand');
});

// ── L4 — Kaskaden-Nahtstellen ────────────────────────────────────────────────────────────────

it('L4.3 recomputeRunStatus: freigegeben + failed → review (kein „done", das luegt)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running', 'staged' => true]);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'freigegeben', 'sort' => 1]);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'failed', 'sort' => 2]);

    app(PlanningCascadeService::class)->recomputeRunStatus((int) $run->id);

    expect($run->refresh()->status)->toBe('review');   // nicht 'done'
});

it('L4.3 recomputeRunStatus: nur freigegeben/skipped (kein failed) → done', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'freigegeben', 'sort' => 1]);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'skipped', 'sort' => 2]);

    app(PlanningCascadeService::class)->recomputeRunStatus((int) $run->id);

    expect($run->refresh()->status)->toBe('done');
});

it('L4.2 ergaenzeManuellenSubStep bindet zurück: Eltern-Zutat + Dependency entstehen', function () {
    $this->unitG($this->rootTeam);   // 'g'-Einheit muss vorhanden sein (Prod: pro Team geseedet)
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht mit manuellem Sub', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true]);
    $root = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht',
        'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $gericht->id, 'sort' => 1,
    ]);

    $step = app(PlanningCascadeService::class)->ergaenzeManuellenSubStep($this->rootTeam, (int) $run->id, 'Schweine Jus');

    expect($step)->not->toBeNull()
        ->and($step->kind)->toBe('rezept')
        ->and($step->status)->toBe('geplant');
    // Eltern-Zutatenzeile am Gericht + Dependency auf den neuen Kind-Step.
    $zutat = $gericht->refresh()->ingredients()->where('raw_text', 'Schweine Jus')->first();
    expect($zutat)->not->toBeNull();
    $dep = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency::where('child_step_id', $step->id)->first();
    expect($dep)->not->toBeNull()
        ->and((int) $dep->ingredient_id)->toBe((int) $zutat->id);

    // Wird das Kind erzeugt+gebunden, zeigt die Eltern-Zutat darauf (Rückbindung schließt sich).
    $sub = $this->makeRecipe($this->rootTeam, 'Schweine Jus');
    app(\Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService::class)
        ->afterGenerated($this->rootTeam, (int) $step->id, auth()->id(), $sub, [], []);
    expect((int) $zutat->refresh()->referenced_recipe_id)->toBe((int) $sub->id);
});

it('L4.4 regeneriereStep (Kind): Eltern-Zutat wird vor dem Loeschen entbunden (keine tote Referenz)', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht Regen', ['status' => 'draft']);
    $altSub = $this->makeRecipe($this->rootTeam, 'Alte Jus', ['status' => 'draft']);
    $zutat = $this->makeIngredient($gericht, 'Jus');
    $zutat->update(['referenced_recipe_id' => $altSub->id, 'match_method' => 'recipe_ref']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true]);
    $gerichtStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $gericht->id, 'sort' => 1]);
    $kindStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $gerichtStep->id, 'kind' => 'rezept', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $altSub->id, 'depth' => 1, 'sort' => 2, 'label' => 'Jus']);
    \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $gerichtStep->id,
        'ingredient_id' => $zutat->id, 'child_step_id' => $kindStep->id,
    ]);

    app(PlanningCascadeService::class)->regeneriereStep($this->rootTeam, (int) $kindStep->id);

    // Alter Sub geloescht, Eltern-Zutat entbunden (nicht mehr auf das geloeschte Rezept).
    expect(FoodAlchemistRecipe::where('team_id', $this->rootTeam->id)->whereKey($altSub->id)->exists())->toBeFalse();
    expect($zutat->refresh()->referenced_recipe_id)->toBeNull();
});

it('L5 markStepDone zieht das Step-Label auf den echten Artefakt-Namen (nicht der Briefing-Text)', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Rotwein-Reduktion', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht',
        'status' => 'running', 'label' => 'Ein langer Briefing-Text der eigentlich kein Name ist', 'sort' => 1,
    ]);

    app(PlanningCascadeService::class)->markStepDone((int) $step->id, 'recipe', (int) $rezept->id);

    expect($step->refresh()->label)->toBe('Rotwein-Reduktion');
});
