<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Pairing-Erdung nach der Umstellung auf das Anker-Vokabular (2026-08-07): pairingStems() +
 * der semantische Recall (searchAnkerSlugs) ziehen aus foodalchemist_vocab_pairing_anchors,
 * NICHT mehr aus den Pairing-Docs. Beweist, dass die KI-Rezept-Pairing-Erdung erhalten bleibt,
 * OBWOHL kein einziges category='pairing'-Dokument existiert (Voraussetzung fürs Aufräumen).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // core_embeddings nachziehen (für den Semantik-Test).
    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', [
        '--realpath' => true,
        '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php'],
    ])->run();

    config([
        'embeddings.default_provider'               => 'fake',
        'foodalchemist.semantic_search.provider'    => 'fake',
        'foodalchemist.semantic_search.min_score'   => 0.01,
        'foodalchemist.semantic_search.anker_min_score' => 0.01,
    ]);
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, function () {
        $r = new EmbeddingProviderRegistry();
        $r->register(new FakeEmbeddingProvider(256));

        return $r;
    });
    $this->app->forgetInstance(EmbeddingService::class);

    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    // Anker-Vokabular + Kante — bewusst KEIN Pairing-Doc.
    $mkAnker = function (string $slug): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->basilikum = $mkAnker('basilikum');
    $this->tomate = $mkAnker('tomate');
    foreach ([[$this->basilikum, $this->tomate], [$this->tomate, $this->basilikum]] as [$x, $y]) {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
            'type' => 'erprobt', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Routing: pairing:discovery für ein Generator-Feature.
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'ai_generate_recipe', 'category' => 'pairing', 'mode' => 'discovery',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('liefert Pairing-Erdung aus dem Anker-Graphen OHNE Pairing-Docs (lexikalisch)', function () {
    expect(DB::table('foodalchemist_knowledge_documents')->where('category', 'pairing')->count())->toBe(0);

    $ctx = app(KnowledgeContextService::class)
        ->contextFor('ai_generate_recipe', 'Ein sommerliches Gericht mit Basilikum und Tomate.');

    expect($ctx['block'])->toContain('FLAVOR-PAIRING')
        ->and($ctx['block'])->toContain('Tomate');                 // Partner von basilikum aus dem Graphen
    expect(collect($ctx['files_used'])->contains(fn ($f) => str_starts_with($f, 'graph:')))->toBeTrue();
});

it('semantischer Recall zieht Anker-Slugs über searchAnkerSlugs (nicht Pairing-Docs)', function () {
    app(KnowledgeEmbeddingService::class)->embedAnkers();

    $slugs = app(KnowledgeEmbeddingService::class)->searchAnkerSlugs('Basilikum', 5);
    expect($slugs)->toContain('basilikum');
});
