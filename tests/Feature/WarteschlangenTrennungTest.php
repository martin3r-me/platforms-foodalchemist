<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

/*
 * Fan-out-Jobs nach ARTEFAKT getrennt (Dominique: „einer Kaskade, einer Planung, einer Rezepte,
 * einer Gerichte, einer Anreicherung").
 *
 * Warum das verhaltensbasiert geprüft wird und nicht per Grep: die Trennung entscheidet der
 * AUFRUFER, nicht die Job-Klasse — dieselbe Klasse dient dem Fan-out UND dem Einzelklick
 * (`GenerateRecipeJob` ist das Kaskaden-Kind und der »erzeug mir ein Basisrezept«-Knopf). Ein
 * Test, der nur die Klasse ansieht, kann das nicht unterscheiden. Und ohne diese Zusicherung
 * rutscht die nächste neue Dispatch-Stelle stillschweigend auf `default` zurück — was niemand
 * merkt, bis wieder alles hinter einem großen Lauf wartet.
 */
it('Speiseplan-Zellen laufen auf der Gerichte-Schlange', function () {
    Queue::fake();
    config(['foodalchemist.queue.gerichte' => 'fa-gerichte']);

    $plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Zyklus', 'cycle_weeks' => 1]);

    app(PlanningCascadeService::class)->starteKaskade(
        $this->rootTeam, 'vollkaskade', null, 'voll_kreativ',
        ['owner_type' => 'speiseplan', 'owner_id' => (int) $plan->id],
    );

    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, fn ($job) => $job->queue === 'fa-gerichte');
});

it('Kaskaden-Kinder laufen auf der Rezepte-Schlange — parallel zu den Gerichten, nicht dahinter', function () {
    Queue::fake();
    config(['foodalchemist.queue.rezepte' => 'fa-rezepte']);

    $run = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running',
    ]);
    $eltern = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'running', 'sort' => 1,
    ]);
    $rezept = $this->makeRecipe($this->rootTeam, 'Rinderfilet');
    $this->makeIngredient($rezept, 'Kalbsfond', null, '100', 1);

    app(RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, $eltern->id, auth()->id(), $rezept,
        [['index' => 0, 'text' => 'Kalbsfond', 'primaer' => 'basisrezept_anlegen']],
        ['auto_dependencies' => true, '_voll_anreichern' => true],
    );

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->queue === 'fa-rezepte');
});

it('DER EINZELKLICK bleibt auf der Standard-Schlange — sonst wartet er hinter 90 Zellen', function () {
    Queue::fake();
    // Alle vier Schlangen scharf: der Einzelklick darf trotzdem KEINE davon nehmen.
    config([
        'foodalchemist.queue.gerichte' => 'fa-gerichte',
        'foodalchemist.queue.rezepte' => 'fa-rezepte',
        'foodalchemist.queue.kaskade' => 'fa-kaskade',
        'foodalchemist.queue.anreichern' => 'fa-anreichern',
    ]);

    // Der Generator-Modal-Pfad: EIN Rezept, ein Mensch wartet davor.
    // `$runId` ist ein NICHT-nullbarer string (Poll-Schlüssel des Generator-Modals).
    GenerateRecipeJob::dispatch(
        (string) \Illuminate\Support\Str::uuid(), $this->rootTeam->id, (int) auth()->id(),
        'Rinderfilet', [], false, false,
    );

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->queue === null);
});

it('ohne Konfiguration bleibt ALLES wie vorher — der Default darf nichts umleiten', function () {
    Queue::fake();
    // Kein config()-Eingriff: die Defaults sind leer. Das ist der Deploy-Zustand, und er MUSS
    // byte-identisch sein — Jobs auf einer Schlange ohne Worker bleiben lautlos liegen, die
    // Generierung stünde still ohne Fehlermeldung.
    $plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Zyklus', 'cycle_weeks' => 1]);

    app(PlanningCascadeService::class)->starteKaskade(
        $this->rootTeam, 'vollkaskade', null, 'voll_kreativ',
        ['owner_type' => 'speiseplan', 'owner_id' => (int) $plan->id],
    );

    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, fn ($job) => $job->queue === null);
});
