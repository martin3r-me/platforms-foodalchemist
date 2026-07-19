<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Tools\ConceptsSearchTool;
use Platform\FoodAlchemist\Tools\FoodbooksSearchTool;
use Platform\FoodAlchemist\Tools\LabNotesSearchTool;
use Platform\FoodAlchemist\Tools\SuppliersSearchTool;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Slice 1b (Spec 15 §5a): die kleinen Pools werden auch KONSUMIERT — Lieferanten-
 * Liste (UI-Service) + suppliers.SEARCH / concepts.SEARCH (MCP). Der
 * FakeEmbeddingProvider (Bag-of-Words) beweist die VERDRAHTUNG: ein Treffer, den die
 * Namens-Lexik verfehlt (Stadt/Facette statt Name), wird semantisch nachgezogen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', [
        '--realpath' => true,
        '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php'],
    ])->run();

    config([
        'embeddings.default_provider'                => 'fake',
        'foodalchemist.semantic_search.enabled'      => true,
        'foodalchemist.semantic_search.provider'     => 'fake',
        'foodalchemist.semantic_search.pool_sem_floor' => 0.01, // Fake-Bag-of-Words tief ansetzen
        'foodalchemist.semantic_search.master_team_id' => null,
    ]);
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, function () {
        $r = new EmbeddingProviderRegistry();
        $r->register(new FakeEmbeddingProvider(256));

        return $r;
    });
    $this->app->forgetInstance(EmbeddingService::class);
});

it('zieht in der Lieferanten-Liste semantische Treffer nach, die die Namens-Lexik verfehlt', function () {
    // Name "Hanos" matcht "Venlo" NICHT lexikalisch; der Embed-Text (Name+Branche+Stadt) schon.
    FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos', 'branch' => 'Großhandel', 'city' => 'Venlo']);
    app(PoolEmbeddingService::class)->embedSuppliers();

    $treffer = app(SupplierService::class)->listWithCounts($this->rootTeam, false, 'Venlo');
    expect($treffer->pluck('name'))->toContain('Hanos');
});

it('bleibt ohne Provider rein lexikalisch (kein semantischer Nachzug)', function () {
    FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos', 'branch' => 'GH', 'city' => 'Venlo']);
    config(['foodalchemist.semantic_search.enabled' => false]);

    $treffer = app(SupplierService::class)->listWithCounts($this->rootTeam, false, 'Venlo');
    expect($treffer)->toBeEmpty();
});

it('suppliers.SEARCH liefert semantische Treffer mit via-Marker', function () {
    FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos', 'branch' => 'Großhandel', 'city' => 'Venlo']);
    app(PoolEmbeddingService::class)->embedSuppliers();

    $ctx = new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam);
    $res = (new SuppliersSearchTool())->execute(['q' => 'Venlo'], $ctx);

    expect($res->success)->toBeTrue();
    $hit = collect($res->data['suppliers'])->firstWhere('name', 'Hanos');
    expect($hit)->not->toBeNull()
        ->and($hit['via'])->toBe('semantic');
});

it('concepts.SEARCH zieht ein Konzept über die Beschreibung nach (Lexik sucht nur Name+Anlass)', function () {
    // paginateBrowser durchsucht nur name+occasion → "Winterzauber" (nur in description) verfehlt es lexikalisch;
    // der Concept-Embed-Text enthält die Beschreibung → semantischer Nachzug.
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Grill-Buffet', 'description' => 'Winterzauber']);
    app(PoolEmbeddingService::class)->embedConcepts();

    $ctx = new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam);
    $res = (new ConceptsSearchTool())->execute(['q' => 'Winterzauber'], $ctx);

    expect($res->success)->toBeTrue();
    $hit = collect($res->data['concepts'])->firstWhere('name', 'Grill-Buffet');
    expect($hit)->not->toBeNull()
        ->and($hit['via'])->toBe('semantic');
});

it('foodbooks.SEARCH zieht über die Beschreibung nach (Lexik sucht Label+Kunde)', function () {
    FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Sommerkarte', 'customer' => 'Broich', 'description' => 'Winterzauber']);
    app(PoolEmbeddingService::class)->embedFoodbooks();

    $ctx = new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam);
    $res = (new FoodbooksSearchTool())->execute(['q' => 'Winterzauber'], $ctx);

    expect($res->success)->toBeTrue();
    $hit = collect($res->data['foodbooks'])->firstWhere('label', 'Sommerkarte');
    expect($hit)->not->toBeNull()
        ->and($hit['via'])->toBe('semantic');
});

it('lab_notes.SEARCH zieht über den Body nach (Lexik sucht nur Titel)', function () {
    FoodAlchemistLabNote::create(['team_id' => $this->rootTeam->id, 'title' => 'Notiz A', 'body' => 'Umami-Karamell-Achse']);
    app(PoolEmbeddingService::class)->embedLabNotes();

    $ctx = new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam);
    $res = (new LabNotesSearchTool())->execute(['q' => 'Umami-Karamell-Achse'], $ctx);

    expect($res->success)->toBeTrue();
    $hit = collect($res->data['lab_notes'])->firstWhere('title', 'Notiz A');
    expect($hit)->not->toBeNull()
        ->and($hit['via'])->toBe('semantic');
});
