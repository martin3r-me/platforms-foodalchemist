<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\FanoutConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateDishProposalJob;
use Platform\FoodAlchemist\Jobs\MaterializeSpeisekartePositionJob;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Jobs\ReviseDishProposalJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 / Paket C-Nachtrag — Phasen fehlten bisher für alle Kaskaden-Jobs außer GenerateRecipeJob:
 * Speisekarten-/Speiseplan-Gerichte, Concept-Steps und Gericht-Bauplan-Vorschläge zeigten nur
 * „running | -" bis done. Dieselbe setzePhase()-Kette wie GenerateRecipeJobPhaseTest, hier für die
 * übrigen Jobs/Service-Methoden.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    Queue::fake();
});

it('GenerateConceptJob setzt "Gerüst wird geplant …" waehrend der Generierung, geloescht nach markStepDone', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'sort' => 1]);
    $concept = $this->makeConcept($this->rootTeam, 'Reuse-Konzept', ['status' => 'draft']);

    $phaseMid = null;
    $this->mock(ConceptGeneratorService::class, function ($m) use (&$phaseMid, $step, $concept) {
        $m->shouldReceive('generiereAusBrief')->once()->andReturnUsing(function () use (&$phaseMid, $step, $concept) {
            $phaseMid = $step->refresh()->phase;

            return ['concept' => $concept, 'coverage' => null];
        });
    });

    // creativeMode='datenbank' (Reuse) -- kein Fan-out-Zweig, haelt den Test auf die Gerüst-Phase fokussiert.
    (new GenerateConceptJob('run-concept-1', $this->rootTeam->id, (int) $this->user->id, 'brief', null, null, (int) $step->id, 'datenbank'))
        ->handle(app(ConceptGeneratorService::class));

    expect($phaseMid)->toBe('Gerüst wird geplant …')
        ->and($step->refresh()->phase)->toBeNull()
        ->and($step->refresh()->status)->toBe('done');
});

it('GenerateConceptJob (inline, ungestuft, voll_kreativ) setzt "Skizzen werden erfunden …" vor dem Fan-out', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running', 'staged' => false]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'sort' => 1]);
    $concept = $this->makeConcept($this->rootTeam, 'Inline-Fanout-Konzept', ['status' => 'draft']);

    $this->mock(ConceptGeneratorService::class, fn ($m) => $m->shouldReceive('planAusBrief')->once()->andReturn(['concept' => $concept, 'coverage' => null]));

    $phaseWaehrendFanout = null;
    $this->partialMock(PlanningCascadeService::class, function ($m) use (&$phaseWaehrendFanout, $step) {
        $m->shouldReceive('fanoutConceptInvention')->once()->andReturnUsing(function () use (&$phaseWaehrendFanout, $step) {
            $phaseWaehrendFanout = $step->refresh()->phase;
        });
    });

    (new GenerateConceptJob('run-concept-2', $this->rootTeam->id, (int) $this->user->id, 'brief', null, null, (int) $step->id, 'voll_kreativ'))
        ->handle(app(ConceptGeneratorService::class));

    expect($phaseWaehrendFanout)->toBe('Skizzen werden erfunden …')
        ->and($step->refresh()->phase)->toBeNull();
});

it('FanoutConceptJob (gestufter Fan-out nach Freigabe) setzt die Phase waehrend fanoutConceptInvention, loescht sie im finally', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $concept = $this->makeConcept($this->rootTeam, 'Gestufter Fanout', ['status' => 'active']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'freigegeben',
        'ref_type' => 'concept', 'ref_id' => $concept->id,
        'deferred' => ['fanout' => ['mode' => 'voll_kreativ', 'trend_doc_id' => null, 'planning_session_id' => null]],
    ]);

    $phaseWaehrend = null;
    $this->partialMock(PlanningCascadeService::class, function ($m) use (&$phaseWaehrend, $step) {
        $m->shouldReceive('fanoutConceptInvention')->once()->andReturnUsing(function () use (&$phaseWaehrend, $step) {
            $phaseWaehrend = $step->refresh()->phase;
        });
    });

    (new FanoutConceptJob($this->rootTeam->id, (int) $this->user->id, (int) $step->id))->handle(app(PlanningCascadeService::class));

    expect($phaseWaehrend)->toBe('Skizzen werden erfunden …')
        ->and($step->refresh()->phase)->toBeNull()
        ->and($step->refresh()->status)->toBe('freigegeben');   // Concept bleibt live, nur die Phase ist weg
});

it('FanoutConceptJob::failed loescht eine haengengebliebene Phase (harter Job-Tod, Timeout/OOM)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $concept = $this->makeConcept($this->rootTeam, 'Timeout-Fanout', ['status' => 'active']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'freigegeben',
        'ref_type' => 'concept', 'ref_id' => $concept->id, 'phase' => 'Skizzen werden erfunden …', 'phase_at' => now(),
    ]);

    (new FanoutConceptJob($this->rootTeam->id, (int) $this->user->id, (int) $step->id))->failed(new \RuntimeException('Timeout'));

    expect($step->refresh()->phase)->toBeNull()
        ->and($step->refresh()->status)->toBe('freigegeben')
        ->and($step->refresh()->deferred['fanout_error'] ?? null)->toContain('Timeout');
});

it('FanoutConceptJob bricht bei fehlendem User hart ab (Konsistenz mit den Geschwister-Jobs)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $concept = $this->makeConcept($this->rootTeam, 'Kein-User-Fanout', ['status' => 'active']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'freigegeben',
        'ref_type' => 'concept', 'ref_id' => $concept->id,
        'deferred' => ['fanout' => ['mode' => 'voll_kreativ']],
    ]);

    (new FanoutConceptJob($this->rootTeam->id, 999999, (int) $step->id))->handle(app(PlanningCascadeService::class));

    expect($step->refresh()->deferred['fanout_error'] ?? null)->toContain('User nicht gefunden')
        ->and($step->refresh()->status)->toBe('freigegeben');
});

it('materialisiereSpeisekartePosition setzt die generiere()-Stufen als Phase, geloescht nach markStepDone', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'vollkaskade', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $recipe = $this->makeRecipe($this->rootTeam, 'Speisekarten-Position', ['is_sales_recipe' => true, 'status' => 'draft']);

    $this->mock(RecipeDependencyWorkflowService::class, function ($m) {
        $m->shouldReceive('prepare')->andReturn(['snapshot' => []]);
        $m->shouldReceive('afterGenerated')->andReturn(null);
    });
    $phaseMid = null;
    $this->mock(RecipeGeneratorService::class, function ($m) use (&$phaseMid, $step, $recipe) {
        $m->shouldReceive('generiere')->once()->andReturnUsing(function (...$args) use (&$phaseMid, $step, $recipe) {
            $cb = $args[7] ?? null;
            if (is_callable($cb)) {
                $cb('KI schreibt das Gericht …');
                $phaseMid = $step->refresh()->phase;
            }

            return ['recipe' => $recipe, 'offene' => []];
        });
    });
    $this->mock(\Platform\FoodAlchemist\Services\SpeisekarteService::class, fn ($m) => $m->shouldReceive('addPosition')->once());

    (new MaterializeSpeisekartePositionJob($this->rootTeam->id, (int) $this->user->id, 1, 'Brief', (int) $step->id))
        ->handle();

    expect($phaseMid)->toBe('KI schreibt das Gericht …')
        ->and($step->refresh()->phase)->toBeNull()
        ->and($step->refresh()->status)->toBe('done');
});

it('materialisiereSpeiseplanZelle setzt die generiere()-Stufen als Phase, geloescht nach markStepDone', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'vollkaskade', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $recipe = $this->makeRecipe($this->rootTeam, 'Speiseplan-Zelle', ['is_sales_recipe' => true, 'status' => 'draft']);

    $this->mock(RecipeDependencyWorkflowService::class, function ($m) {
        $m->shouldReceive('prepare')->andReturn(['snapshot' => []]);
        $m->shouldReceive('afterGenerated')->andReturn(null);
    });
    $phaseMid = null;
    $this->mock(RecipeGeneratorService::class, function ($m) use (&$phaseMid, $step, $recipe) {
        $m->shouldReceive('generiere')->once()->andReturnUsing(function (...$args) use (&$phaseMid, $step, $recipe) {
            $cb = $args[7] ?? null;
            if (is_callable($cb)) {
                $cb('Zutaten werden zugeordnet …');
                $phaseMid = $step->refresh()->phase;
            }

            return ['recipe' => $recipe, 'offene' => []];
        });
    });
    $this->mock(\Platform\FoodAlchemist\Services\SpeiseplanService::class, fn ($m) => $m->shouldReceive('addEintrag')->once());

    (new MaterializeSpeiseplanCellJob($this->rootTeam->id, (int) $this->user->id, 1, '2026-09-20', 'mittag', 1, 'Brief', (int) $step->id))
        ->handle();

    expect($phaseMid)->toBe('Zutaten werden zugeordnet …')
        ->and($step->refresh()->phase)->toBeNull()
        ->and($step->refresh()->status)->toBe('done');
});

it('GenerateDishProposalJob setzt "Gerichtsvorschlag wird erfunden …", geloescht nach Erfolg', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $idee = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'title' => 'Vorschlag', 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'entwurf', 'position' => 1, 'created_via' => 'test']);

    $phaseMid = null;
    $this->mock(IdeenService::class, function ($m) use (&$phaseMid, $step, $idee) {
        $m->shouldReceive('kiDivergenzSession')->once()->andReturnUsing(function () use (&$phaseMid, $step, $idee) {
            $phaseMid = $step->refresh()->phase;

            return ['angelegt' => [$idee]];
        });
    });

    (new GenerateDishProposalJob($this->rootTeam->id, (int) $this->user->id, 1, (int) $step->id, 'Brief', 'voll_kreativ'))
        ->handle(app(IdeenService::class), app(PlanningCascadeService::class));

    expect($phaseMid)->toBe('Gerichtsvorschlag wird erfunden …')
        ->and($step->refresh()->phase)->toBeNull()
        ->and($step->refresh()->status)->toBe('geplant');
});

it('GenerateDishProposalJob::failed loescht eine haengengebliebene Phase (harter Job-Tod)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'phase' => 'Gerichtsvorschlag wird erfunden …', 'phase_at' => now(), 'sort' => 1]);

    (new GenerateDishProposalJob($this->rootTeam->id, (int) $this->user->id, 1, (int) $step->id, 'Brief', 'voll_kreativ'))
        ->failed(new \RuntimeException('Timeout'));

    expect($step->refresh()->phase)->toBeNull()
        ->and($step->refresh()->status)->toBe('failed');
});

it('materialisiereConceptGericht setzt die generiere()-Stufen als Phase (haeufigster Gericht-Pfad: erfundenes Concept-/Foodbook-Gericht)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $idee = FoodAlchemistDishIdea::create([
        'team_id' => $this->rootTeam->id, 'title' => 'Erfundenes Gericht', 'status' => 'entwurf', 'target_form' => 'einzel',
        'generation_status' => 'queued', 'position' => 1, 'created_via' => 'test',
        'source_meta' => ['target_concept_slot_id' => 0],
    ]);
    $recipe = $this->makeRecipe($this->rootTeam, 'Erfundenes-Gericht-Rezept', ['is_sales_recipe' => true, 'status' => 'draft']);

    $this->mock(RecipeDependencyWorkflowService::class, function ($m) {
        $m->shouldReceive('prepare')->andReturn(['snapshot' => []]);
        $m->shouldReceive('afterGenerated')->andReturn(null);
    });
    $phaseMid = null;
    $this->mock(RecipeGeneratorService::class, function ($m) use (&$phaseMid, $step, $recipe) {
        $m->shouldReceive('generiere')->once()->andReturnUsing(function (...$args) use (&$phaseMid, $step, $recipe) {
            $cb = $args[7] ?? null;
            if (is_callable($cb)) {
                $cb('KI schreibt das Rezept …');
                $phaseMid = $step->refresh()->phase;
            }

            return ['recipe' => $recipe, 'offene' => []];
        });
    });

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idee->id, (int) $step->id);

    expect($phaseMid)->toBe('KI schreibt das Rezept …')
        ->and($step->refresh()->phase)->toBeNull()
        ->and($step->refresh()->status)->toBe('done');
});

it('ReviseDishProposalJob setzt "Gerichtsvorschlag wird überarbeitet …" waehrend propose(), geloescht danach', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $idee = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'title' => 'Alt', 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'entwurf', 'position' => 1, 'created_via' => 'test']);

    $phaseMid = null;
    $this->mock(AiGatewayService::class, function ($m) use (&$phaseMid, $step) {
        $m->shouldReceive('propose')->once()->andReturnUsing(function () use (&$phaseMid, $step) {
            $phaseMid = $step->refresh()->phase;

            return new AiProposal(['titel' => 'Neu', 'beschreibung' => 'Ueberarbeitet', 'komponenten' => []], 0.9);
        });
    });

    (new ReviseDishProposalJob($this->rootTeam->id, (int) $this->user->id, (int) $step->id, (int) $idee->id, 'Feedback'))
        ->handle(app(AiGatewayService::class), app(PlanningCascadeService::class));

    expect($phaseMid)->toBe('Gerichtsvorschlag wird überarbeitet …')
        ->and($step->refresh()->phase)->toBeNull()
        ->and($step->refresh()->status)->toBe('geplant')
        ->and($idee->refresh()->title)->toBe('Neu');
});

it('ReviseDishProposalJob::failed setzt den Step zurueck auf geplant und loescht die Phase (harter Job-Tod)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'phase' => 'Gerichtsvorschlag wird überarbeitet …', 'phase_at' => now(), 'sort' => 1]);
    $idee = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'title' => 'Alt', 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'entwurf', 'position' => 1, 'created_via' => 'test']);

    (new ReviseDishProposalJob($this->rootTeam->id, (int) $this->user->id, (int) $step->id, (int) $idee->id, 'Feedback'))
        ->failed(new \RuntimeException('Timeout'));

    expect($step->refresh()->phase)->toBeNull()
        ->and($step->refresh()->status)->toBe('geplant')
        ->and($step->refresh()->error)->toContain('Timeout');
});

it('laufStatus() zeigt fuer queued/running-Steps ohne gesetzte Phase denselben "wartet auf Worker"-Text wie das Cockpit', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running']);
    $wartend = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'queued', 'sort' => 1]);
    $laufend = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'running', 'phase' => 'Rezept wird entworfen …', 'phase_at' => now(), 'sort' => 2]);
    $fertig = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done', 'sort' => 3]);

    $status = app(PlanningCascadeService::class)->laufStatus($this->rootTeam, (int) $run->id);
    $schritte = collect($status['schritte'])->keyBy('id');

    expect($schritte[$wartend->id]['phase'] ?? null)->toBe('eingereiht — wartet auf Worker')
        ->and($schritte[$laufend->id]['phase'] ?? null)->toBe('Rezept wird entworfen …')
        ->and($schritte[$fertig->id]['phase'] ?? null)->toBeNull();
});
