<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConformanceFinding;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 80 Teil H — erst prüfen, dann anreichern; grün erst nach der Anreicherung. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
    $this->lauf = fn () => FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'review', 'staged' => true]);
    $this->schritt = fn ($run, $recipe) => FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'rezept', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id]);
});

it('gestufte Freigabe: Rezept wird erst nach erfolgreicher Anreicherung grün', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Püree: Petersilienwurzel (grün)', ['status' => 'draft']);
    $step = ($this->schritt)(($this->lauf)(), $recipe);

    app(PlanningCascadeService::class)->gibStepFrei($this->rootTeam, (int) $step->id);
    expect($recipe->refresh()->status->value)->toBe('review');

    $job = new EnrichRecipeJob($this->rootTeam->id, auth()->id(), $recipe->id, null, false, $step->id);
    Closure::bind(fn () => $this->freigabeAbschliessen(), $job, EnrichRecipeJob::class)();

    expect($recipe->refresh()->status->value)->toBe('approved')
        ->and($step->refresh()->deferred['freigabe_nach_anreicherung'] ?? null)->toBeNull();
});

it('ohne Freigabe-Marker hebt die Anreicherung nichts an', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Fond: Kalb', ['status' => 'review']);
    $step = ($this->schritt)(($this->lauf)(), $recipe);

    $job = new EnrichRecipeJob($this->rootTeam->id, auth()->id(), $recipe->id, null, false, $step->id);
    Closure::bind(fn () => $this->freigabeAbschliessen(), $job, EnrichRecipeJob::class)();

    expect($recipe->refresh()->status->value)->toBe('review');
});

it('Sammelknopf: reichert neu Gebautes an, lässt Entwürfe mit hartem Befund stehen', function () {
    $run = ($this->lauf)();
    $gut = $this->makeRecipe($this->rootTeam, 'Jus: Pilz', ['status' => 'draft']);
    $offen = $this->makeRecipe($this->rootTeam, 'Gemüsebeilage: Karotte', ['status' => 'draft']);
    $sGut = ($this->schritt)($run, $gut);
    $sOffen = ($this->schritt)($run, $offen);
    FoodAlchemistConformanceFinding::create(['team_id' => $this->rootTeam->id, 'artifact_type' => 'recipe', 'artifact_id' => $offen->id,
        'paragraph' => '§1.2', 'schweregrad' => 'hart', 'feld' => 'name', 'reason' => 'Präfix nicht im Vokabular',
        'status' => 'offen', 'fingerprint' => 'test-' . $offen->id, 'confidence' => 1.0]);

    $r = app(PlanningCascadeService::class)->gibNeueFrei($this->rootTeam, $run->id);

    expect($r)->toBe(['freigegeben' => 1, 'uebersprungen' => 1])
        ->and($sGut->refresh()->status)->toBe('freigegeben')
        ->and($sOffen->refresh()->status)->toBe('done');
    Queue::assertPushed(EnrichRecipeJob::class, fn ($job) => (int) $job->recipeId === (int) $gut->id);
});
