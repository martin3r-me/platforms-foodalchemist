<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 / Paket C — GenerateRecipeJob::fortschritt() spiegelt die Cache-Phase (Rezept-Modal, alt)
 * zusätzlich an den Kaskaden-Step (Cockpit + MCP, neu). Beweisziel: EIN Callback, ZWEI Schreiber —
 * der Cache-Vertrag (progress/status) bleibt byte-identisch, der Step trägt dieselbe Phase.
 *
 * RecipeDependencyWorkflowService wird gemockt (prepare/afterGenerated no-op) — sonst würde der reale
 * Kontext-Bau mitlaufen, den dieser Test nicht prüfen will; ConformanceCheckJob-Dispatch wird per
 * Queue::fake() abgefangen (Schicht-3-Critic ist nicht Gegenstand dieses Tests).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    Queue::fake();
});

it('fortschritt() setzt die Phase am Kaskaden-Step UND schreibt weiterhin den Cache-progress', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'running', 'sort' => 1]);

    $runId = 'p53-c-' . uniqid();
    $teamId = $this->rootTeam->id;
    $userId = $this->user->id;

    // Phase + Cache-progress MID-FLIGHT abgreifen (im Callback) — beide werden danach wieder
    // überschrieben: markStepDone() (via meldeKaskade) nullt die Phase, sobald der Step `done` ist
    // (Terminal-Status ersetzt die Zwischen-Phase), schreibe() überschreibt den Cache-Key mit dem
    // fertigen Ergebnis (kein progress-Key mehr). Der Vertrag gilt nur WÄHREND der Generierung.
    // An $this gehängt statt per use(&$var) — zuverlässiger über die verschachtelten Mockery-Closures.
    $this->progressMid = null;
    $this->phaseMid = null;
    $this->mock(RecipeDependencyWorkflowService::class, function ($m) {
        $m->shouldReceive('prepare')->andReturn(['snapshot' => []]);
        $m->shouldReceive('afterGenerated')->andReturn(null);
    });
    $this->mock(RecipeGeneratorService::class, function ($m) use ($teamId, $runId, $step) {
        $m->shouldReceive('generiere')->once()->andReturnUsing(function (...$args) use ($teamId, $runId, $step) {
            $cb = $args[7] ?? null;
            if (is_callable($cb)) {
                $cb('Rezept wird entworfen …');
                $this->progressMid = Cache::get(GenerateRecipeJob::cacheKey($runId));
                $this->phaseMid = $step->refresh()->phase;
            }

            return [
                'recipe' => FoodAlchemistRecipe::create(['team_id' => $teamId, 'recipe_key' => $runId, 'name' => 'X', 'status' => 'draft']),
                'statistik' => ['bestand_gp' => 0, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0],
                'offene' => [],
            ];
        });
    });

    (new GenerateRecipeJob($runId, $teamId, $userId, 'desc', ['cascade_step_id' => $step->id], false, false))
        ->handle(app(RecipeGeneratorService::class));

    expect($this->phaseMid)->toBe('Rezept wird entworfen …')
        ->and($this->progressMid['progress'] ?? null)->toBe('Rezept wird entworfen …')
        // markStepDone (meldeKaskade) nullt die Phase, sobald der Step done ist — kein Zombie-Text.
        ->and($step->refresh()->phase)->toBeNull();
});

it('fortschritt() ohne cascade_step_id bleibt byte-identisch zum Bestandspfad (kein Step-Schreiber)', function () {
    $runId = 'p53-c-ohne-' . uniqid();
    $teamId = $this->rootTeam->id;
    $userId = $this->user->id;
    $progressMid = null;

    $this->mock(RecipeGeneratorService::class, function ($m) use ($teamId, $runId, &$progressMid) {
        $m->shouldReceive('generiere')->once()->andReturnUsing(function (...$args) use ($teamId, $runId, &$progressMid) {
            $cb = $args[7] ?? null;
            if (is_callable($cb)) {
                $cb('Rezept wird entworfen …');
                $progressMid = Cache::get(GenerateRecipeJob::cacheKey($runId));
            }

            return [
                'recipe' => FoodAlchemistRecipe::create(['team_id' => $teamId, 'recipe_key' => $runId, 'name' => 'X', 'status' => 'draft']),
                'statistik' => ['bestand_gp' => 0, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0],
                'offene' => [],
            ];
        });
    });

    (new GenerateRecipeJob($runId, $teamId, $userId, 'desc', [], false, false))
        ->handle(app(RecipeGeneratorService::class));

    expect($progressMid['progress'] ?? null)->toBe('Rezept wird entworfen …');
});
