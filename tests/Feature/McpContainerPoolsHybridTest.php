<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ausbau (b): neue Embedding-Pools + Hybrid-SEARCH für Speisekarte/Angebot/Paket/Format.
 * Beweis (Speisekarte): ein Token, das nur in der Beschreibung steht (die Lexik durchsucht
 * nur name/code), ist lexikalisch unsichtbar, aber via Embed-Text semantisch findbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', ['--realpath' => true, '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php']])->run();

    config([
        'embeddings.default_provider' => 'fake',
        'foodalchemist.semantic_search.enabled' => true,
        'foodalchemist.semantic_search.provider' => 'fake',
        'foodalchemist.semantic_search.pool_sem_floor' => 0.1,
    ]);
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, function () {
        $r = new EmbeddingProviderRegistry();
        $r->register(new FakeEmbeddingProvider(256));

        return $r;
    });
    $this->app->forgetInstance(EmbeddingService::class);

    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a) => $this->registry->get($n)->execute($a, $this->kontext);
});

it('Speisekarte-Pool: findet semantisch, wenn das Token nur in der Beschreibung steht', function () {
    app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Sommerkarte', 'description' => 'mediterrane Vorspeisen und Grillgerichte']);
    $stats = app(PoolEmbeddingService::class)->embedSpeisekarten($this->rootTeam->id);
    expect($stats['available'])->toBeTrue()->and($stats['candidates'])->toBeGreaterThanOrEqual(1);

    // „mediterrane" steht nicht im Namen/Code → lexikalisch unsichtbar, semantisch findbar.
    $res = ($this->run)('foodalchemist.speisekarten.SEARCH', ['q' => 'mediterrane', 'limit' => 10]);
    expect($res->success)->toBeTrue('sk: ' . ($res->error ?? ''));
    $hit = collect($res->data['speisekarten'])->firstWhere('name', 'Sommerkarte');
    expect($hit)->not->toBeNull()->and($hit['via'])->toBe('semantic');
});

it('Angebot/Paket/Format: Pools embedden + Hybrid-SEARCH findet den Datensatz', function () {
    app(AngebotService::class)->create($this->rootTeam, ['name' => 'Galadinner Herbst']);
    app(PaketService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet Deluxe']);
    app(FormatService::class)->create($this->rootTeam, ['name' => 'Urban Fusion Hub']);

    $pools = app(PoolEmbeddingService::class);
    expect($pools->embedAngebote($this->rootTeam->id)['candidates'])->toBeGreaterThanOrEqual(1)
        ->and($pools->embedPakete($this->rootTeam->id)['candidates'])->toBeGreaterThanOrEqual(1)
        ->and($pools->embedFormate($this->rootTeam->id)['candidates'])->toBeGreaterThanOrEqual(1);

    $ang = ($this->run)('foodalchemist.angebote.SEARCH', ['q' => 'Galadinner']);
    expect($ang->success)->toBeTrue('ang: ' . ($ang->error ?? ''))
        ->and(collect($ang->data['angebote'])->pluck('name')->all())->toContain('Galadinner Herbst');

    $pak = ($this->run)('foodalchemist.pakete.SEARCH', ['query' => 'Grill-Buffet']);
    expect($pak->success)->toBeTrue('pak: ' . ($pak->error ?? ''))
        ->and(collect($pak->data['pakete'])->pluck('name')->all())->toContain('Grill-Buffet Deluxe');

    $fmt = ($this->run)('foodalchemist.formats.SEARCH', ['query' => 'Urban Fusion']);
    expect($fmt->success)->toBeTrue('fmt: ' . ($fmt->error ?? ''))
        ->and(collect($fmt->data['formats'])->pluck('name')->all())->toContain('Urban Fusion Hub');
});
