<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Platform\Core\Jobs\GenerateEmbeddingJob;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * RAG-Autoindex (Werkstrang A) + Recall-Fallback (B1). FakeEmbeddingProvider →
 * deterministische Bag-of-Words-Vektoren; geprüft wird die VERDRAHTUNG (Observer/
 * Service embeddet + löscht, active-Gate, Purge, Graceful-off), NICHT echte
 * synonyme Semantik (die kann nur der echte Embedder auf demo, s. Plan-Risiken).
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();

    // core_embeddings ist nicht im selektiven Core-Satz → nachziehen (wie PoolEmbeddingTest).
    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', [
        '--realpath' => true,
        '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php'],
    ])->run();

    config([
        'embeddings.default_provider'             => 'fake',
        'foodalchemist.semantic_search.enabled'   => true,
        'foodalchemist.semantic_search.provider'  => 'fake',
        'foodalchemist.semantic_search.min_score' => 0.01,
    ]);
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, function () {
        $r = new EmbeddingProviderRegistry();
        $r->register(new FakeEmbeddingProvider(256));

        return $r;
    });
    $this->app->forgetInstance(EmbeddingService::class);

    $this->laVectors = fn () => DB::table('core_embeddings')->where('entity_type', PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM);
    $this->docVectors = fn () => DB::table('core_embeddings')->where('entity_type', KnowledgeEmbeddingService::ENTITY_TYPE);

    $this->mkLa = function (string $designation, string $slug = 'kichererbse', string $display = 'Kichererbse', array $extra = []): FoodAlchemistSupplierItem {
        $s = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
        $la = FoodAlchemistSupplierItem::create(array_merge([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $s->id,
            'designation' => $designation, 'qty' => 1.0, 'unit_code' => 'kg',
        ], $extra));
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id,
            'main_ingredient_slug' => $slug, 'main_ingredient_display' => $display,
        ]);

        return $la;
    };

    $this->mkDoc = function (string $slug, bool $active): object {
        $id = DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'team_id' => null, 'slug' => $slug, 'title' => $slug, 'category' => 'domain',
            'content_md' => 'Inhalt zu ' . $slug, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'imported_hash' => null, 'char_count' => 10,
            'active' => $active, 'source_path' => null, 'created_via' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('foodalchemist_knowledge_documents')->where('id', $id)->first();
    };
});

// ── A3 · Lieferartikel-Observer ────────────────────────────────────────────

it('A3: LA-Observer queued Re-Embed bei Anlage + relevanter Änderung, nicht bei irrelevanter', function () {
    Queue::fake();
    $la = ($this->mkLa)('Bio Hummus Paste 1kg');
    Queue::assertPushed(GenerateEmbeddingJob::class);            // created → embed

    Queue::fake();
    $la->update(['qty' => 2.0]);                                 // Gebinde — irrelevant für den Embed-Text
    Queue::assertNotPushed(GenerateEmbeddingJob::class);

    $la->update(['designation' => 'Bio Hummus Paste 2kg Geb.']); // Bezeichnung — relevant
    Queue::assertPushed(GenerateEmbeddingJob::class);
});

it('A3: LA-Observer löscht den Vektor bei Auslistung (is_discontinued) und beim Delete', function () {
    $la = ($this->mkLa)('Bio Hummus Paste 1kg');
    app(PoolEmbeddingService::class)->embedSupplierItems($this->rootTeam->id);
    expect(($this->laVectors)()->where('entity_id', (string) $la->id)->count())->toBe(1);

    $la->update(['is_discontinued' => true]);                    // Austritt → queueSupplierItem erkennt → delete
    expect(($this->laVectors)()->where('entity_id', (string) $la->id)->count())->toBe(0);

    $la2 = ($this->mkLa)('Kichererbsen getrocknet 5kg');
    app(PoolEmbeddingService::class)->embedSupplierItems($this->rootTeam->id);
    expect(($this->laVectors)()->where('entity_id', (string) $la2->id)->count())->toBe(1);
    $la2->delete();                                              // deleted-Observer → deleteSupplierItem
    expect(($this->laVectors)()->where('entity_id', (string) $la2->id)->count())->toBe(0);
});

it('A3: degradiert ohne Provider — kein LA-Vektor, kein Job, kein Fehler', function () {
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, fn () => new EmbeddingProviderRegistry());
    $this->app->forgetInstance(EmbeddingService::class);
    Queue::fake();

    ($this->mkLa)('Bio Hummus Paste 1kg');

    Queue::assertNotPushed(GenerateEmbeddingJob::class);
    expect(($this->laVectors)()->count())->toBe(0);
});

// ── A1 · Wissens-Doc queueDocument (active-gegatet) ─────────────────────────

it('A1: queueDocument embeddet aktive Docs, purged inaktive (active-Gate)', function () {
    $svc = app(KnowledgeEmbeddingService::class);

    Queue::fake();
    $svc->queueDocument(($this->mkDoc)('domain.aktiv', true));
    Queue::assertPushed(GenerateEmbeddingJob::class);            // aktiv → embed-Job

    Queue::fake();
    $svc->queueDocument(($this->mkDoc)('domain.inaktiv', false));
    Queue::assertNotPushed(GenerateEmbeddingJob::class);         // inaktiv → purge (Sync-Delete), kein Embed-Job
});

// ── A2 · Waisen-Purge ───────────────────────────────────────────────────────

it('A2: purgeStale entfernt den Vektor eines deaktivierten Docs', function () {
    $doc = ($this->mkDoc)('domain.tomate', true);
    $svc = app(KnowledgeEmbeddingService::class);
    $svc->embedCorpus(['domain']);
    expect(($this->docVectors)()->where('entity_id', (string) $doc->id)->count())->toBe(1);

    DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->update(['active' => false]);
    $res = $svc->purgeStale(true);

    expect($res['available'])->toBeTrue()
        ->and(($this->docVectors)()->where('entity_id', (string) $doc->id)->count())->toBe(0);
});

// ── B1 · bestandsInventar-Fallback ohne Provider ────────────────────────────

it('B1: bestandsInventar fällt ohne Provider auf reine Lexik zurück', function () {
    // Provider aus → semantischer Pass no-op → reines Token-LIKE (Alt-Verhalten).
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, fn () => new EmbeddingProviderRegistry());
    config(['foodalchemist.semantic_search.enabled' => false]);

    foreach (['Kuerbissuppe', 'Kuerbis-Espuma', 'Kartoffelpuree'] as $name) {
        FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => 'ragtest|' . Str::slug($name),
            'name' => $name, 'status' => 'approved', 'is_sales_recipe' => false,
        ]);
    }

    $svc = app(RecipeGeneratorService::class);
    $call = Closure::bind(
        fn ($team, $d) => $this->bestandsInventar($team, $d),
        $svc, RecipeGeneratorService::class
    );

    // Tokens ≥4: „kuerbis", „suppe" → matcht Kuerbissuppe + Kuerbis-Espuma (nach Name sortiert).
    $inventar = $call($this->rootTeam, 'Kuerbis Suppe');

    expect($inventar)->toBe(['Kuerbis-Espuma', 'Kuerbissuppe']);
});
