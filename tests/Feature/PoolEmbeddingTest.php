<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Platform\Core\Jobs\GenerateEmbeddingJob;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * E1 (#507): Embedding-Ausweitung auf die GP-/Rezept-Pools. Der
 * FakeEmbeddingProvider erzeugt deterministische Bag-of-Words-Vektoren (kein
 * HTTP) — geprüft wird die VERDRAHTUNG (Backfill + Store + Team-Partition +
 * Idempotenz + Observer-Gate + Merge-/Delete-Hygiene), NICHT die echte
 * semantische Qualität (Beef→Rindfleisch teilt kein Token — das kann nur der
 * echte Embedder, Post-Deploy/E5). Bewusst KEIN Beef→Rindfleisch-Assert hier.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // core_embeddings ist NICHT im selektiven Core-Satz von seedTeamHierarchy → nachziehen.
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

    // Inkrementelle Observer-Jobs erfassen (nicht ausführen) → Backfill-Zählungen bleiben deterministisch.
    Queue::fake();

    $this->svc = app(PoolEmbeddingService::class);

    $this->mkGp = function (string $name, ?int $teamId, string $status = 'approved', array $extra = []): int {
        $attrs = array_merge([
            'team_id' => $teamId,
            'gp_key' => 'pooltest|' . mb_strtolower(str_replace([' ', ','], ['-', ''], $name)) . '|' . $status,
            'name' => $name,
            'status' => $status,
            'is_platzhalter' => false,
        ], $extra);

        return (int) FoodAlchemistGp::create($attrs)->id;
    };

    $this->gpVectors = fn () => DB::table('core_embeddings')->where('entity_type', PoolEmbeddingService::ENTITY_TYPE_GP);
    $this->recipeVectors = fn () => DB::table('core_embeddings')->where('entity_type', PoolEmbeddingService::ENTITY_TYPE_RECIPE);
});

it('embeddet den GP-Pool und macht ihn semantisch auffindbar (Verdrahtung)', function () {
    ($this->mkGp)('Rindfleisch, frisch', $this->childA->id);
    ($this->mkGp)('Kartoffel, festkochend', $this->childA->id);

    $stats = $this->svc->embedGps();

    expect($stats['available'])->toBeTrue()
        ->and($stats['candidates'])->toBe(2)
        ->and(($this->gpVectors)()->count())->toBe(2);

    // Suche mit Token-Overlap findet das richtige GP, der Distraktor bleibt draußen.
    $hits = app(EmbeddingService::class)->search(
        teamId: $this->childA->id,
        queryText: 'Rindfleisch Rouladen',
        entityTypes: [PoolEmbeddingService::ENTITY_TYPE_GP],
        limit: 5,
        minScore: 0.01,
        providerName: 'fake',
    );
    $names = collect($hits)->pluck('entity_id');
    expect($names)->not->toBeEmpty();
});

it('legt globale GPs (team_id NULL) unter dem Sentinel-Team ab', function () {
    ($this->mkGp)('Olivenöl nativ extra', null);          // global/BHG
    ($this->mkGp)('Hauslimonade', $this->childA->id);      // team-eigen

    $this->svc->embedGps();

    expect(($this->gpVectors)()->where('team_id', $this->svc->globalTeamId())->count())->toBe(1)
        ->and(($this->gpVectors)()->where('team_id', $this->childA->id)->count())->toBe(1);
});

it('hält rejected/merged/Platzhalter-GPs aus dem Pool', function () {
    ($this->mkGp)('Zanderfilet, TK', $this->childA->id, 'approved');
    ($this->mkGp)('Verworfenes GP', $this->childA->id, 'rejected');
    ($this->mkGp)('Platzhalter Wasser', $this->childA->id, 'approved', ['is_platzhalter' => true]);

    $stats = $this->svc->embedGps();

    expect($stats['candidates'])->toBe(1)
        ->and(($this->gpVectors)()->count())->toBe(1);
});

it('embeddet Basis- + Verkaufsrezepte inkl. is_sales_recipe-Metadatum', function () {
    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'pooltest|kalbsjus', 'name' => 'Kalbsjus', 'status' => 'approved', 'is_sales_recipe' => false,
    ]);
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'pooltest|kalbsbaeckchen', 'name' => 'Geschmorte Kalbsbäckchen', 'status' => 'approved', 'is_sales_recipe' => true,
    ]);

    $stats = $this->svc->embedRecipes();

    expect($stats['candidates'])->toBe(2)
        ->and(($this->recipeVectors)()->count())->toBe(2);

    $row = ($this->recipeVectors)()->where('entity_id', (string) $vk->id)->first();
    $meta = json_decode((string) $row->metadata, true);
    expect($meta['is_sales_recipe'] ?? null)->toBeTrue();
});

it('baut kompakte, entrauschte Embed-Texte (die Qualitäts-Stellschraube)', function () {
    // GP: Hauptzutat + Zustand, die schon im Namen stehen, werden NICHT dupliziert;
    //     der Warengruppen-CODE entfällt bewusst (Slice 1 Entrauschung #507); Struktur-
    //     Separatoren werden zu Leerzeichen normalisiert (symmetrisch zur Query).
    $text = $this->svc->gpEmbedText((object) [
        'name' => 'Rindfleisch, frisch', 'main_ingredient_display' => 'Rindfleisch',
        'main_ingredient_slug' => 'rindfleisch', 'condition' => 'frisch', 'commodity_group_code' => 'FLEISCH',
    ]);
    expect($text)->toBe('Rindfleisch frisch');

    // GP: Hauptzutat steckt bereits im Namen (Topinambur ⊂ Topinambur-Püree) → nicht
    //     dupliziert; nur der Zustand (TK) kommt dazu; WG-Code bleibt draußen.
    $text2 = $this->svc->gpEmbedText((object) [
        'name' => 'Topinambur-Püree', 'main_ingredient_display' => 'Topinambur',
        'condition' => 'TK', 'commodity_group_code' => 'GEMUESE',
    ]);
    expect($text2)->toBe('Topinambur-Püree TK')
        ->and($text2)->not->toContain('GEMUESE');

    // Rezept: Name (Kategorie) + Top-Zutaten, Separatoren normalisiert (kein „: " / „, ").
    $recipeText = $this->svc->recipeEmbedText(
        (object) ['name' => 'Geschmorte Kalbsbäckchen', 'category_label' => 'Schmorgericht', 'is_sales_recipe' => true],
        ['Kalbsbäckchen', 'Rotwein', 'Wurzelgemüse'],
    );
    expect($recipeText)->toBe('Geschmorte Kalbsbäckchen (Schmorgericht) Kalbsbäckchen Rotwein Wurzelgemüse');
});

it('normalisiert Query und Ziel in denselben Vektorraum (Symmetrie)', function () {
    // Der Kern des Slice-1-Fixes: dieselbe Funktion glättet beide Enden. Eine rohe
    // Query „Aubergine" und ein Ziel-Text „Aubergine · frisch" dürfen nach der
    // Normalisierung keine Struktur-Separatoren mehr tragen.
    expect(\Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::normalizeForEmbedding('Aubergine · frisch, ganz'))
        ->toBe('Aubergine frisch ganz')
        ->and(\Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::normalizeForEmbedding('  Beef  '))
        ->toBe('Beef');
});

it('ist idempotent — zweiter Lauf ohne Änderung legt nicht neu an', function () {
    ($this->mkGp)('Butter, gesalzen', $this->childA->id);

    $this->svc->embedGps();
    $row = ($this->gpVectors)()->first();
    expect($row)->not->toBeNull();

    $this->svc->embedGps();
    $after = ($this->gpVectors)()->where('id', $row->id)->first();

    expect(($this->gpVectors)()->count())->toBe(1)
        ->and($after->updated_at)->toBe($row->updated_at);
});

it('degradiert sauber ohne Provider — kein Vektor, kein Fehler', function () {
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, fn () => new EmbeddingProviderRegistry()); // leer
    $this->app->forgetInstance(EmbeddingService::class);
    $svc = app(PoolEmbeddingService::class);

    ($this->mkGp)('Salz, fein', $this->childA->id);

    expect($svc->isProviderAvailable())->toBeFalse()
        ->and($svc->embedGps()['available'])->toBeFalse()
        ->and(($this->gpVectors)()->count())->toBe(0);
});

it('löscht den GP-Vektor über deleteGp (Merge/Delete-Hygiene)', function () {
    $id = ($this->mkGp)('Schalotte, geschält', $this->childA->id);
    $this->svc->embedGps();
    expect(($this->gpVectors)()->count())->toBe(1);

    $this->svc->deleteGp($this->childA->id, $id, $this->childA->id);
    expect(($this->gpVectors)()->count())->toBe(0);
});

it('Observer queued Re-Embed nur bei embed-relevanter Änderung', function () {
    $gp = FoodAlchemistGp::create([
        'team_id' => $this->childA->id, 'gp_key' => 'pooltest|obs|x', 'name' => 'Möhre, frisch',
        'status' => 'approved', 'is_platzhalter' => false,
    ]);
    Queue::assertPushed(GenerateEmbeddingJob::class);            // create → embed

    Queue::fake();                                              // Zähler zurücksetzen
    $gp->update(['n_las_total' => 5]);                          // irrelevant für den Embed-Text
    Queue::assertNotPushed(GenerateEmbeddingJob::class);

    $gp->update(['name' => 'Möhre, geschält, gewürfelt']);      // relevant
    Queue::assertPushed(GenerateEmbeddingJob::class);
});

it('Observer löscht den Vektor beim Merge (Status-Austritt aus dem Pool)', function () {
    $lead = ($this->mkGp)('Tomate, frisch', $this->childA->id);
    $dup = FoodAlchemistGp::create([
        'team_id' => $this->childA->id, 'gp_key' => 'pooltest|dup|x', 'name' => 'Tomaten frisch',
        'status' => 'approved', 'is_platzhalter' => false,
    ]);
    $this->svc->embedGps();
    expect(($this->gpVectors)()->count())->toBe(2);

    // Merge: dup verlässt den Pool → Observer.saved → queueGp erkennt Austritt → deleteGp.
    $dup->update(['status' => 'merged', 'merged_into_id' => $lead]);

    expect(($this->gpVectors)()->where('entity_id', (string) $dup->id)->count())->toBe(0)
        ->and(($this->gpVectors)()->count())->toBe(1);
});
