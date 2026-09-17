<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket B Aufgabe 5 — „Verwendet" = wirklich gesendet.
 *
 * `RecipeDependencyWorkflowService::prepare()` schreibt `context_snapshot.kanon_files` VOR dem
 * KI-Call aus der vollen Kanon-Liste (pflicht + wenn_platz). `AiGatewayService::selectKanon()`
 * droppt `wenn_platz` später, wenn das Budget knapp wird — die Step-Zeile „Kanon (n) · Recherche
 * (m)" zeigte damit eine Zahl, die grösser sein kann als das, was tatsächlich im Prompt stand.
 * `afterGenerated()` korrigiert das jetzt anhand der Call-Log-Zeile, die der Gateway selbst
 * bereits mit der WIRKLICH gesendeten Kanon-Liste schreibt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->mkRun = function (): FoodAlchemistCascadeRun {
        return FoodAlchemistCascadeRun::create([
            'team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running',
        ]);
    };

    $this->mkStep = function (FoodAlchemistCascadeRun $run, array $kanonFiles): FoodAlchemistCascadeRunStep {
        return FoodAlchemistCascadeRunStep::create([
            'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
            'kind' => 'rezept', 'status' => 'running', 'sort' => 1,
            'context_snapshot' => [
                'knowledge_files' => ['domain-doc@v1'],
                'kanon_files' => $kanonFiles,
                'template_ids' => [], 'pairing_keys' => [], 'built_at' => now()->toIso8601String(),
            ],
        ]);
    };

    $this->mkCallLog = function (int $recipeId, string $feature, array $kanonGesendet): void {
        $daten = [
            'uuid' => (string) UuidV7::generate(),
            'team_id' => (int) $this->rootTeam->id,
            'feature' => $feature,
            'model' => 'test-model',
            'tier' => 'B',
            'target_table' => 'foodalchemist_recipes',
            'target_id' => $recipeId,
            'prompt_hash' => hash('sha256', $feature.$recipeId),
            'tokens_in' => 1000, 'tokens_out' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_ai_call_log', 'knowledge_channels')) {
            $daten['knowledge_channels'] = json_encode(['kanon' => $kanonGesendet, 'domain' => ['domain-doc@v1']]);
        }
        DB::table('foodalchemist_ai_call_log')->insert($daten);
    };
});

it('korrigiert kanon_files auf die wirklich gesendete Liste (wenn_platz gedroppt)', function () {
    $run = ($this->mkRun)();
    $vollePflichtUndWennPlatz = ['regelwerk-a@v1', 'regelwerk-b@v1', 'regelwerk-c-wenn-platz@v1'];
    $step = ($this->mkStep)($run, $vollePflichtUndWennPlatz);
    $rezept = $this->makeRecipe($this->rootTeam, 'Tomatensuppe');

    // Der Gateway hat nur 2 von 3 Dossiers gesendet (das dritte fiel als wenn_platz weg).
    $wirklichGesendet = ['regelwerk-a@v1', 'regelwerk-b@v1'];
    ($this->mkCallLog)($rezept->id, 'recipe.generator', $wirklichGesendet);

    app(RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, $step->id, auth()->id(), $rezept, [], []
    );

    expect($step->refresh()->context_snapshot['kanon_files'])->toBe($wirklichGesendet)
        // Die Retrieval-Seite (knowledge_files) bleibt unberührt — sie war schon vorher korrekt.
        ->and($step->context_snapshot['knowledge_files'])->toBe(['domain-doc@v1']);
});

it('lässt kanon_files unverändert, wenn keine passende Call-Log-Zeile existiert (fail-soft)', function () {
    $run = ($this->mkRun)();
    $original = ['regelwerk-a@v1', 'regelwerk-b@v1'];
    $step = ($this->mkStep)($run, $original);
    $rezept = $this->makeRecipe($this->rootTeam, 'Tomatensuppe ohne Call-Log');

    // Kein Call-Log-Eintrag für dieses Rezept — die Korrektur darf nicht raten oder crashen.
    app(RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, $step->id, auth()->id(), $rezept, [], []
    );

    expect($step->refresh()->context_snapshot['kanon_files'])->toBe($original);
});

it('rührt Steps ohne kanon_files-Schlüssel nicht an', function () {
    $run = ($this->mkRun)();
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'rezept', 'status' => 'running', 'sort' => 1,
        'context_snapshot' => ['knowledge_files' => ['domain-doc@v1']],
    ]);
    $rezept = $this->makeRecipe($this->rootTeam, 'Tomatensuppe ohne Kanon-Feld');
    ($this->mkCallLog)($rezept->id, 'recipe.generator', ['regelwerk-a@v1']);

    app(RecipeDependencyWorkflowService::class)->afterGenerated(
        $this->rootTeam, $step->id, auth()->id(), $rezept, [], []
    );

    expect($step->refresh()->context_snapshot)->not->toHaveKey('kanon_files');
});
