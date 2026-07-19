<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Platform\Core\Jobs\GenerateEmbeddingJob;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 15 §5a/§5b: die kleinen Geschwister-Pools (Lieferant/Konzept/Foodbook/
 * Lab-Note). Wie {@see PoolEmbeddingTest} prüft der FakeEmbeddingProvider nur die
 * VERDRAHTUNG (Embed-Text-Bau + Backfill + Team-Partition + Gates + Observer +
 * Delete-Hygiene), nicht die echte semantische Qualität.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', [
        '--realpath' => true,
        '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php'],
    ])->run();

    config([
        'embeddings.default_provider'            => 'fake',
        'foodalchemist.semantic_search.enabled'  => true,
        'foodalchemist.semantic_search.provider' => 'fake',
    ]);
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, function () {
        $r = new EmbeddingProviderRegistry();
        $r->register(new FakeEmbeddingProvider(256));

        return $r;
    });
    $this->app->forgetInstance(EmbeddingService::class);

    Queue::fake();

    $this->svc = app(PoolEmbeddingService::class);
    $this->vectors = fn (string $type) => DB::table('core_embeddings')->where('entity_type', $type);
});

// ── Embed-Text-Bau (Snapshot je Pool — Text-Drift = stiller Recall-Killer) ─────

it('baut Embed-Texte für alle vier Pools kompakt + normalisiert', function () {
    expect($this->svc->supplierEmbedText((object) ['name' => 'Hanos', 'branch' => 'Großhandel', 'city' => 'Venlo']))
        ->toBe('Hanos Großhandel Venlo');

    // leere Facetten fallen raus, Struktur-Separatoren werden geglättet
    expect($this->svc->conceptEmbedText((object) [
        'name' => 'Grill-Buffet', 'consumer_name' => 'Sommerfest', 'occasion' => 'Firmenfeier',
        'season' => 'Sommer', 'target_group' => '', 'description' => 'BBQ, Bowls & Sides',
    ]))->toBe('Grill-Buffet Sommerfest Firmenfeier Sommer BBQ Bowls & Sides');

    expect($this->svc->foodbookEmbedText((object) ['label' => 'Sommerkarte 2027', 'customer' => 'Broich', 'description' => null]))
        ->toBe('Sommerkarte 2027 Broich');

    expect($this->svc->labNoteEmbedText((object) ['title' => 'Miso-Karamell', 'body' => 'Umami-Süße-Achse getestet']))
        ->toBe('Miso-Karamell Umami-Süße-Achse getestet');
});

it('kürzt lange Freitext-Leads auf LEAD_MAX (kompakt, kein Vektor-Verwässern)', function () {
    $long = str_repeat('a', 900);
    $text = $this->svc->labNoteEmbedText((object) ['title' => 'T', 'body' => $long]);
    // "T " + 400 Zeichen Body
    expect(mb_strlen($text))->toBe(1 + 1 + 400);
});

// ── Backfill + Gates ───────────────────────────────────────────────────────────

it('embeddet Lieferanten und hält inaktive/soft-deleted draußen', function () {
    FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Hanos', 'branch' => 'GH', 'city' => 'Venlo']);
    FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Alt-Lieferant', 'is_inactive' => true]);
    $del = FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Weg', 'city' => 'X']);
    $del->delete();

    $stats = $this->svc->embedSuppliers();

    expect($stats['candidates'])->toBe(1)
        ->and(($this->vectors)(PoolEmbeddingService::ENTITY_TYPE_SUPPLIER)->count())->toBe(1);
});

it('embeddet Konzepte (soft-deleted raus) + legt globale unter dem Sentinel-Team ab', function () {
    FoodAlchemistConcept::create(['team_id' => null, 'name' => 'BHG-Vorlage', 'occasion' => 'Gala']);   // global
    FoodAlchemistConcept::create(['team_id' => $this->childA->id, 'name' => 'Grill-Buffet']);            // team-eigen
    FoodAlchemistConcept::create(['team_id' => $this->childA->id, 'name' => 'Papierkorb'])->delete();    // soft-deleted

    $stats = $this->svc->embedConcepts();
    $inTeam = fn (int $team) => ($this->vectors)(PoolEmbeddingService::ENTITY_TYPE_CONCEPT)->where('team_id', $team)->count();

    expect($stats['candidates'])->toBe(2)
        ->and($inTeam($this->svc->globalTeamId()))->toBe(1)
        ->and($inTeam($this->childA->id))->toBe(1);
});

it('embeddet Foodbooks und Lab-Notes', function () {
    FoodAlchemistFoodbook::create(['team_id' => $this->childA->id, 'label' => 'Sommerkarte']);
    FoodAlchemistLabNote::create(['team_id' => $this->childA->id, 'title' => 'Miso-Karamell', 'body' => 'Test']);

    expect($this->svc->embedFoodbooks()['candidates'])->toBe(1)
        ->and(($this->vectors)(PoolEmbeddingService::ENTITY_TYPE_FOODBOOK)->count())->toBe(1)
        ->and($this->svc->embedLabNotes()['candidates'])->toBe(1)
        ->and(($this->vectors)(PoolEmbeddingService::ENTITY_TYPE_LAB_NOTE)->count())->toBe(1);
});

// ── Observer + Delete + Graceful ────────────────────────────────────────────────

it('Observer queued Lieferant-Re-Embed nur bei embed-relevanter Änderung', function () {
    $s = FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Hanos']);
    Queue::assertPushed(GenerateEmbeddingJob::class);              // create → embed

    Queue::fake();
    $s->update(['payment_term_days' => 30]);                       // irrelevant für Embed-Text
    Queue::assertNotPushed(GenerateEmbeddingJob::class);

    $s->update(['city' => 'Venlo']);                              // relevant
    Queue::assertPushed(GenerateEmbeddingJob::class);
});

it('löscht den Vektor bei soft-delete (Observer → delete)', function () {
    $n = FoodAlchemistLabNote::create(['team_id' => $this->childA->id, 'title' => 'T', 'body' => 'B']);
    $this->svc->embedLabNotes();
    expect(($this->vectors)(PoolEmbeddingService::ENTITY_TYPE_LAB_NOTE)->count())->toBe(1);

    $n->delete();
    expect(($this->vectors)(PoolEmbeddingService::ENTITY_TYPE_LAB_NOTE)->count())->toBe(0);
});

it('degradiert sauber ohne Provider — kein Vektor, kein Fehler', function () {
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, fn () => new EmbeddingProviderRegistry());
    $this->app->forgetInstance(EmbeddingService::class);
    $svc = app(PoolEmbeddingService::class);

    FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Hanos']);

    expect($svc->embedSuppliers()['available'])->toBeFalse()
        ->and(($this->vectors)(PoolEmbeddingService::ENTITY_TYPE_SUPPLIER)->count())->toBe(0);
});
