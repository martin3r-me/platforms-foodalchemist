<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Planung-Leitstelle: die KI-Erstellung (Regler-Leitplanken) lebt konsolidiert in der Planung.
 * Beweisziele:
 *  1. Die Regler des Cockpit-Go werden als Lauf-`params` gereicht UND als `generation_params`
 *     der Session persistiert (whitelist-gefiltert) → Fan-out kann sie erben.
 *  2. Der Kaskaden-Fan-out (materialisiereConceptGericht) reicht die Session-Regler an `generiere`.
 *  3. Freie 1-Klick-Erstellung legt eine `cockpit_frei`-Session an (de-trend) und öffnet den Editor.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
});

it('setGenerationParams: filtert auf die Whitelist und macht leere Auswahl zu null', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X']);
    $svc = app(PlanningSessionService::class);

    $svc->setGenerationParams($this->rootTeam, (int) $session->id, [
        'level' => 'gehoben', 'bio' => true,
        'aroma' => '',                 // leerer String → raus
        'diaet_hart' => [],            // leeres Array → raus
        'unbekannt' => 'boese',        // nicht in der Whitelist → raus
    ]);
    expect($session->refresh()->generation_params)->toBe(['level' => 'gehoben', 'bio' => true]);

    // Nur Leerwerte/Fremdkeys → null (kein leeres {} persistieren)
    $svc->setGenerationParams($this->rootTeam, (int) $session->id, ['aroma' => '', 'unbekannt' => 'x']);
    expect($session->refresh()->generation_params)->toBeNull();
});

it('Leitstelle: goKaskade reicht die Regler als params UND persistiert sie als generation_params', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Rotwein-Reduktion', 'brief' => 'Dunkle Reduktion.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('regler.level', 'gehoben')
        ->set('regler.convenience', 'from_scratch')
        ->set('regler.bio_praeferenz', 'bio')      // → bio-Bool true
        ->call('goKaskade', 'rezept')
        ->assertSet('laeuft', true)
        ->assertNoRedirect();

    // Leitplanken an der Session (für den Fan-out) — bio-Bool aus der dreiwertigen Präferenz.
    expect($session->refresh()->generation_params)->toMatchArray([
        'level' => 'gehoben', 'convenience' => 'from_scratch', 'bio' => true,
    ]);

    // Der Depth-1-Job trägt die Regler im parameter (nicht mehr leer).
    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->vkModus === false
        && ($job->parameter['level'] ?? null) === 'gehoben'
        && ($job->parameter['convenience'] ?? null) === 'from_scratch'
        && ($job->parameter['bio'] ?? null) === true);
});

it('Fan-out erbt die Regler: materialisiereConceptGericht reicht generation_params an generiere', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer', 'brief' => 'x']);
    app(PlanningSessionService::class)->setGenerationParams($this->rootTeam, (int) $session->id, [
        'level' => 'gehoben', 'bio' => true, 'convenience' => 'from_scratch',
    ]);

    $idea = FoodAlchemistDishIdea::create([
        'team_id' => $this->rootTeam->id, 'title' => 'Erfundenes Gericht',
        'generation_status' => 'queued', 'status' => 'offen',
        'source_meta' => ['target_concept_slot_id' => 0],   // 0 → fillSlot wird übersprungen
    ]);
    $recipe = $this->makeRecipe($this->rootTeam, 'Erfundenes Gericht', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    // Workflow neutralisieren (Grounding/Vererbung sind hier nicht das Testziel).
    $this->mock(RecipeDependencyWorkflowService::class, function ($m) {
        $m->shouldReceive('prepare')->andReturn([]);
        $m->shouldReceive('afterGenerated')->andReturnNull();
    });
    // generiere: die übergebenen $params (3. Arg) einfangen, echtes Rezept zurückgeben.
    $erhaltene = [];
    $this->mock(RecipeGeneratorService::class, function ($m) use (&$erhaltene, $recipe) {
        $m->shouldReceive('generiere')->andReturnUsing(function (...$args) use (&$erhaltene, $recipe) {
            $erhaltene = $args[2] ?? [];
            return ['recipe' => $recipe, 'offene' => []];
        });
    });

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idea->id, (int) $step->id, (int) $session->id);

    expect($erhaltene['level'] ?? null)->toBe('gehoben')
        ->and($erhaltene['bio'] ?? null)->toBeTrue()
        ->and($erhaltene['convenience'] ?? null)->toBe('from_scratch')
        ->and((int) ($erhaltene['cascade_step_id'] ?? 0))->toBe((int) $step->id);   // Steuer-Key bleibt
});

it('Freie 1-Klick-Erstellung: schnellErstellen legt eine cockpit_frei-Session an (de-trend) + öffnet den Editor', function () {
    Livewire::test(PlanungIndex::class)
        ->call('schnellErstellen', 'gericht')
        ->assertDispatched('modal.open');

    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)->latest('id')->first();
    expect($session)->not->toBeNull()
        ->and($session->created_via)->toBe('cockpit_frei')
        ->and($session->title)->toBe('Freies Gericht')
        ->and($session->source_knowledge_document_id)->toBeNull();   // kein Trend
});

it('Cockpit rendert die Regler-Leitplanken + die freie Erstell-Leiste (Blade kompiliert, KI-Fläche ist in der Planung)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-planung-regler')      // volle Regler-Fläche im Planung-Tab
        ->assertSeeHtml('data-frei-rezept')          // freie 1-Klick-Erstellung
        ->assertSeeHtml('data-planung-ziel-vk');     // Gericht-Achse Ziel-VK
});

it('Queue-Watchdog: Lauf hängt lange OHNE Step-Fortschritt → sichtbarer Hinweis (kein Worker), kein Abbruch', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'rezept', 'status' => 'running']);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'running']);
    // Raw-Update, um den created_at-Touch zu umgehen: „vor 2 Minuten gestartet, immer noch nichts fertig".
    FoodAlchemistCascadeRun::where('id', $run->id)->update(['created_at' => now()->subSeconds(120)]);

    Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->set('laeuft', true)
        ->call('pruefeLauf')
        ->assertSet('laeuft', true)              // kein Abbruch — weiter pollen
        ->assertNotSet('hinweis', null);         // Watchdog schlägt an
});

it('Queue-Watchdog schweigt, wenn ein Schritt Fortschritt gemacht hat (Worker bewiesen aktiv, legitim langer Fan-out)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'concept', 'status' => 'running']);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'done']);      // Fortschritt!
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    FoodAlchemistCascadeRun::where('id', $run->id)->update(['created_at' => now()->subSeconds(300)]);

    Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->set('laeuft', true)
        ->call('pruefeLauf')
        ->assertSet('hinweis', null);            // trotz 5 Min Laufzeit kein Hinweis — Worker lebt
});
