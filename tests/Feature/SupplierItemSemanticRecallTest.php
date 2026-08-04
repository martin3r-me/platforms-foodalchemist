<?php

use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\LaCandidateFinder;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * Spec 15 §5c — LA-Pool (foodalchemist_supplier_item) als geroutete Recall-Schicht.
 * Prüft die VERDRAHTUNG (FakeEmbeddingProvider, Bag-of-Words): embedSupplierItems →
 * Store → additive Semantik im LaCandidateFinder. Kernfall „Semantik sieht, was die
 * Lexik nicht sieht": der Such-Prefilter der Lexik durchsucht NUR die Designation —
 * ein Query-Token, das nur im klassifizierten Haupt-Slug (Structure) steht, ist
 * lexikalisch unsichtbar, aber im Embed-Text enthalten → semantisch findbar.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();

    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', [
        '--realpath' => true,
        '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php'],
    ])->run();

    config([
        'embeddings.default_provider'                  => 'fake',
        'foodalchemist.semantic_search.enabled'        => true,
        'foodalchemist.semantic_search.provider'       => 'fake',
        'foodalchemist.semantic_search.pool_sem_floor' => 0.1,   // Wiring-Test, keine Kalibrierung
    ]);
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, function () {
        $r = new EmbeddingProviderRegistry();
        $r->register(new FakeEmbeddingProvider(256));

        return $r;
    });
    $this->app->forgetInstance(EmbeddingService::class);
    $this->app->forgetInstance(LaCandidateFinder::class);

    $this->mkLaWithSlug = function (string $designation, string $slug, string $display): FoodAlchemistSupplierItem {
        $s = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $s->id,
            'designation' => $designation, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id,
            'main_ingredient_slug' => $slug, 'main_ingredient_display' => $display,
        ]);

        return $la;
    };
});

it('embeddet LAs und findet sie semantisch, wenn das Query-Token nur im Haupt-Slug steht', function () {
    // Designation OHNE "Kichererbse" → lexikalisch unsichtbar. Haupt-Slug = kichererbse
    // → landet im Embed-Text → semantisch findbar.
    $la = ($this->mkLaWithSlug)('Bio Hummus Paste 1kg Geb.', 'kichererbse', 'Kichererbse');

    $stats = app(PoolEmbeddingService::class)->embedSupplierItems($this->rootTeam->id);
    expect($stats['available'])->toBeTrue()
        ->and($stats['candidates'])->toBe(1);

    // Gegenprobe: rein lexikalisch (Semantik aus) findet NICHTS.
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->app->forgetInstance(LaCandidateFinder::class);
    expect(app(LaCandidateFinder::class)->best($this->rootTeam, 'Kichererbse'))->toBeNull();

    // Semantik an: der LA wird über den Haupt-Slug gefunden.
    config(['foodalchemist.semantic_search.enabled' => true]);
    $this->app->forgetInstance(LaCandidateFinder::class);
    $best = app(LaCandidateFinder::class)->best($this->rootTeam, 'Kichererbse');

    expect($best)->not->toBeNull()
        ->and($best->id)->toBe($la->id)
        ->and($best->origin)->toBeIn(['semantic', 'both'])
        ->and($best->score)->toBeGreaterThan(0.0);
});

it('bleibt idempotent — zweiter embed-Lauf ohne Textänderung erzeugt keine neuen Kandidaten', function () {
    ($this->mkLaWithSlug)('Bio Hummus Paste 1kg Geb.', 'kichererbse', 'Kichererbse');

    app(PoolEmbeddingService::class)->embedSupplierItems($this->rootTeam->id);
    // source_hash-idempotent: der Kandidat wird gezählt, aber nicht neu geschrieben.
    $again = app(PoolEmbeddingService::class)->embedSupplierItems($this->rootTeam->id);

    expect($again['candidates'])->toBe(1);   // gezählt, aber Store-seitig übersprungen
});
