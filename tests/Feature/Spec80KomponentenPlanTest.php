<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGenerationContextService;
use Platform\FoodAlchemist\Services\RecipeKomponentenPlanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 80 Teil B — Komponenten-Plan. Zielbild: Püree aus Bestandspüree + neuer Matte (Dominique 2026-10-09). */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
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
    $this->zielbild = ['komponenten' => [
        ['name' => 'Püree: Petersilienwurzel', 'funktion' => 'basis', 'menge' => 1500, 'einheit' => 'g', 'suchbegriffe' => ['Petersilienwurzel', 'Püree']],
        ['name' => 'Matte: Petersilie', 'funktion' => 'farbe', 'menge' => 100, 'einheit' => 'g', 'suchbegriffe' => ['glatte Petersilie', 'Blattgrün']],
    ]];
    $this->rezept = fn (string $name, string $status) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'k' . md5($name . $status . microtime()), 'name' => $name, 'status' => $status,
    ]);
});

it('plant Komponenten: Bestand nur freigegeben, Lücke wird „neu"', function () {
    ($this->stub)($this->zielbild);
    $frei = ($this->rezept)('Püree: Petersilienwurzel', 'approved');
    ($this->rezept)('Matte: Petersilie', 'draft');                   // Entwurf ist kein Bestand

    $plan = app(RecipeKomponentenPlanService::class)->plane($this->rootTeam, 'Petersilienpüree grün', ['ziel_menge' => 1.6, 'ziel_einheit' => 'kg']);

    expect($plan)->toHaveCount(2)
        ->and($plan[0]['bestand'])->toBe(['recipe_id' => $frei->id, 'name' => 'Püree: Petersilienwurzel'])
        ->and($plan[1]['bestand'])->toBeNull()
        ->and($plan[1]['neu'])->toBeTrue()
        ->and($plan[1]['funktion'])->toBe('farbe')
        ->and($plan[1]['suchbegriffe'])->toBe(['glatte Petersilie', 'Blattgrün']);
});

it('„komplett neu" schaut nicht in den Bestand', function () {
    ($this->stub)($this->zielbild);
    ($this->rezept)('Püree: Petersilienwurzel', 'approved');

    $plan = app(RecipeKomponentenPlanService::class)->plane($this->rootTeam, 'x', ['bestand' => 'komplett_neu']);

    expect($plan[0]['bestand'])->toBeNull();
});

it('mischt den Plan in die KI-Zutaten: Bestand als Verweis, Lücke als Unterrezept, KI-Zeile gleichen Namens ersetzt', function () {
    $zutaten = RecipeKomponentenPlanService::einmischen([
        ['text' => 'Matte: Petersilie', 'quantity' => 40, 'unit' => 'g'],
        ['text' => 'Butter: frisch', 'quantity' => 20, 'unit' => 'g'],
    ], [
        ['name' => 'Püree: Petersilienwurzel', 'menge' => 1500, 'einheit' => 'g', 'bestand' => ['recipe_id' => 374, 'name' => 'Püree: Petersilienwurzel']],
        ['name' => 'Matte: Petersilie', 'menge' => 100, 'einheit' => 'g', 'bestand' => null, 'funktion' => 'farbe'],
    ]);
    $je = collect($zutaten)->keyBy('text');

    expect($zutaten)->toHaveCount(3)
        ->and($je['Matte: Petersilie']['quantity'])->toBe(100)
        ->and($je['Matte: Petersilie']['sub_rezept'])->toBeTrue()
        ->and($je['Püree: Petersilienwurzel']['sub_rezept_id'])->toBe(374)
        ->and($je['Butter: frisch']['quantity'])->toBe(20);
});

it('erkennt die KI-Zeile auch bei anderer Schreibweise wieder — keine doppelte Plan-Komponente', function () {
    // demo Lauf 87: „Reduktion: Ginger Beer-Ingwer“ (KI) ↔ „Reduktion: Ginger Beer Ingwer“ (Plan) → zwei Zeilen, zwei Kinder.
    $zutaten = RecipeKomponentenPlanService::einmischen([
        ['text' => 'Jus: dunkle Grundjus', 'quantity' => 3000, 'unit' => 'ml'],
        ['text' => 'Reduktion: Ginger Beer-Ingwer', 'quantity' => 600, 'unit' => 'ml'],
    ], [
        ['name' => 'Jus: dunkle Grundjus', 'menge' => 3000, 'einheit' => 'ml', 'bestand' => null],
        ['name' => 'Reduktion: Ginger Beer Ingwer', 'menge' => 600, 'einheit' => 'ml', 'bestand' => null],
    ]);

    expect($zutaten)->toHaveCount(2)
        ->and(collect($zutaten)->where('sub_rezept', true))->toHaveCount(2);
});

it('Basisrezept-Go startet den Plan statt sofort zu bauen', function () {
    Queue::fake();
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Petersilie']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.rezept.brief', 'Petersilienwurzelpüree, grün, mit Petersilienmatte eingefärbt')
        ->set('eingabe.rezept.suchbegriffe', [['t' => 'Petersilienwurzel', 'g' => 'zutaten', 'q' => 'mensch']])
        ->call('goKaskade', 'rezept');

    Queue::assertPushed(GenerateRecipePlanJob::class);
    Queue::assertNotPushed(GenerateRecipeJob::class);
    expect(FoodAlchemistCascadeRun::latest('id')->first()->params['plan_first'] ?? null)->toBeTrue();
});

it('Plan-Job: mehrere Komponenten → geplant (Gate); Annehmen baut mit dem Plan', function () {
    Queue::fake();
    ($this->stub)($this->zielbild);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running',
        'brief' => 'Petersilienwurzelpüree grün', 'params' => ['plan_first' => true], 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'running']);

    (new GenerateRecipePlanJob($this->rootTeam->id, auth()->id(), $step->id, 'Petersilienwurzelpüree grün', $run->params))
        ->handle(app(RecipeKomponentenPlanService::class), app(PlanningCascadeService::class));

    $step->refresh();
    expect($step->status)->toBe('geplant')
        ->and($step->context_snapshot['plan'])->toBeTrue()
        ->and($step->context_snapshot['komponenten'])->toHaveCount(2);
    Queue::assertNotPushed(GenerateRecipeJob::class);

    app(PlanningCascadeService::class)->erzeugeGeplantenStep($this->rootTeam, $step->id);

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => count($job->parameter['plan_komponenten'] ?? []) === 2);
});

it('Plan-Job: ein einziger Baustein braucht kein Gate', function () {
    Queue::fake();
    ($this->stub)(['komponenten' => [['name' => 'Fond: Kalb', 'menge' => 5, 'einheit' => 'l']]]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running',
        'brief' => 'Kalbsfond', 'params' => ['plan_first' => true], 'staged' => true]);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'running']);

    (new GenerateRecipePlanJob($this->rootTeam->id, auth()->id(), $step->id, 'Kalbsfond', $run->params))
        ->handle(app(RecipeKomponentenPlanService::class), app(PlanningCascadeService::class));

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => ! isset($job->parameter['plan_komponenten']));
});

it('Generator-Prompt kennt die Zusammenstellung', function () {
    $ctx = app(RecipeGenerationContextService::class)->build($this->rootTeam, 'Petersilienwurzelpüree grün', [
        'plan_komponenten' => [['name' => 'Matte: Petersilie', 'menge' => 100, 'einheit' => 'g', 'bestand' => null]],
    ], false);

    expect($ctx['prompt'])->toHaveKey('zusammenstellung')
        ->and($ctx['prompt']['zusammenstellung']['komponenten'][0])->toMatchArray(['name' => 'Matte: Petersilie', 'menge' => 100, 'neu' => true]);
});

it('B6: Unterrezept bekommt als Ansatz die Menge seiner Zeile und die Suchbegriffe seiner Komponente', function () {
    $eltern = ($this->rezept)('Püree: Petersilienwurzel (grün)', 'draft');
    $zeile = FoodAlchemistRecipeIngredient::create(['team_id' => $eltern->team_id, 'recipe_id' => $eltern->id,
        'raw_text' => 'Matte: Petersilie', 'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done',
        'context_snapshot' => ['plan' => true, 'komponenten' => [['name' => 'Matte: Petersilie', 'suchbegriffe' => ['glatte Petersilie', 'Blattgrün']]]]]);

    $vorgaben = Closure::bind(fn ($s, $i, $t) => $this->kindVorgaben($s, $i, $t), app(RecipeDependencyWorkflowService::class), RecipeDependencyWorkflowService::class)
        ($step, $zeile->id, 'Matte: Petersilie');

    expect($vorgaben)->toBe(['ziel_menge' => 100.0, 'ziel_einheit' => 'g', 'suchbegriffe' => ['glatte Petersilie', 'Blattgrün']]);
});

it('Live-Test demo 09.10.: Komponenten-Namen werden gesäubert (Regelwerk-Verweis raus, Typ nicht doppelt)', function () {
    $s = \Platform\FoodAlchemist\Services\RecipeKomponentenPlanService::class;

    expect($s::bereinigeName('Püree: Petersilienwurzelpüree nach Basisrezept-Regelwerk §1'))->toBe('Püree: Petersilienwurzel')
        ->and($s::bereinigeName('Matte: Petersilienmatte nach Basisrezept-Regelwerk §1'))->toBe('Matte: Petersilie')
        ->and($s::bereinigeName('Püree: Sellerie (gemäß §1.2)'))->toBe('Püree: Sellerie')
        ->and($s::bereinigeName('Fond: Kalbsfond'))->toBe('Fond: Kalb')
        ->and($s::bereinigeName('Sauce: Tomatensauce'))->toBe('Sauce: Tomate')
        ->and($s::bereinigeName('Sauce: Beurre Blanc'))->toBe('Sauce: Beurre Blanc')
        ->and($s::bereinigeName('Kartoffelpüree'))->toBe('Kartoffelpüree');
});

it('Live-Test demo 09.10.: nicht freigegebener Bestand erscheint als abgelehnt mit Grund', function () {
    $entwurf = $this->makeRecipe($this->rootTeam, 'Püree: Petersilienwurzel', ['status' => 'review']);
    $abgelehnt = [];

    $treffer = app(\Platform\FoodAlchemist\Services\RecipeKomponentenPlanService::class)
        ->bestandFuer($this->rootTeam, 'Püree: Petersilienwurzel', [], $abgelehnt);

    expect($treffer)->toBeNull()
        ->and(collect($abgelehnt)->firstWhere('recipe_id', $entwurf->id)['grund'] ?? null)->toContain('noch nicht freigegeben');
});

it('Live-Test demo 09.10.: eine offene Basisrezept-Zeile fällt beim Re-Grounding nie auf ein rohes Grundprodukt', function () {
    $this->makeGp($this->rootTeam, 'Petersilienwurzel: frisch');
    $r = $this->makeRecipe($this->rootTeam, 'Püree: Petersilienwurzel (grün)', ['status' => 'draft']);
    $g = $this->unitG($this->rootTeam)->id;

    app(\Platform\FoodAlchemist\Services\RecipeService::class)->syncIngredients($this->rootTeam, $r->id, [
        ['raw_text' => 'Püree: Petersilienwurzel', 'quantity' => 7000, 'unit_vocab_id' => $g],
        ['raw_text' => 'Petersilienwurzel', 'quantity' => 100, 'unit_vocab_id' => $g],
    ]);

    $zeilen = $r->fresh()->ingredients()->orderBy('position')->get();
    expect($zeilen[0]->gp_id)->toBeNull()->and($zeilen[0]->referenced_recipe_id)->toBeNull()   // Lücke statt roher Wurzel
        ->and($zeilen[1]->gp_id)->not->toBeNull();                                                  // normale Zeile wie bisher
});
