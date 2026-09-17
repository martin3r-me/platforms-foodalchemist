<?php

use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket B Aufgabe 6 — `laufStatus()` liest additiv den neuen Snapshot-Key
 * `knowledge_dropped` (geschrieben von RecipeGenerationContextService::build() +
 * RecipeDependencyWorkflowService::afterGenerated(), s. dort) und gibt ihn je Step als
 * `wissen_verworfen` aus — getrennt nach `retrieval`/`kanon`, `null` statt einer leeren Struktur,
 * solange nichts verworfen wurde (kein Etikett ohne Landebahn).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('gibt wissen_verworfen getrennt nach Kanal aus, wenn etwas verworfen wurde', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'rezept', 'status' => 'done', 'sort' => 1,
        'context_snapshot' => [
            'knowledge_files' => ['domain-doc@v1'],
            'kanon_files' => ['regelwerk-a@v1'],
            'knowledge_dropped' => [
                'retrieval' => ['fruchtgemuese-substitutionen@v3'],
                'kanon' => ['regelwerk-c-wenn-platz@v1'],
            ],
        ],
    ]);

    $status = app(PlanningCascadeService::class)->laufStatus($this->rootTeam, (int) $run->id);
    $schritt = collect($status['schritte'])->firstWhere('id', (int) $step->id);

    expect($schritt['wissen_verworfen']['retrieval'] ?? null)->toBe(['fruchtgemuese-substitutionen@v3'])
        ->and($schritt['wissen_verworfen']['kanon'] ?? null)->toBe(['regelwerk-c-wenn-platz@v1']);
});

it('laesst wissen_verworfen komplett weg, wenn nichts verworfen wurde', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'rezept', 'status' => 'done', 'sort' => 1,
        'context_snapshot' => [
            'knowledge_files' => ['domain-doc@v1'],
            'kanon_files' => ['regelwerk-a@v1'],
            'knowledge_dropped' => ['retrieval' => [], 'kanon' => []],
        ],
    ]);

    $status = app(PlanningCascadeService::class)->laufStatus($this->rootTeam, (int) $run->id);
    $schritt = collect($status['schritte'])->firstWhere('id', (int) $step->id);

    expect($schritt)->not->toHaveKey('wissen_verworfen');
});

it('bleibt fail-safe bei Steps ohne knowledge_dropped-Schluessel (alter Snapshot-Stand)', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'rezept', 'status' => 'done', 'sort' => 1,
        'context_snapshot' => ['knowledge_files' => ['domain-doc@v1'], 'kanon_files' => ['regelwerk-a@v1']],
    ]);

    $status = app(PlanningCascadeService::class)->laufStatus($this->rootTeam, (int) $run->id);
    $schritt = collect($status['schritte'])->firstWhere('id', (int) $step->id);

    expect($schritt)->not->toHaveKey('wissen_verworfen');
});
