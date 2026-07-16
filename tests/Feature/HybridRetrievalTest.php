<?php

use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * E2 (#507): Hybrider Retrieval-Layer in candidatesFor. Der FakeEmbeddingProvider
 * (deterministische Bag-of-Words) prüft die VERDRAHTUNG — additive Shortlist,
 * Herkunfts-Marker, Multi-Partition-Merge, graceful Fallback, und dass die
 * deterministische Match-ENTSCHEIDUNG (matchIngredient) unberührt bleibt.
 *
 * Kern-Fall „Semantik sieht, was die Lexik nicht sieht": Der LIKE-Vorfilter der
 * Lexik durchsucht NUR name/slug/display — NICHT den Zustand. Ein Query-Token,
 * das nur im Zustand steht, ist lexikalisch unsichtbar, aber im Embed-Text
 * ("Name · Zustand · WG") enthalten → semantisch findbar. Das ist echter Recall-
 * Gewinn, kein Fake-Artefakt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', [
        '--realpath' => true,
        '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php'],
    ])->run();

    $this->enableSemantic = function (bool $on = true) {
        config([
            'embeddings.default_provider'                => 'fake',
            'foodalchemist.semantic_search.enabled'      => $on,
            'foodalchemist.semantic_search.provider'     => 'fake',
            'foodalchemist.semantic_search.pool_sem_floor' => 0.1,   // Wiring-Test, keine Kalibrierung
        ]);
        $this->app->forgetInstance(EmbeddingProviderRegistry::class);
        $this->app->singleton(EmbeddingProviderRegistry::class, function () {
            $r = new EmbeddingProviderRegistry();
            $r->register(new FakeEmbeddingProvider(256));

            return $r;
        });
        $this->app->forgetInstance(EmbeddingService::class);
        $this->app->forgetInstance(IngredientMatchService::class);
    };

    $this->mkGp = function (string $name, ?int $teamId, array $extra = []): int {
        return (int) FoodAlchemistGp::create(array_merge([
            'team_id' => $teamId,
            'gp_key' => 'hybtest|' . mb_strtolower(str_replace([' ', ','], ['-', ''], $name)),
            'name' => $name, 'status' => 'approved', 'is_platzhalter' => false,
        ], $extra))->id;
    };
});

it('lässt candidatesFor byte-identisch, wenn Semantik AUS ist (nur Marker additiv)', function () {
    ($this->enableSemantic)(false);
    ($this->mkGp)('Rindfleisch, frisch', $this->childA->id);

    $out = app(IngredientMatchService::class)->candidatesFor($this->childA, 'Rindfleisch', null, 5);

    expect($out)->not->toBeEmpty()
        ->and($out[0]['kind'])->toBe('gp')
        ->and($out[0]['name'])->toBe('Rindfleisch, frisch')
        ->and($out[0]['origin'])->toBe('lexical')
        ->and($out[0]['score'])->toBeGreaterThan(0.5);   // KEINE ×0.5-Abwertung im Legacy-Pfad
});

it('findet semantisch, was der lexikalische LIKE-Vorfilter verfehlt (Zustand-Token)', function () {
    ($this->enableSemantic)(true);
    // "geräuchert" steht NUR im Zustand → nicht im LIKE-Vorfilter (name/slug/display).
    ($this->mkGp)('Forelle', $this->childA->id, ['condition' => 'geräuchert']);
    ($this->mkGp)('Kartoffel', $this->childA->id, ['condition' => 'frisch']);   // Distraktor

    app(PoolEmbeddingService::class)->embedGps();

    // exakte Token-Form (Fake-BoW stemmt nicht — der echte Embedder schon).
    $out = app(IngredientMatchService::class)->candidatesFor($this->childA, 'geräuchert', null, 5);
    $names = collect($out)->pluck('name');

    expect($names)->toContain('Forelle')
        ->and($names)->not->toContain('Kartoffel');
    $forelle = collect($out)->firstWhere('name', 'Forelle');
    expect($forelle['origin'])->toBe('semantic')
        ->and($forelle['reference'])->toStartWith('gp:');
});

it('markiert lexikalisch UND semantisch gefundene Kandidaten als "both" (Cosine-Boost)', function () {
    ($this->enableSemantic)(true);
    ($this->mkGp)('Rindfleisch, frisch', $this->childA->id);
    app(PoolEmbeddingService::class)->embedGps();

    $out = app(IngredientMatchService::class)->candidatesFor($this->childA, 'Rindfleisch Rouladen', null, 5);
    $hit = collect($out)->firstWhere('name', 'Rindfleisch, frisch');

    expect($hit)->not->toBeNull()
        ->and($hit['origin'])->toBe('both');
});

it('merged über Partitionen: globales (Sentinel) GP wird für ein Kind-Team gefunden', function () {
    ($this->enableSemantic)(true);
    ($this->mkGp)('Olivenöl', null, ['condition' => 'nativ']);   // global (team_id NULL)
    app(PoolEmbeddingService::class)->embedGps();

    $out = app(IngredientMatchService::class)->candidatesFor($this->childA, 'Olivenöl', null, 5);

    expect(collect($out)->pluck('name'))->toContain('Olivenöl');
});

it('degradiert graceful auf Lexik, wenn kein Provider da ist', function () {
    ($this->enableSemantic)(true);
    ($this->mkGp)('Butter', $this->childA->id);

    // Provider entfernen → enabled() false → rein lexikalisch, kein Fehler.
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, fn () => new EmbeddingProviderRegistry());
    $this->app->forgetInstance(EmbeddingService::class);
    $this->app->forgetInstance(IngredientMatchService::class);

    $out = app(IngredientMatchService::class)->candidatesFor($this->childA, 'Butter', null, 5);

    expect(collect($out)->pluck('name'))->toContain('Butter')
        ->and($out[0]['origin'])->toBe('lexical');
});
