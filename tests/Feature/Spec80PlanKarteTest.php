<?php

use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 80 D7 — Plan-Karte: den Komponenten-Plan vor dem Bau ändern. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'review', 'staged' => true, 'params' => ['plan_first' => true]]);
    $this->step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'geplant',
        'context_snapshot' => ['plan' => true, 'komponenten' => [
            ['name' => 'Püree: Petersilienwurzel', 'menge' => 1500, 'einheit' => 'g', 'bestand' => ['recipe_id' => 374, 'name' => 'Püree: Petersilienwurzel'], 'abgelehnt' => [], 'neu' => false],
            ['name' => 'Matte: Petersilie', 'menge' => 100, 'einheit' => 'g', 'bestand' => null, 'abgelehnt' => [], 'neu' => true],
        ]]]);
    $this->svc = app(PlanningCascadeService::class);
    $this->k = fn () => $this->step->refresh()->context_snapshot['komponenten'];
});

it('ändert Menge, lehnt Bestand ab, entfernt und ergänzt', function () {
    $this->svc->aenderePlan($this->rootTeam, $this->step->id, 'menge', ['index' => 1, 'menge' => '120,5']);
    expect(($this->k)()[1]['menge'])->toBe(120.5);

    $this->svc->aenderePlan($this->rootTeam, $this->step->id, 'bestand_ablehnen', ['index' => 0]);
    expect(($this->k)()[0]['bestand'])->toBeNull()
        ->and(($this->k)()[0]['neu'])->toBeTrue()
        ->and(($this->k)()[0]['abgelehnt'][0]['grund'])->toBe('vom Menschen abgelehnt');

    $frei = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'butter_noisette', 'name' => 'Butter: Noisette', 'status' => 'approved']);
    $this->svc->aenderePlan($this->rootTeam, $this->step->id, 'hinzufuegen', ['name' => 'Butter: Noisette', 'menge' => '40']);
    expect(($this->k)())->toHaveCount(3)
        ->and(($this->k)()[2]['bestand']['recipe_id'])->toBe($frei->id);

    $this->svc->aenderePlan($this->rootTeam, $this->step->id, 'entfernen', ['index' => 2]);
    expect(($this->k)())->toHaveCount(2);
});

it('nach dem Bau ist der Plan fest', function () {
    $this->step->update(['status' => 'running']);

    expect(fn () => $this->svc->aenderePlan($this->rootTeam, $this->step->id, 'entfernen', ['index' => 0]))
        ->toThrow(RuntimeException::class, 'vor dem Bau');
});
