<?php

use Illuminate\Support\Facades\Cache;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * P0.2 — gestufte Generierung: `generiere()` meldet feine Stufen über einen
 * Fortschritts-Callback (Kontext → KI → Zutaten [→ Kohärenz]). Zweck: die UI
 * zeigt live, WO der Lauf steht — und ein OOM-/Hänger-Tod bleibt an der letzten
 * gemeldeten Stufe stehen (Pinpoint der Ursache auf demo). Der Callback ist
 * optional (letzter Param, Default null) → alle Bestandsaufrufe byte-identisch.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.ai.provider' => 'fake']);
    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
        ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1],
    ] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('meldet die Zutaten-Stufe über den Fortschritts-Callback (realer Lauf, ohne LLM)', function () {
    // kiRezeptOverride überspringt Kontext-Bau + LLM → nur die Transaktions-/Matching-Stufe feuert.
    $stufen = [];
    $ki = ['name' => 'Fond: Test', 'zutaten' => [['text' => 'Wasser', 'quantity' => 1000, 'unit' => 'ml']]];

    app(RecipeGeneratorService::class)->generiere(
        $this->rootTeam, 'Testfond', [], $ki, false, null, null,
        function (string $stufe) use (&$stufen) { $stufen[] = $stufe; },
    );

    expect($stufen)->toContain('Zutaten werden zugeordnet …');
});

it('läuft ohne Callback byte-identisch weiter (Default null)', function () {
    $ki = ['name' => 'Fond: Ohne Callback', 'zutaten' => [['text' => 'Wasser', 'quantity' => 500, 'unit' => 'ml']]];

    $r = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'x', [], $ki, false);

    expect($r['recipe'])->toBeInstanceOf(FoodAlchemistRecipe::class)
        ->and($r['recipe']->name)->toBe('Fond: Ohne Callback');
});

it('der Job reicht einen Callback an generiere, der die Stufe live in den Cache schreibt', function () {
    $runId = 'p0-2-' . uniqid();
    $teamId = $this->rootTeam->id;
    $userId = $this->user->id;
    $progressMid = null;
    $cbCallable = null;

    // Generator mocken: wir prüfen NUR die Verdrahtung — bekommt generiere einen
    // Callback (8. Arg), und schreibt dieser über den Job eine live-Stufe in den Cache?
    $this->mock(RecipeGeneratorService::class, function ($m) use (&$progressMid, &$cbCallable, $runId, $teamId) {
        $m->shouldReceive('generiere')->once()->andReturnUsing(function (...$args) use (&$progressMid, &$cbCallable, $runId, $teamId) {
            $cb = $args[7] ?? null;                         // onProgress = 8. Argument
            $cbCallable = is_callable($cb);
            if ($cbCallable) {
                $cb('Zwischenstufe X');
                $progressMid = Cache::get(GenerateRecipeJob::cacheKey($runId));
            }

            return [
                'recipe' => FoodAlchemistRecipe::create(['team_id' => $teamId, 'recipe_key' => $runId, 'name' => 'X', 'status' => 'draft']),
                'statistik' => ['bestand_gp' => 0, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0],
                'offene' => [],
            ];
        });
    });

    (new GenerateRecipeJob($runId, $teamId, $userId, 'desc', [], false, false))->handle(app(RecipeGeneratorService::class));

    expect($cbCallable)->toBeTrue()
        ->and($progressMid['progress'] ?? null)->toBe('Zwischenstufe X')
        ->and($progressMid['status'] ?? null)->toBe('pending');
});

it('#505-Nachtrag Task 5: mergt die Timings aus generiere() in context_snapshot des Kaskaden-Steps', function () {
    // Vertrag mit Peter/Paket C (laufStatus() liest 'timings' je Step): der Job ist der
    // einzige zweite Schreiber von context_snapshot neben prepare() im selben Aufruf —
    // kein Race. Schlüsselname exakt 'timings', 5 Phasen wie RecipeGeneratorService.
    $run = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running',
    ]);
    $step = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'basisrezept', 'status' => 'running',
    ]);
    $runId = 'p5-' . uniqid();
    $teamId = $this->rootTeam->id;
    $userId = $this->user->id;
    $timings = ['context_ms' => 12, 'generation_ms' => 340, 'matching_and_save_ms' => 8, 'checks_ms' => 3, 'generator_ms' => 363];

    $this->mock(RecipeGeneratorService::class, function ($m) use ($timings, $teamId, $runId) {
        $m->shouldReceive('generiere')->once()->andReturnUsing(function () use ($timings, $teamId, $runId) {
            return [
                'recipe' => FoodAlchemistRecipe::create(['team_id' => $teamId, 'recipe_key' => $runId, 'name' => 'X', 'status' => 'draft']),
                'statistik' => ['bestand_gp' => 0, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0, 'timings' => $timings],
                'offene' => [],
            ];
        });
    });

    (new GenerateRecipeJob($runId, $teamId, $userId, 'Testfond', ['cascade_step_id' => $step->id], false, false))
        ->handle(app(RecipeGeneratorService::class));

    expect($step->refresh()->context_snapshot['timings'] ?? null)->toBe($timings)
        // prepare() hat vorher geschrieben — der Job-Merge darf den Rest des Snapshots nicht wegwerfen.
        ->and($step->context_snapshot)->toHaveKey('built_at');
});
