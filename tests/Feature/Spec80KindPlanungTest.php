<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeKomponentenPlanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ein neues Unterrezept im Gericht läuft automatisch durch die Basisrezept-Planung — wie ein allein gestartetes
 * Basisrezept, aber ohne Mensch-Gate (Dominique 09.10.: „Ein Basisrezept steht für sich“, „Ja, automatisch“).
 * Entwurf: Berater-Session, 00_INBOX/_Entwurf_Kind_Basisrezept_Auto_Planung_2026-10-09.md.
 */
beforeEach(function () {
    Queue::fake();
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->stub = function (array $werte) {
        app()->bind(FakeAiProvider::class, fn () => new class($werte) extends FakeAiProvider
        {
            public function __construct(private array $werte)
            {
            }

            public function chat(array $messages, array $options = []): array
            {
                return ['content' => json_encode(['werte' => $this->werte, 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                    'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'fake', 'tool_calls' => null];
            }
        });
    };
    $this->run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true, 'brief' => 'Rinderfilet',
    ]);
    // Gericht mit einer Zeile „Jus: Thymian“ 120 g, als Kind geplant (Dependency wie planChildren).
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g' . md5(microtime()), 'name' => '[HG] Rinderfilet', 'status' => 'draft',
    ]);
    $gramm = $this->unitG($this->rootTeam)->id;
    $this->zeile = FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'position' => 1, 'raw_text' => 'Jus: Thymian',
        'quantity' => 120, 'unit_vocab_id' => $gramm, 'match_method' => 'unmatched',
    ]);
    $this->eltern = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $this->run->id, 'kind' => 'gericht', 'status' => 'done',
        'label' => 'Rinderfilet', 'sort' => 1, 'depth' => 0, 'ref_type' => 'recipe', 'ref_id' => $this->gericht->id,
        'deferred' => ['children' => ['params' => ['aroma' => 'Ingwer, Kürbis', 'pax' => 80, 'ziel_vk_eur' => 24,
            'diaet_hart' => ['vegetarisch'], 'level' => 'gehoben'], 'user_id' => $this->user->id]],
    ]);
    $this->kind = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $this->run->id, 'parent_step_id' => $this->eltern->id,
        'kind' => 'rezept', 'status' => 'geplant', 'label' => 'Jus: Thymian', 'sort' => 2, 'depth' => 1,
    ]);
    FoodAlchemistCascadeRecipeDependency::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $this->run->id, 'parent_step_id' => $this->eltern->id,
        'child_step_id' => $this->kind->id, 'ingredient_id' => $this->zeile->id,
    ]);
});

it('„jetzt erzeugen“ plant das Kind zuerst — mit Ansatz der Zeile, ohne Gericht-Achsen, ohne Mensch-Gate', function () {
    app(PlanningCascadeService::class)->erzeugeGeplantenStep($this->rootTeam, (int) $this->kind->id);

    Queue::assertNotPushed(GenerateRecipeJob::class);
    Queue::assertPushed(GenerateRecipePlanJob::class, function ($job) {
        $p = $job->params;

        return $job->stepId === (int) $this->kind->id && $job->brief === 'Jus: Thymian'
            && ($p['ziel_menge'] ?? null) === 120.0 && ($p['ziel_einheit'] ?? null) === 'g'
            && ($p['diaet_hart'] ?? null) === ['vegetarisch']
            && ! array_key_exists('aroma', $p) && ! array_key_exists('pax', $p) && ! array_key_exists('ziel_vk_eur', $p);
    });
    expect($this->kind->fresh()->status)->toBe('running');

    // Eine spätere Stufen-Freigabe startet das Kind nicht ein zweites Mal.
    expect(app(RecipeDependencyWorkflowService::class)->dispatchGeplantesKind($this->rootTeam, $this->kind->fresh()))->toBeFalse();
});

it('Kind-Plan mit mehreren Komponenten baut ohne Gate: Plan, Suchbegriffe aus dem Plan, kein _defer_children', function () {
    ($this->stub)(['komponenten' => [
        ['name' => 'Fond: Gemüse', 'funktion' => 'basis', 'menge' => 100, 'einheit' => 'g', 'suchbegriffe' => ['Röstgemüse', 'Fond']],
        ['name' => 'Thymian', 'funktion' => 'aroma', 'menge' => 20, 'einheit' => 'g', 'suchbegriffe' => ['Thymian', 'Röstgemüse']],
    ]]);
    app(PlanningCascadeService::class)->erzeugeGeplantenStep($this->rootTeam, (int) $this->kind->id);
    $planJob = Queue::pushed(GenerateRecipePlanJob::class)->first();

    $planJob->handle(app(RecipeKomponentenPlanService::class), app(PlanningCascadeService::class));

    $kind = $this->kind->fresh();
    expect($kind->status)->toBe('running')                                  // nie `geplant` am Kind
        ->and($kind->context_snapshot['plan'] ?? null)->toBeTrue()
        ->and($kind->context_snapshot['komponenten'])->toHaveCount(2);
    Queue::assertPushed(GenerateRecipeJob::class, function ($job) use ($kind) {
        $p = $job->parameter;

        return (int) $p['cascade_step_id'] === (int) $kind->id && $p['auto_dependencies'] === true
            && count($p['plan_komponenten'] ?? []) === 2
            && ! array_key_exists('_defer_children', $p) && ! array_key_exists('_voll_anreichern', $p)
            && $p['suchbegriffe'] === ['Röstgemüse', 'Fond', 'Thymian'];
    });
});

it('ein Baustein: Kind-Plan mit einer Komponente baut ohne plan_komponenten', function () {
    ($this->stub)(['komponenten' => [['name' => 'Jus: Thymian', 'funktion' => 'basis', 'menge' => 120, 'einheit' => 'g', 'suchbegriffe' => []]]]);
    app(PlanningCascadeService::class)->erzeugeGeplantenStep($this->rootTeam, (int) $this->kind->id);

    Queue::pushed(GenerateRecipePlanJob::class)->first()->handle(app(RecipeKomponentenPlanService::class), app(PlanningCascadeService::class));

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => ! array_key_exists('plan_komponenten', $job->parameter));
});

it('unterste Ebene (MAX_DEPTH) plant nicht, sondern baut direkt', function () {
    $this->kind->update(['depth' => RecipeDependencyWorkflowService::MAX_DEPTH]);

    app(PlanningCascadeService::class)->erzeugeGeplantenStep($this->rootTeam, (int) $this->kind->id);

    Queue::assertNotPushed(GenerateRecipePlanJob::class);
    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => (int) $job->parameter['cascade_step_id'] === (int) $this->kind->id);
});

it('Kind-Plan: Bestand nur freigegeben und diät-passend', function () {
    ($this->stub)(['komponenten' => [
        ['name' => 'Fond: Kalb', 'funktion' => 'basis', 'menge' => 100, 'einheit' => 'g', 'suchbegriffe' => []],
        ['name' => 'Fond: Gemüse', 'funktion' => 'basis', 'menge' => 20, 'einheit' => 'g', 'suchbegriffe' => []],
    ]]);
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'kalb' . md5(microtime()), 'name' => 'Fond: Kalb', 'status' => 'approved',
        'spec_is_vegan' => false, 'spec_is_vegetarian' => false]);   // ausdrücklich nicht vegetarisch (unbekannt blockiert nicht)
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'gem' . md5(microtime()), 'name' => 'Fond: Gemüse', 'status' => 'draft']);

    $plan = app(RecipeKomponentenPlanService::class)->plane($this->rootTeam, 'Jus: Thymian',
        RecipeDependencyWorkflowService::kindParameter(['diaet_hart' => ['vegetarisch'], 'aroma' => 'Kürbis']), 4);

    expect($plan[0]['bestand'])->toBeNull()     // Kalbsfond: nicht vegetarisch
        ->and($plan[1]['bestand'])->toBeNull();  // Gemüsefond: nur Entwurf
});

it('Obergrenze der Kind-Komponenten wird geklemmt (Config 0 → 1, 99 → MAX_KOMPONENTEN)', function () {
    $sieben = array_map(fn ($i) => ['name' => "Komponente {$i}", 'funktion' => 'basis', 'menge' => 10, 'einheit' => 'g', 'suchbegriffe' => []], range(1, 7));
    ($this->stub)(['komponenten' => $sieben]);
    $svc = app(RecipeKomponentenPlanService::class);

    expect($svc->plane($this->rootTeam, 'x', [], 0))->toHaveCount(1)
        ->and($svc->plane($this->rootTeam, 'x', [], 99))->toHaveCount(RecipeKomponentenPlanService::MAX_KOMPONENTEN)
        ->and($svc->plane($this->rootTeam, 'x', [], 4))->toHaveCount(4);
});

it('Plan-Fehler am Kind: Step scheitert, kein Bau-Job, Zeile bleibt ungebunden', function () {
    app()->bind(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            throw new \RuntimeException('Provider weg');
        }
    });
    app(PlanningCascadeService::class)->erzeugeGeplantenStep($this->rootTeam, (int) $this->kind->id);

    Queue::pushed(GenerateRecipePlanJob::class)->first()->handle(app(RecipeKomponentenPlanService::class), app(PlanningCascadeService::class));

    expect($this->kind->fresh()->status)->toBe('failed')
        ->and($this->zeile->fresh()->referenced_recipe_id)->toBeNull();
    Queue::assertNotPushed(GenerateRecipeJob::class);
});

it('der Bau überschreibt den Plan im Snapshot nicht mehr — kindVorgaben findet die Suchbegriffe (B6 in der Pipeline)', function () {
    $this->eltern->update(['context_snapshot' => ['plan' => true, 'komponenten' => [
        ['name' => 'Jus: Thymian', 'suchbegriffe' => ['Thymian', 'Röstaromen']],
    ]]]);

    app(RecipeDependencyWorkflowService::class)->prepare($this->rootTeam, (int) $this->eltern->id, 'Rinderfilet', [], true);

    expect($this->eltern->fresh()->context_snapshot['komponenten'][0]['name'] ?? null)->toBe('Jus: Thymian');

    app(PlanningCascadeService::class)->erzeugeGeplantenStep($this->rootTeam, (int) $this->kind->id);
    Queue::assertPushed(GenerateRecipePlanJob::class, fn ($job) => ($job->params['suchbegriffe'] ?? null) === ['Thymian', 'Röstaromen']);
});

it('„neu erzeugen“ am Kind läuft über denselben Start — ohne Gericht-Aroma, mit eigenem Plan', function () {
    $alt = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'j' . md5(microtime()), 'name' => 'Jus: Thymian', 'status' => 'draft']);
    $this->run->update(['params' => ['aroma' => 'Ingwer, Kürbis', 'pax' => 80]]);
    $this->kind->update(['status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $alt->id]);

    app(PlanningCascadeService::class)->regeneriereStep($this->rootTeam, (int) $this->kind->id);

    Queue::assertPushed(GenerateRecipePlanJob::class, fn ($job) => $job->stepId === (int) $this->kind->id
        && ! array_key_exists('aroma', $job->params) && ($job->params['ziel_menge'] ?? null) === 120.0);
});

it('Architektur: Kind-Steps werden nur über starteKind/baueKind gestartet', function () {
    $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/Services/RecipeDependencyWorkflowService.php');

    expect(substr_count($quelle, 'GenerateRecipeJob::dispatch('))->toBe(1)
        ->and(substr_count($quelle, 'GenerateRecipePlanJob::dispatch('))->toBe(1);
});
