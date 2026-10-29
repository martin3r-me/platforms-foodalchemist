<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\ConformanceCheckJob;
use Platform\FoodAlchemist\Jobs\EnrichGeneratedRecipeJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Services\Ai\KiExternLauf;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 (P2c-2): der JOB-Weg des Generators — GenerateRecipeJob + Kinder (EnrichGeneratedRecipeJob, ConformanceCheckJob, Sub-Rezept-Jobs).
 * Wartestatus nie als Fehler, Rollback bis zur Antwort, Extern-Flag erbt in alle Kinder, Kinder rufen den Plattform-Provider nie.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);

    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
        ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1],
    ] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $gp = $this->makeGp($this->rootTeam, 'Schalotten: frisch, ganz');
    $gp->update(['main_ingredient_slug' => 'schalotten', 'status' => 'approved']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Schalotten', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 4.0, 'status' => '0']);
    $gp->update(['lead_la_supplier_item_id' => $la->id]);

    config(['foodalchemist.ai.provider' => 'core']);
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            return ['content' => json_encode(['werte' => [
                'name' => 'Reduktion: Rotwein-Schalotte', 'description' => 'Sirupartige Saucenbasis.', 'preparation' => '1. Schalotten anschwitzen.',
                'zutaten' => [['text' => 'Schalotten', 'slug' => 'schalotten', 'quantity' => 300, 'unit' => 'g']],
            ], 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(LLMProviderContract::class, $this->stub);

    $this->job = fn (bool $extern, bool $voll = false, ?string $runId = null) => new GenerateRecipeJob(
        $runId ?? (string) \Illuminate\Support\Str::uuid(), $this->rootTeam->id, (int) $this->user->id, 'Dunkle Rotwein-Schalotten-Reduktion',
        ['convenience' => 'from_scratch'], false, $voll, $extern);
    $this->starte = function (GenerateRecipeJob $j) {
        Cache::put(GenerateRecipeJob::cacheKey($j->runId), ['status' => 'pending', 'gestartet_at' => now()->timestamp], now()->addMinutes(15));
        $j->handle(app(RecipeGeneratorService::class));
    };
    $this->antworteOffene = function () {
        $tool = app(ToolRegistry::class)->get('foodalchemist.ki_auftraege.ANTWORT');
        $vorher = $this->stub->calls;
        $antworten = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get()->map(fn ($a) => [
            'auftrag_id' => $a->uuid, 'werte' => json_decode($this->stub->chat(json_decode($a->messages, true), [])['content'], true)])->all();
        $this->stub->calls = $vorher;                            // die Antwort-Berechnung des Tests ist kein Plattform-Call

        return $tool->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp);
    };
    $this->snap = function () {
        $r = FoodAlchemistRecipe::ohneTeamSicht('test')->where('name', 'Reduktion: Rotwein-Schalotte')->first();

        return $r === null ? null : ['name' => $r->name, 'status' => $r->status->value, 'description' => $r->description, 'preparation' => $r->preparation,
            'zutaten' => FoodAlchemistRecipeIngredient::where('recipe_id', $r->id)->orderBy('id')->get()->map(fn ($z) => [(int) $z->gp_id, (float) $z->quantity])->all()];
    };
});

it('Vergleichstest: GenerateRecipeJob extern und Plattformweg erzeugen dasselbe Rezept; Wartestatus nie als Fehler; Provider im Extern-Weg 0×', function () {
    DB::beginTransaction();                                       // Folge-Jobs hängen an afterCommit und fallen mit dem Rollback weg: fairer Vergleich
    $platt = ($this->job)(false);
    ($this->starte)($platt);
    $plattform = ($this->snap)();
    $statusPlatt = Cache::get(GenerateRecipeJob::cacheKey($platt->runId))['status'] ?? null;
    DB::rollBack();
    $callsPlattform = $this->stub->calls;
    expect($plattform)->not->toBeNull()->and($statusPlatt)->toBe('done')->and($callsPlattform)->toBeGreaterThan(0)
        ->and(($this->snap)())->toBeNull();

    DB::beginTransaction();
    $extern = ($this->job)(true);
    $geschrieben = [];                                            // jede Status-Schreibung des Laufs, auch kurzzeitige
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Cache\Events\KeyWritten::class, function ($e) use (&$geschrieben, $extern) {
        if ($e->key === GenerateRecipeJob::cacheKey($extern->runId) && is_array($e->value)) {
            $geschrieben[] = $e->value['status'] ?? null;
        }
    });
    ($this->starte)($extern);
    $stati = [];
    $runden = 0;
    while (DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->exists() && $runden++ < 12) {
        $stati[] = Cache::get(GenerateRecipeJob::cacheKey($extern->runId));
        expect(($this->snap)())->toBeNull('wartend: kein Halb-Rezept');
        ($this->antworteOffene)();
    }
    $stati[] = Cache::get(GenerateRecipeJob::cacheKey($extern->runId));
    $externStand = ($this->snap)();
    $auftragStatus = DB::table('foodalchemist_ki_auftraege')->pluck('status')->unique()->values()->all();
    $zaehler = app(KiAuftragService::class)->zaehler($this->rootTeam->id);
    DB::rollBack();

    expect($stati[0]['status'])->toBe('pending')->and($stati[0]['wartet_auf'] ?? null)->toBe('ki_auftraege')
        ->and(collect($stati)->pluck('status')->contains('error'))->toBeFalse('der Wartestatus ist nie ein Fehler')
        ->and($geschrieben)->not->toContain('error', 'auch nicht kurzzeitig zwischendurch')
        ->and($geschrieben)->toContain('pending')
        ->and(end($stati)['status'])->toBe('done')
        ->and($externStand)->toBe($plattform, 'gleiches Ergebnis wie der Plattformweg')
        ->and($this->stub->calls)->toBe($callsPlattform, 'der Provider wird im Extern-Weg nicht gerufen')
        ->and($auftragStatus)->toBe(['verbraucht'])
        ->and($zaehler['doppelt'])->toBe(0)->and($zaehler['offen'])->toBe(0);
});

it('Kinder erben das Extern-Flag: Enrich-Kind und Konformitäts-Kind sind extern gestartet, erst nach dem Commit', function () {
    Bus::fake([EnrichGeneratedRecipeJob::class, ConformanceCheckJob::class]);
    $j = ($this->job)(true, voll: true);
    ($this->starte)($j);
    $runden = 0;
    while (DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->exists() && $runden++ < 12) {
        if (DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->where('lauf_key', 'like', 'generate-recipe:%')->exists()) {
            expect(Bus::dispatched(EnrichGeneratedRecipeJob::class)->count())->toBe(0, 'kein Kind, solange das Rezept-Glied wartet (Rollback)');
        }
        ($this->antworteOffene)();
    }

    Bus::assertDispatchedTimes(EnrichGeneratedRecipeJob::class, 1);
    Bus::assertDispatched(EnrichGeneratedRecipeJob::class, fn ($k) => $k->extern === true && $k->runId === $j->runId && $k->afterCommit === true);
    expect($this->stub->calls)->toBe(0);
});

it('Kind im Extern-Lauf macht keinen Provider-Call: der ganze Baum (Rezept, Anreicherung, Konformität, Sensorik) läuft über Aufträge', function () {
    $j = ($this->job)(true, voll: true);
    ($this->starte)($j);
    $runden = 0;
    while (DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->exists() && $runden++ < 40) {
        ($this->antworteOffene)();
    }

    expect(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->count())->toBe(0, 'Baum vollständig beantwortet')
        ->and($this->stub->calls)->toBe(0, 'weder das Rezept noch eines der Kinder ruft den Plattform-Provider')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('lauf_key', 'like', 'enrich-generated:%')->count())->toBeGreaterThan(0, 'das Anreicherungs-Kind hat Aufträge angelegt')
        ->and(Cache::get(GenerateRecipeJob::cacheKey($j->runId))['status'] ?? null)->not->toBe('error');
});

it('Sub-Rezept-Kinder: _extern steht in KIND_ERBT und baueKind startet das Kind extern, erst nach dem Commit', function () {
    expect((new ReflectionClassConstant(RecipeDependencyWorkflowService::class, 'KIND_ERBT'))->getValue())->toContain('_extern');

    Bus::fake([GenerateRecipeJob::class]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running', 'staged' => false]);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'geplant', 'ref_type' => 'recipe']);
    $svc = app(RecipeDependencyWorkflowService::class);
    $team = $this->rootTeam;
    $nutzer = (int) $this->user->id;
    $baue = fn (array $params) => (function () use ($team, $kind, $nutzer, $params) {
        $this->baueKind($team, $kind, $nutzer, 'Fond: Kalb', $params, false);
    })->call($svc);

    $baue(['_extern' => true]);
    $baue([]);

    Bus::assertDispatchedTimes(GenerateRecipeJob::class, 2);
    Bus::assertDispatched(GenerateRecipeJob::class, fn ($k) => $k->extern === true && ($k->parameter['_extern'] ?? false) === true && $k->afterCommit === true);
    Bus::assertDispatched(GenerateRecipeJob::class, fn ($k) => $k->extern === false);
});

it('Plattformweg bleibt bytegleich: ohne Extern-Flag keine Transaktion und kein Auftrag', function () {
    $niveau = DB::transactionLevel();
    $j = ($this->job)(false);
    ($this->starte)($j);

    expect(Cache::get(GenerateRecipeJob::cacheKey($j->runId))['status'] ?? null)->toBe('done')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe($niveau)
        ->and(KiExternLauf::class)->toBeString();
});

it('Konformitäts-Kind des Anreicherungs-Jobs erbt das Extern-Flag: startet extern, kein Provider-Call, erst nach dem Commit der Anreicherung', function () {
    // das Rezept entsteht regulär (Plattformweg), danach läuft nur die Anreicherung extern; echte (Sync-)Queue, afterCommit wirkt wie in Produktion
    $gen = ($this->job)(false);
    ($this->starte)($gen);
    $recipe = FoodAlchemistRecipe::ohneTeamSicht('test')->where('name', 'Reduktion: Rotwein-Schalotte')->first();
    $callsVorher = $this->stub->calls;
    $verletzt = [];
    $externFlags = [];
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function ($e) use (&$verletzt, &$externFlags) {
        if (str_contains($e->job->resolveName(), 'ConformanceCheckJob')) {
            $externFlags[] = unserialize($e->job->payload()['data']['command'])->extern;     // so wurde das Kind gestartet
            if (DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->where('lauf_key', 'like', 'enrich-generated:%')->exists()) {
                $verletzt[] = 'Konformität lief, während die Anreicherung noch wartete';
            }
        }
    });
    $enrich = new EnrichGeneratedRecipeJob($gen->runId, $this->rootTeam->id, (int) $this->user->id, (int) $recipe->id, ['recipe_id' => $recipe->id], null, null, null, extern: true);
    $enrich->handle(app(\Platform\FoodAlchemist\Services\RecipeOneShotService::class));
    $runden = 0;
    while (DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->exists() && $runden++ < 40) {
        ($this->antworteOffene)();
    }

    expect($verletzt)->toBe([])
        ->and($this->stub->calls)->toBe($callsVorher, 'die externe Anreicherung samt Kindern ruft den Plattform-Provider nicht')
        ->and($externFlags)->toBe([true], 'das Konformitäts-Kind wurde genau einmal gestartet, und zwar extern');
});

it('erreicht die Warte-Ausnahme den catch des Jobs, schreibt er weder „error“ noch meldet er den Kaskaden-Step als gescheitert', function () {
    // ein Generator, der direkt am ersten KI-Aufruf hängt (die Ausnahme steigt bis in den Job)
    $this->mock(RecipeGeneratorService::class, function ($m) {
        $m->shouldReceive('generiere')->andReturnUsing(function () {
            app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('recipe.description', ['description' => 'x']);

            return [];
        });
    });
    $j = ($this->job)(true);
    $geschrieben = [];
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Cache\Events\KeyWritten::class, function ($e) use (&$geschrieben, $j) {
        if ($e->key === GenerateRecipeJob::cacheKey($j->runId) && is_array($e->value)) {
            $geschrieben[] = $e->value['status'] ?? null;
        }
    });
    Cache::put(GenerateRecipeJob::cacheKey($j->runId), ['status' => 'pending'], now()->addMinutes(15));

    $j->handle(app(RecipeGeneratorService::class));

    expect($geschrieben)->not->toContain('error')
        ->and(Cache::get(GenerateRecipeJob::cacheKey($j->runId))['status'])->toBe('pending')
        ->and(Cache::get(GenerateRecipeJob::cacheKey($j->runId))['wartet_auf'])->toBe('ki_auftraege')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->count())->toBe(1);
});
