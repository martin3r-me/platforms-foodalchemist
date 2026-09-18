<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Briefing Zutaten-Bulk-Import, 2026-09-18: knowledge.EMBED_STATUS ist die Kontrolle fuer die
 * blockweise Aktivierung — wie viele aktive Dossiers haben schon ein Embedding, wie viele nicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = app(ToolRegistry::class)->get('foodalchemist.knowledge.EMBED_STATUS');
});

it('meldet alle aktiven Dossiers ohne Embedding, wenn kein Provider verfuegbar ist', function () {
    app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Zutat A', 'slug' => 'zutat.a--verwendung', 'category' => 'cross_cutting',
        'content_md' => 'Inhalt A.',
    ]);

    $res = $this->tool->execute(['category' => 'cross_cutting'], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['aktiv_gesamt'])->toBe(1)
        ->and($res->data['mit_embedding'])->toBe(0)
        ->and($res->data['ohne_embedding'])->toBe(1)
        ->and($res->data['stichprobe_ohne_embedding'])->toContain('zutat.a--verwendung');
});

it('zaehlt inaktive Dossiers separat, nicht als "ohne Embedding"', function () {
    $doc = app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Zutat Inaktiv', 'slug' => 'zutat.inaktiv--verwendung', 'category' => 'cross_cutting',
        'content_md' => 'Inhalt.',
    ]);
    \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->update(['active' => false]);

    $res = $this->tool->execute(['category' => 'cross_cutting'], $this->kontext);

    expect($res->data['aktiv_gesamt'])->toBe(0)
        ->and($res->data['inaktiv'])->toBe(1);
});

it('unterscheidet mit/ohne Embedding korrekt, wenn ein Provider verfuegbar ist', function () {
    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', [
        '--realpath' => true,
        '--path' => [$core.'/2026_06_17_181355_create_core_embeddings_table.php'],
    ])->run();
    config(['foodalchemist.semantic_search.provider' => 'fake']);
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, function () {
        $r = new EmbeddingProviderRegistry();
        $r->register(new FakeEmbeddingProvider(64));

        return $r;
    });
    $this->app->forgetInstance(EmbeddingService::class);

    $mitEmbedding = app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Zutat mit Embedding', 'slug' => 'zutat.mit--verwendung', 'category' => 'cross_cutting',
        'content_md' => 'Inhalt mit Embedding.',
    ]);
    $ohneEmbedding = app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Zutat ohne Embedding', 'slug' => 'zutat.ohne--verwendung', 'category' => 'cross_cutting',
        'content_md' => 'Inhalt ohne Embedding.',
    ]);
    // Direkt embedden statt ueber die Queue (Sync-Vertrag ist nicht Gegenstand dieses Tests).
    $frischesDoc = \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->where('slug', $mitEmbedding->slug)->first();
    app(KnowledgeEmbeddingService::class)->queueDocument($frischesDoc);

    $res = $this->tool->execute(['category' => 'cross_cutting'], $this->kontext);

    expect($res->data['aktiv_gesamt'])->toBe(2)
        ->and($res->data['mit_embedding'])->toBe(1)
        ->and($res->data['ohne_embedding'])->toBe(1)
        ->and($res->data['stichprobe_ohne_embedding'])->toBe(['zutat.ohne--verwendung']);
});

it('filtert auf einen Slug-Praefix', function () {
    app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Acai', 'slug' => 'zutat.acai--verwendung', 'category' => 'cross_cutting', 'content_md' => 'Inhalt.',
    ]);
    app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Vanille', 'slug' => 'zutat.vanille--verwendung', 'category' => 'cross_cutting', 'content_md' => 'Inhalt.',
    ]);

    $res = $this->tool->execute(['prefix' => 'zutat.acai'], $this->kontext);

    expect($res->data['aktiv_gesamt'])->toBe(1);
});
