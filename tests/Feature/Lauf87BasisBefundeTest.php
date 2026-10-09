<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Nachher-Messung demo Lauf 87 (Berater-Session, 10.10.): Befunde A, D, E vor „Basis steht“. */
beforeEach(function () {
    Queue::fake();
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
});

it('E: der Generator legt die Schritte gleich beim Anlegen an, nicht erst in der Heilung', function () {
    FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $r = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [], [
        'name' => 'Beilage: Herbsttrompeten',
        'zutaten' => [['text' => 'Herbsttrompeten', 'quantity' => 2000, 'unit' => 'g']],
        'preparation' => "1. Pilze putzen und trocken abreiben.\n2. In Butter scharf anbraten.\n3. Mit Salz abschmecken.",
    ])['recipe'];

    expect($r->steps()->count())->toBe(3);
});

it('A: gleiches Unterrezept in anderer Schreibweise wird EIN Kind-Step', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Ginger Beer', ['status' => 'draft']);
    $this->makeIngredient($jus, 'Reduktion: Ginger Beer-Ingwer', null, '600', 1);
    $this->makeIngredient($jus, 'Reduktion: Ginger Beer Ingwer', null, '600', 2);

    app(RecipeDependencyWorkflowService::class)->afterGenerated($this->rootTeam, (int) $step->id, (int) auth()->id(), $jus, [
        ['index' => 0, 'text' => 'Reduktion: Ginger Beer-Ingwer', 'primaer' => 'basisrezept_anlegen'],
        ['index' => 1, 'text' => 'Reduktion: Ginger Beer Ingwer', 'primaer' => 'basisrezept_anlegen'],
    ], ['auto_dependencies' => true]);

    expect(FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->count())->toBe(1)
        ->and(FoodAlchemistCascadeRecipeDependency::where('parent_step_id', $step->id)->count())->toBe(2);
});

it('D: der Prüfer sieht eine Zeile, deren Unterrezept gerade gebaut wird, nicht als Loch', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done']);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $eltern->id, 'kind' => 'rezept', 'status' => 'running']);
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Ginger Beer', ['status' => 'draft']);
    $zeile = $this->makeIngredient($jus, 'Reduktion: Ginger Beer-Ingwer', null, '600', 1);
    $offen = $this->makeIngredient($jus, 'Fond: Kalb', null, '400', 2);
    FoodAlchemistCascadeRecipeDependency::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'child_step_id' => $kind->id, 'ingredient_id' => $zeile->id]);

    $zutaten = collect(app(RecipeConformanceAdapter::class)->pruefauftrag($this->rootTeam, $jus->id)['kontext']['zutaten'] ?? []);

    expect($zutaten->firstWhere('text', 'Reduktion: Ginger Beer-Ingwer'))->toMatchArray(['ist_sub_rezept' => true])
        ->toHaveKey('unterrezept_in_arbeit')
        ->and($zutaten->firstWhere('text', 'Fond: Kalb'))->toMatchArray(['ist_sub_rezept' => false])
        ->not->toHaveKey('unterrezept_in_arbeit');
});

it('G: Kaufware wird ein Rüst-Basisrezept mit genau dieser Ware — kein Plan, keine Eigenherstellung', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done']);
    $mk = fn (string $label) => FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'kind' => 'rezept', 'status' => 'geplant', 'label' => $label, 'depth' => 1]);
    $oel = $mk('Kürbiskernöl');
    $jus = $mk('Jus: Thymian');
    $fond = $mk('Geflügelfond');
    $wf = app(RecipeDependencyWorkflowService::class);

    foreach ([$oel, $jus, $fond] as $s) {
        $wf->starteKind($this->rootTeam, $s, (int) auth()->id(), (string) $s->label, [], false);
    }

    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipeJob::class, fn ($j) => (int) $j->parameter['cascade_step_id'] === (int) $oel->id
        && ($j->parameter['ruest_ware'] ?? null) === 'Kürbiskernöl');
    Queue::assertNotPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::class, fn ($j) => $j->stepId === (int) $oel->id);
    // Basisrezept-Typ und Halbfabrikat bleiben echte Zubereitungen und planen.
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::class, fn ($j) => $j->stepId === (int) $jus->id && ! isset($j->params['ruest_ware']));
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::class, fn ($j) => $j->stepId === (int) $fond->id && ! isset($j->params['ruest_ware']));

    $prompt = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class)->build($this->rootTeam, 'Kürbiskernöl', ['ruest_ware' => 'Kürbiskernöl'], false)['prompt'];
    expect($prompt['ruest_basisrezept']['ware'])->toBe('Kürbiskernöl')
        ->and($prompt['ruest_basisrezept']['hinweis'])->toContain('Keine Eigenherstellung');
});
