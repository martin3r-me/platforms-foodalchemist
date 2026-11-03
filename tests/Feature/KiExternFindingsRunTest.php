<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Jobs\RecipeFindingsRunJob;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Services\Ai\KiExternLauf;
use Platform\FoodAlchemist\Services\RecipeFindingsBatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 · recipe_findings_run.POST extern: der Befunde-Batch lief im Worker (RecipeFindingsRunJob) immer über den Plattform-Provider,
 * auch beim MCP-Aufruf. Jetzt: MCP-Aufruf → Job extern (KiExternWiederholbar), je fälligem Rezept ein KI-Auftrag, 0 Provider-Calls,
 * nichts geschrieben bis zur Antwort; nach ANTWORT startet der Lauf neu und legt dieselben Befunde ab wie der Plattformweg.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);

    // Antwort trägt beide Formen: Copilot liest `befunde`, der Bauart-Pass `einstufung` (ein Basisrezept „eher Gericht“ → Befund)
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            return ['content' => json_encode(['werte' => [
                'gesamturteil' => 'stimmig',
                'befunde' => [['art' => 'hinweis', 'zutat_text' => 'Salz', 'begruendung' => 'Salzmenge prüfen.', 'konfidenz' => 0.8]],
                'einstufung' => 'gericht', 'konfidenz' => 0.9, 'begruendung' => 'Eigenständig servierbar.',
            ], 'confidence' => 0.8], JSON_UNESCAPED_UNICODE), 'model' => 'fake-befunde', 'usage' => []];
        }
    };
    app()->singleton(FakeAiProvider::class, fn () => $this->stub);

    $this->rezepte = collect(['Jus: Rotwein', 'Fond: Kalb', 'Sauce: Hollandaise'])->map(fn ($n) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fr-' . bin2hex(random_bytes(4)), 'name' => $n, 'status' => 'approved',
    ]));
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->intern = new ToolContext($this->user, $this->rootTeam);
    $this->befunde = fn () => DB::table('foodalchemist_recipe_findings')->orderBy('recipe_id')->orderBy('kind')
        ->get(['recipe_id', 'kind', 'fingerprint', 'reason', 'status'])->map(fn ($z) => (array) $z)->all();

    // MCP-Client: offene Aufträge mit derselben Antwort wie der Plattform-Provider beantworten (ANTWORT startet den Lauf neu)
    $this->antworteAlle = function () {
        $offen = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get();
        $vorher = $this->stub->calls;
        $antworten = $offen->map(fn ($a) => ['auftrag_id' => $a->uuid,
            'werte' => json_decode($this->stub->chat(json_decode($a->messages, true), [])['content'], true)])->all();
        $this->stub->calls = $vorher;                          // die Antwort-Berechnung des Tests ist kein Plattform-Call

        return app(ToolRegistry::class)->get('foodalchemist.ki_auftraege.ANTWORT')
            ->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp);
    };
    $this->loese = function () {
        $runden = 0;
        $nebenwirkungen = [];
        while (DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->exists() && $runden++ < 12) {
            $a = ($this->antworteAlle)();
            expect($a->success)->toBeTrue($a->error ?? '');
            $nebenwirkungen = array_merge($nebenwirkungen, KiExternLauf::$nebenwirkungen);
        }

        return [$runden, $nebenwirkungen];
    };
});

it('recipe_findings_run.POST per MCP: Aufträge statt Provider-Calls, nichts geschrieben; nach ANTWORT dieselben Befunde wie der Plattformweg', function () {
    // Plattformweg als Referenz (Job direkt, in Transaktion, danach zurück)
    DB::beginTransaction();
    $refRun = FoodAlchemistBulkRun::starte($this->rootTeam->id, \Platform\FoodAlchemist\Enums\BulkRunType::Review, 0, [], $this->user->id, umfangSteht: false);
    (new RecipeFindingsRunJob((int) $refRun->id, (int) $this->rootTeam->id, 25, false, 'copilot', (int) $this->user->id))
        ->handle(app(RecipeFindingsBatchService::class));
    $plattform = ($this->befunde)();
    $callsPlattform = $this->stub->calls;
    DB::rollBack();
    expect($plattform)->toHaveCount(3)->and($callsPlattform)->toBe(3)
        ->and(($this->befunde)())->toBe([]);
    $this->stub->calls = 0;

    $r = app(ToolRegistry::class)->get('foodalchemist.recipe_findings_run.POST')->execute(['limit' => 25], $this->mcp);

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['extern'])->toBeTrue()
        ->and($this->stub->calls)->toBe(0, 'der Plattform-Provider wird per MCP nicht gerufen');
    $offen = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get();
    $run = FoodAlchemistBulkRun::find($r->data['run_id']);
    expect($offen)->toHaveCount(3, 'ein Auftrag je fälligem Rezept')
        ->and($offen->pluck('feature')->unique()->values()->all())->toBe(['recipe.review'])
        ->and($offen->pluck('lauf_key')->unique()->values()->all())->toBe(["recipe-findings:{$this->rootTeam->id}:{$run->id}"])
        ->and(($this->befunde)())->toBe([], 'wartend: keine Befunde abgelegt')
        ->and(FoodAlchemistRecipe::whereNotNull('ai_reviewed_at')->count())->toBe(0, 'wartend: kein Prüf-Stempel')
        ->and($run->status)->toBe(BulkRunStatus::Running)
        ->and($run->context['wartet_auf'] ?? null)->toBe('ki_auftraege');

    [$runden, $nebenwirkungen] = ($this->loese)();

    $run->refresh();
    expect(($this->befunde)())->toBe($plattform, 'gleiche Befunde wie der Plattformweg')
        ->and($runden)->toBe(1)
        ->and($this->stub->calls)->toBe(0)
        ->and($nebenwirkungen)->toBe([], 'keine Nicht-DB-Nebenwirkung vor dem Commit')
        ->and(FoodAlchemistRecipe::whereNotNull('ai_reviewed_at')->count())->toBe(3)
        ->and($run->status)->toBe(BulkRunStatus::Done)
        ->and($run->context['geprueft'] ?? null)->toBe(3)
        ->and($run->context)->not->toHaveKey('wartet_auf')
        ->and(DB::table('foodalchemist_ki_auftraege')->pluck('status')->unique()->all())->toBe(['verbraucht']);
    $z = app(KiAuftragService::class)->zaehler($this->rootTeam->id);
    expect($z['doppelt'])->toBe(0)->and($z['offen'])->toBe(0);
});

it('pass=bauart per MCP (Pflichtliste Nr. 59): Aufträge recipe.bauart, nach ANTWORT Bauart-Befunde', function () {
    $r = app(ToolRegistry::class)->get('foodalchemist.recipe_findings_run.POST')->execute(['pass' => 'bauart'], $this->mcp);

    expect($r->success)->toBeTrue($r->error ?? '')->and($this->stub->calls)->toBe(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->pluck('feature')->unique()->values()->all())->toBe(['recipe.bauart']);

    ($this->loese)();

    expect($this->stub->calls)->toBe(0)
        ->and(collect(($this->befunde)())->pluck('kind')->unique()->values()->all())->toBe(['bauart'])
        ->and(FoodAlchemistRecipe::whereNotNull('structure_reviewed_at')->count())->toBe(3);
});

it('ohne MCP-Herkunft (interner Weg) bleibt der Befunde-Lauf beim Plattform-Provider', function () {
    $r = app(ToolRegistry::class)->get('foodalchemist.recipe_findings_run.POST')->execute([], $this->intern);

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['extern'])->toBeFalse()
        ->and($this->stub->calls)->toBe(3)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0)
        ->and(($this->befunde)())->toHaveCount(3);
});
