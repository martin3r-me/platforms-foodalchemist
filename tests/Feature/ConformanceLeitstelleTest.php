<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\ConformanceCheckJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConformanceFinding;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Schicht 3 · Slice 3 (Leitstelle) — die step-zeile zeigt die offenen Konformitäts-Hinweise
 * am Rezept-Step und bietet den on-demand Re-Check. Verifiziert den vollen Render-Pfad
 * (render()-Injektion `offeneFuerViele` → ergebnis → step-zeile-Blade) + die Livewire-Action.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Leitstelle: step-zeile rendert Konformitäts-Hinweise + on-demand prüfen am Rezept-Step', function () {
    Queue::fake();

    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Konf-Test', 'brief' => 'x']);
    $recipe = $this->makeRecipe($this->rootTeam, 'Konf-Draft', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id,
        'scope' => 'gericht', 'status' => 'review',
    ]);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht',
        'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id,
    ]);

    FoodAlchemistConformanceFinding::create([
        'team_id' => $this->rootTeam->id, 'artifact_type' => 'recipe', 'artifact_id' => $recipe->id,
        'paragraph' => '§6.1', 'schweregrad' => 'hart', 'feld' => 'name',
        'reason' => 'Plural statt Singular', 'confidence' => 0.9,
        'status' => 'offen', 'fingerprint' => 'testfp-konf-1', 'seen_count' => 1,
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)              // ladeLetztenLauf → laufId=run, ergebnis rendert
        ->assertSee('Konformität')                  // die Hinweis-Fläche
        ->assertSee('Plural statt Singular')        // der Befund selbst
        ->assertSee('§6.1')
        ->call('konformitaetPruefen', $recipe->id); // on-demand-Action läuft fehlerfrei

    Queue::assertPushed(ConformanceCheckJob::class, fn ($job) => $job->artifactId === $recipe->id);
});
