<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Tools\GpsSearchTool;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * E4 (#507): MCP-Such-Tools hybridisiert (gps.SEARCH exemplarisch — recipes.SEARCH
 * und knowledge.SEARCH nutzen denselben semanticPoolIds-Helfer). Prüft: lexikalische
 * Treffer tragen via:lexical, der semantische Pass ergänzt via:semantic, und ohne
 * Provider bleibt es rein lexikalisch (graceful).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', ['--realpath' => true,
        '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php']])->run();

    $this->user = $this->makeUser($this->childA);

    $this->fakeProvider = function () {
        config([
            'embeddings.default_provider'                  => 'fake',
            'foodalchemist.semantic_search.enabled'        => true,
            'foodalchemist.semantic_search.provider'       => 'fake',
            'foodalchemist.semantic_search.pool_sem_floor' => 0.1,
        ]);
        $this->app->forgetInstance(EmbeddingProviderRegistry::class);
        $this->app->singleton(EmbeddingProviderRegistry::class, function () {
            $r = new EmbeddingProviderRegistry();
            $r->register(new FakeEmbeddingProvider(256));

            return $r;
        });
        $this->app->forgetInstance(EmbeddingService::class);
    };

    $this->mkGp = fn (string $name, array $extra = []) => FoodAlchemistGp::create(array_merge([
        'team_id' => $this->childA->id, 'gp_key' => 'e4|' . mb_strtolower(str_replace([' ', ','], ['-', ''], $name)),
        'name' => $name, 'status' => 'approved', 'is_platzhalter' => false,
    ], $extra));
});

it('gps.SEARCH ergänzt lexikalisch um semantische Treffer (via-Marker)', function () {
    ($this->fakeProvider)();
    ($this->mkGp)('Forelle', ['condition' => 'geräuchert']);   // "geräuchert" nur im Zustand → lexikalisch unsichtbar
    app(PoolEmbeddingService::class)->embedGps();

    $res = (new GpsSearchTool())->execute(['q' => 'geräuchert', 'limit' => 10], new ToolContext($this->user, $this->childA));
    $data = $res->toArray()['data'] ?? $res->toArray();
    $gps = collect($data['gps'] ?? []);

    $forelle = $gps->firstWhere('name', 'Forelle');
    expect($forelle)->not->toBeNull()
        ->and($forelle['via'])->toBe('semantic')
        ->and($forelle['semantic_score'])->toBeGreaterThan(0.0);
});

it('gps.SEARCH markiert lexikalische Treffer als via:lexical', function () {
    ($this->fakeProvider)();
    ($this->mkGp)('Rindfleisch, frisch');
    app(PoolEmbeddingService::class)->embedGps();

    $res = (new GpsSearchTool())->execute(['q' => 'Rindfleisch', 'limit' => 10], new ToolContext($this->user, $this->childA));
    $data = $res->toArray()['data'] ?? $res->toArray();
    $hit = collect($data['gps'] ?? [])->firstWhere('name', 'Rindfleisch, frisch');

    expect($hit)->not->toBeNull()->and($hit['via'])->toBe('lexical');
});

it('gps.SEARCH bleibt rein lexikalisch ohne Provider (graceful)', function () {
    // Kein Provider registriert → nur der Zustand-Token-Fall ist unauffindbar.
    ($this->mkGp)('Forelle', ['condition' => 'geräuchert']);

    $res = (new GpsSearchTool())->execute(['q' => 'geräuchert', 'limit' => 10], new ToolContext($this->user, $this->childA));
    $data = $res->toArray()['data'] ?? $res->toArray();

    expect(collect($data['gps'] ?? [])->pluck('name'))->not->toContain('Forelle');
});
