<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeRunContext;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeRunService;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Services\ConformanceService;
use Platform\FoodAlchemist\Tests\Support\CopilotStub;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    app(KnowledgeService::class)->create($this->rootTeam, [
        'slug' => 'regel-snapshot', 'title' => 'Regel Snapshot', 'category' => 'regelwerk',
        'content_md' => 'REGEL_VERSION_EINS',
    ]);
    app(KnowledgeCanonService::class)->set($this->rootTeam, [
        'scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'slug' => 'regel-snapshot', 'mode' => 'pflicht',
    ]);
});

it('friert Inhalt und Bindung ein und lädt sie nach einer Queue-Grenze wieder', function () {
    $runs = app(KnowledgeRunService::class);
    $run = $runs->start($this->rootTeam);
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'regel-snapshot')->update([
        'content_md' => 'REGEL_VERSION_ZWEI', 'version' => 2,
    ]);
    DB::table('foodalchemist_knowledge_canon')->update(['active' => 0]);
    $loaded = $runs->load($this->rootTeam, $run->id);
    app(KnowledgeRunContext::class)->within($loaded, function () use ($run) {
        $docs = app(KnowledgeCanonService::class)->documentsFor('prompt_key', 'recipe.generator', $this->rootTeam);
        expect($docs)->toHaveCount(1)->and($docs->first()->version)->toBe(1)
            ->and($docs->first()->content_md)->toBe('REGEL_VERSION_EINS')
            ->and(app(KnowledgeRunContext::class)->current()->snapshotHash)->toBe($run->snapshotHash);
        // Rückgaben sind Kopien, keine veränderlichen Snapshot-Referenzen.
        $docs->first()->content_md = 'MUTIERT';
        expect(app(KnowledgeCanonService::class)->documentsFor('prompt_key', 'recipe.generator', $this->rootTeam)->first()->content_md)
            ->toBe('REGEL_VERSION_EINS');
    });
    expect(app(KnowledgeCanonService::class)->documentsFor('prompt_key', 'recipe.generator', $this->rootTeam))->toBeEmpty();
    expect($runs->start($this->rootTeam)->snapshotHash)->not->toBe($run->snapshotHash);
});

it('verbindet Generator und spätere Prüfung über dieselbe Lauf-ID trotz Dossieränderung', function () {
    $run = app(KnowledgeRunService::class)->start($this->rootTeam);
    $recipe = $this->makeRecipe($this->rootTeam, 'Snapshot Rezept');
    $recipe->forceFill(['knowledge_run_id' => $run->id, 'is_sales_recipe' => false])->save();
    CopilotStub::bind([]);
    app(KnowledgeRunContext::class)->within($run, fn () => app(AiGatewayService::class)->propose('recipe.generator', []));
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'regel-snapshot')->update([
        'content_md' => 'REGEL_VERSION_ZWEI', 'version' => 2, 'active' => 0,
    ]);
    app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $recipe->id);
    expect($GLOBALS['l6_all_prompt'])->toContain('REGEL_VERSION_EINS')->not->toContain('REGEL_VERSION_ZWEI');
    $logs = DB::table('foodalchemist_ai_call_log')->whereIn('feature', ['recipe.generator', 'conformance.check'])->get();
    expect($logs)->toHaveCount(2)->and($logs->pluck('knowledge_run_id')->unique()->all())->toBe([$run->id])
        ->and($logs->pluck('knowledge_snapshot_hash')->unique()->all())->toBe([$run->snapshotHash])
        ->and(app(KnowledgeRunContext::class)->current())->toBeNull();
});

it('räumt den Lauf bei Exceptions auf und stellt äußere Läufe wieder her', function () {
    $runs = app(KnowledgeRunService::class);
    $outer = $runs->start($this->rootTeam);
    $inner = $runs->start($this->rootTeam);
    $context = app(KnowledgeRunContext::class);
    $context->within($outer, function () use ($context, $outer, $inner) {
        expect(fn () => $context->within($inner, fn () => throw new RuntimeException('Testabbruch')))->toThrow(RuntimeException::class);
        expect($context->current()->id)->toBe($outer->id);
    });
    expect($context->current())->toBeNull();
});

it('weist fremde Teams und beschädigte Snapshots ab', function () {
    $runs = app(KnowledgeRunService::class);
    $run = $runs->start($this->rootTeam);
    expect(fn () => $runs->load($this->childA, $run->id))->toThrow(RuntimeException::class);
    app(KnowledgeRunContext::class)->within($run, function () {
        expect(fn () => app(KnowledgeCanonService::class)->documentsFor('prompt_key', 'recipe.generator', $this->childA))
            ->toThrow(RuntimeException::class);
    });
    DB::table('foodalchemist_knowledge_runs')->where('id', $run->id)->update(['snapshot' => '{}']);
    expect(fn () => $runs->load($this->rootTeam, $run->id))->toThrow(RuntimeException::class, 'beschädigt');
});

it('hinterlegt den Lauf bei einer echten Generator-Ausführung am Ergebnis und am Rezept', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1,
    ]);
    $seen = null;
    $this->mock(AiGatewayService::class, function ($mock) use (&$seen) {
        $mock->shouldReceive('propose')->once()->andReturnUsing(function () use (&$seen) {
            $seen = app(KnowledgeRunContext::class)->current()->id;
            return new \Platform\FoodAlchemist\Services\Ai\AiProposal([
                'name' => 'Fond: Snapshot', 'zutaten' => [['text' => 'Wasser', 'quantity' => 1000, 'unit' => 'ml']],
            ], 0.9, 'Test', []);
        });
    });
    $result = app(\Platform\FoodAlchemist\Services\RecipeGeneratorService::class)->generiere($this->rootTeam, 'Testfond');
    expect($result['knowledge_run_id'])->toBe($seen)
        ->and($result['recipe']->fresh()->knowledge_run_id)->toBe($seen)
        ->and(app(KnowledgeRunContext::class)->current())->toBeNull();
});

it('bindet den Queue-Job an seinen Startlauf auch wenn später ein neuer Lauf am Rezept steht', function () {
    $runs = app(KnowledgeRunService::class);
    $first = $runs->start($this->rootTeam);
    $recipe = $this->makeRecipe($this->rootTeam, 'Queue Snapshot');
    $recipe->forceFill(['knowledge_run_id' => $first->id, 'is_sales_recipe' => false])->save();
    $job = new \Platform\FoodAlchemist\Jobs\ConformanceCheckJob((int) $this->rootTeam->id, (int) auth()->id(), 'basisrezept', (int) $recipe->id);
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'regel-snapshot')->update(['content_md' => 'REGEL_VERSION_ZWEI', 'version' => 2]);
    $recipe->forceFill(['knowledge_run_id' => $runs->start($this->rootTeam)->id])->save();
    CopilotStub::bind([]);
    $restored = unserialize(serialize($job));
    $restored->handle(app(ConformanceService::class));
    expect($restored->knowledgeRunId)->toBe($first->id)
        ->and($GLOBALS['l6_all_prompt'])->toContain('REGEL_VERSION_EINS')->not->toContain('REGEL_VERSION_ZWEI')
        ->and(DB::table('foodalchemist_ai_call_log')->latest('id')->value('knowledge_run_id'))->toBe($first->id)
        ->and(app(KnowledgeRunContext::class)->current())->toBeNull();
});

it('liest auch Aufrufe ohne eigenes Rezeptziel über die Lauf-ID und hält Teams getrennt', function () {
    $run = app(KnowledgeRunService::class)->start($this->rootTeam);
    $recipe = $this->makeRecipe($this->rootTeam, 'Historie');
    $recipe->forceFill(['knowledge_run_id' => $run->id, 'is_sales_recipe' => false])->save();
    CopilotStub::bind([]);
    app(KnowledgeRunContext::class)->within($run, fn () => app(AiGatewayService::class)->propose('recipe.generator', []));
    $id = DB::table('foodalchemist_ai_call_log')->latest('id')->value('id');
    $calls = app(\Platform\FoodAlchemist\Services\Ai\RecipeKiKontextService::class)->alleCallsFuerRezept($recipe);
    expect($calls)->toHaveCount(1)->and($calls[0]['knowledge_run_id'])->toBe($run->id)
        ->and($calls[0]['knowledge_snapshot_hash'])->toBe($run->snapshotHash);
    DB::table('foodalchemist_ai_call_log')->where('id', $id)->update(['team_id' => $this->childA->id]);
    expect(app(\Platform\FoodAlchemist\Services\Ai\RecipeKiKontextService::class)->alleCallsFuerRezept($recipe))->toBeEmpty();
});
