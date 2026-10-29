<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Services\Ai\KiExternLauf;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 (P2b): recipes.ENRICH extern — EnrichRecipeJob als KiExternWiederholbar. Vergleichstest Plattformweg = MCP-Weg über die ganze
 * Anreicherung (mehrere KI-Glieder mit Schreibzugriffen dazwischen), kein Doppellauf, keine Nicht-DB-Nebenwirkung vor dem Commit.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);

    $hg = FoodAlchemistRecipeMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'FND', 'label' => 'Fonds & Saucen']);
    $this->kategorie = FoodAlchemistRecipeCategory::create(['team_id' => $this->rootTeam->id, 'main_group_id' => $hg->id, 'code' => 'RED', 'label' => 'Reduktionen']);
    $katId = $this->kategorie->id;
    $this->stub = new class($katId) extends FakeAiProvider
    {
        public int $calls = 0;

        public function __construct(private int $katId)
        {
        }

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            return ['content' => json_encode(['werte' => ['description' => 'Dunkle, sirupartige Saucenbasis.', 'category_id' => $this->katId, 'taste_direction' => 'herzhaft'], 'confidence' => 0.82]),
                'model' => 'fake-oneshot', 'usage' => []];
        }
    };
    app()->singleton(FakeAiProvider::class, fn () => $this->stub);

    $this->rezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'p2b-'.bin2hex(random_bytes(4)),
        'name' => 'Reduktion: Rotwein-Schalotte', 'status' => 'draft', 'description' => 'Vom Generator.', 'description_source' => 'ki']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'done', 'staged' => false, 'created_via' => 'import']);
    $this->step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $this->rezept->id, 'sort' => 0]);

    $this->job = fn (bool $extern) => new EnrichRecipeJob($this->rootTeam->id, (int) $this->user->id, (int) $this->rezept->id, null, false, (int) $this->step->id,
        false, false, true, false, [], $extern);
    $this->zustand = fn () => collect($this->rezept->fresh()->toArray())->except(['updated_at', 'created_at'])->all();
    $this->antworteAlle = function () {
        $tool = app(ToolRegistry::class)->get('foodalchemist.ki_auftraege.ANTWORT');
        $ctx = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
        $offen = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get();
        $vorher = $this->stub->calls;
        $antworten = $offen->map(fn ($a) => ['auftrag_id' => $a->uuid,
            'werte' => json_decode($this->stub->chat(json_decode($a->messages, true), [])['content'], true)])->all();
        $this->stub->calls = $vorher;                          // die Antwort-Berechnung des Tests ist kein Plattform-Call

        return $tool->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $ctx);
    };
});

it('Vergleichstest: Plattformweg und MCP-Weg führen bei gleicher KI-Antwort zum gleichen Rezept — Antworten erst am Ende verbraucht, keine Doppel', function () {
    // Plattformweg (in Transaktion, danach zurück auf den Ausgangszustand)
    $vorher = ($this->zustand)();
    DB::beginTransaction();
    ($this->job)(false)->handle(app(RecipeOneShotService::class));
    $plattform = ($this->zustand)();
    DB::rollBack();
    expect($plattform)->not->toBe($vorher, 'der Plattformweg hat etwas geschrieben')
        ->and(($this->zustand)())->toBe($vorher)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
    $callsPlattform = $this->stub->calls;

    // MCP-Weg: Job extern, dann Antwort-Schleife — ANTWORT startet den Lauf selbst neu
    ($this->job)(true)->handle(app(RecipeOneShotService::class));
    expect(($this->zustand)())->toBe($vorher, 'wartend: nichts geschrieben')
        ->and($this->step->fresh()->deferred['enrich'] ?? [])->toMatchArray(['status' => 'queued', 'wartet_auf' => 'ki_auftraege'])
        ->and($this->stub->calls)->toBe($callsPlattform, 'der Provider wird im Extern-Weg nicht gerufen');

    // der Wissens-Lauf der zurückgerollten Transaktion existiert nicht mehr — der Auftrag darf nicht ins Leere zeigen
    foreach (DB::table('foodalchemist_ki_auftraege')->get() as $auftrag) {
        expect($auftrag->knowledge_run_id === null || DB::table('foodalchemist_knowledge_runs')->where('id', $auftrag->knowledge_run_id)->exists())
            ->toBeTrue("Auftrag {$auftrag->uuid}: knowledge_run_id {$auftrag->knowledge_run_id} existiert nicht");
    }
    expect(DB::table('foodalchemist_ki_auftraege')->whereNotNull('knowledge_run_id')->count())->toBe(0, 'der Lauf wurde zurückgerollt');

    $runden = 0;
    $nebenwirkungen = [];
    while (DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->exists() && $runden++ < 12) {
        ($this->antworteAlle)();
        $nebenwirkungen = array_merge($nebenwirkungen, KiExternLauf::$nebenwirkungen);
    }

    expect(($this->zustand)())->toBe($plattform, 'gleiches Ergebnis wie der Plattformweg')
        ->and($this->step->fresh()->deferred['enrich']['status'] ?? null)->toBe('done')
        ->and($this->stub->calls)->toBe($callsPlattform)
        ->and($nebenwirkungen)->toBe([], 'keine Nicht-DB-Nebenwirkung vor dem Commit')
        ->and(DB::table('foodalchemist_ki_auftraege')->pluck('status')->unique()->all())->toBe(['verbraucht']);
    $z = app(KiAuftragService::class)->zaehler($this->rootTeam->id);
    expect($z['doppelt'])->toBe(0)->and($z['verwaist'])->toBe(0)->and($z['offen'])->toBe(0)
        ->and($z['angelegt'])->toBeGreaterThanOrEqual(4, 'ein Auftrag je KI-Glied (category, geschmack, dichteklasse, regeneration)');
});

it('recipes.ENRICH per MCP: extern, Aufträge statt OpenAI, Bilder entfallen, nach den Antworten ist das Rezept angereichert', function () {
    $mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $r = app(ToolRegistry::class)->get('foodalchemist.recipes.ENRICH')->execute(['recipe_id' => $this->rezept->id, 'ki_bilder' => true], $mcp);

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['extern'])->toBeTrue()
        ->and($this->stub->calls)->toBe(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->count())->toBeGreaterThan(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->first()->lauf_key)->toBe("enrich-recipe:{$this->rootTeam->id}:{$this->rezept->id}");

    $runden = 0;
    while (DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->exists() && $runden++ < 12) {
        ($this->antworteAlle)();
    }
    $frisch = $this->rezept->fresh();
    expect((int) $frisch->category_id)->toBe((int) $this->kategorie->id)
        ->and($frisch->category_source)->toBe('ki')
        ->and($frisch->taste_direction)->toBe('herzhaft');
});

it('recipes.ENRICH ohne MCP-Herkunft (interner Weg) bleibt beim Plattform-Provider', function () {
    $intern = new ToolContext($this->user, $this->rootTeam);
    $r = app(ToolRegistry::class)->get('foodalchemist.recipes.ENRICH')->execute(['recipe_id' => $this->rezept->id], $intern);

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['extern'])->toBeFalse()
        ->and($this->stub->calls)->toBeGreaterThan(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});

it('ein zweiter manueller Aufruf während der Lauf wartet legt keinen zweiten Lauf an', function () {
    $mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $tool = app(ToolRegistry::class)->get('foodalchemist.recipes.ENRICH');
    $tool->execute(['recipe_id' => $this->rezept->id], $mcp);
    $anzahl = DB::table('foodalchemist_ki_auftraege')->count();

    $tool->execute(['recipe_id' => $this->rezept->id], $mcp);

    expect(DB::table('foodalchemist_ki_auftraege')->count())->toBe($anzahl);
});
