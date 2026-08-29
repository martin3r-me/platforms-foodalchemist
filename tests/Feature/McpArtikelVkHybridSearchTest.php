<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ausbau (a): artikel.SEARCH + verkaufsrezepte.SEARCH auf Hybrid gehoben — sie tappen jetzt die
 * bereits vorhandenen Embedding-Pools (Lieferantenartikel bzw. Rezept, gefiltert auf VK) an.
 * Kernbeweis (Artikel): ein Token, das nur im klassifizierten Haupt-Slug steht (nicht in der
 * Designation), ist lexikalisch unsichtbar, aber semantisch findbar → via='semantic'.
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

it('artikel.SEARCH ist hybrid: findet LA semantisch, wenn das Token nur im Haupt-Slug steht', function () {
    $s = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $s->id,
        'designation' => 'Bio Hummus Paste 1kg Geb.', 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id,
        'main_ingredient_slug' => 'kichererbse', 'main_ingredient_display' => 'Kichererbse',
    ]);
    $stats = app(PoolEmbeddingService::class)->embedSupplierItems($this->rootTeam->id);
    expect($stats['available'])->toBeTrue()->and($stats['candidates'])->toBe(1);

    $res = ($this->run)('foodalchemist.artikel.SEARCH', ['q' => 'Kichererbse', 'limit' => 10]);
    expect($res->success)->toBeTrue('artikel: ' . ($res->error ?? ''));
    $hit = collect($res->data['artikel'])->firstWhere('id', $la->id);
    expect($hit)->not->toBeNull()
        ->and($hit['via'])->toBe('semantic');   // lexikalisch unsichtbar (Designation hat kein „Kichererbse")
});

it('verkaufsrezepte.SEARCH ist hybrid: lexical-Treffer trägt via-Feld, semantischer Pass läuft ohne Fehler', function () {
    FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'wiener', 'name' => 'DES: Wiener Schnitzel',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 12.5, 'yield_kg' => 1.0,
    ]);
    app(PoolEmbeddingService::class)->embedRecipes($this->rootTeam->id);

    $res = ($this->run)('foodalchemist.verkaufsrezepte.SEARCH', ['q' => 'Schnitzel', 'limit' => 10]);
    expect($res->success)->toBeTrue('vk: ' . ($res->error ?? ''))
        ->and($res->data['total'])->toBeGreaterThanOrEqual(1)
        ->and(collect($res->data['verkaufsrezepte'])->pluck('name')->all())->toContain('DES: Wiener Schnitzel')
        ->and($res->data['verkaufsrezepte'][0]['via'])->toBeIn(['lexical', 'semantic']);
});
