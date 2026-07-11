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
 * Embedding-Integration (Core 32b66074): Hybrid-Recall über Cores
 * EmbeddingService. Der FakeEmbeddingProvider erzeugt deterministische
 * Bag-of-Words-Vektoren (kein HTTP) — das prüft die VERDRAHTUNG (Service +
 * Store + Hybrid-Gate + global_team_id-Sentinel), nicht die echte semantische
 * Qualität (die hängt an der Live-API → Post-Deploy-Validierung).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();                                       // Core-Prereqs + alle Modul-Migrationen

    // core_embeddings ist NICHT im selektiven Core-Satz von seedTeamHierarchy → nachziehen.
    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', [
        '--realpath' => true,
        '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php'],
    ])->run();

    // Fake-Provider als Default registrieren (deterministisch, kein API-Key).
    config([
        'embeddings.default_provider'             => 'fake',
        'foodalchemist.semantic_search.enabled'   => true,
        'foodalchemist.semantic_search.provider'  => 'fake',
        'foodalchemist.semantic_search.min_score' => 0.01,            // > 0 ⇒ Null-Overlap-Docs raus
    ]);
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, function () {
        $r = new EmbeddingProviderRegistry();
        $r->register(new FakeEmbeddingProvider(256));
        return $r;
    });
    $this->app->forgetInstance(EmbeddingService::class);

    $this->svc = app(KnowledgeEmbeddingService::class);

    $this->mkDoc = function (string $slug, string $kategorie, string $titel, string $inhalt, ?int $teamId = null): int {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $titel,
            'category' => $kategorie, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $inhalt), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'team_id' => $teamId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
});

it('embeddet Pairing-Docs und rankt das semantisch passende zuerst', function () {
    ($this->mkDoc)('pairing.trueffel', 'pairing', 'Trüffel', "# Trüffel\n## Pairings\n[[Pasta]] · [[Sellerie]]\n## Notizen\nx\n");
    ($this->mkDoc)('pairing.schokolade', 'pairing', 'Schokolade', "# Schokolade\n## Pairings\n[[Chili]] · [[Orange]]\n## Notizen\nx\n");

    $stats = $this->svc->embedCorpus(['pairing']);

    expect($stats['available'])->toBeTrue()
        ->and($stats['kategorien']['pairing'])->toBe(2)
        ->and(DB::table('core_embeddings')->where('entity_type', KnowledgeEmbeddingService::ENTITY_TYPE)->count())->toBe(2);

    // Treffer kommen OHNE "pairing."-Präfix zurück (Stem-Form), bestes Match zuerst.
    $hits = $this->svc->searchSlugs('Hauptgericht mit Pasta und Sellerie', ['pairing'], 2);

    expect($hits)->not->toBeEmpty()
        ->and($hits[0])->toBe('trueffel')
        ->and($hits)->not->toContain('schokolade');
});

it('durchsucht den ganzen Korpus kategorieübergreifend (Browser-Semantiksuche, searchDocIds + Default alle Kategorien)', function () {
    $rw = ($this->mkDoc)('regelwerk.pfeffer', 'regelwerk', 'Pfeffer-Regel', "# Pfeffer\nSchwarzer Pfeffer, Grundprodukt-Naming und Zuschnitt.\n");
    ($this->mkDoc)('zitrus', 'domain', 'Zitrus', "# Zitrus\nOrange, Zitrone, Limette.\n");

    // embedCorpus() ohne Argument = ALLE indizierbaren Kategorien (nicht nur domain/pairing).
    $stats = $this->svc->embedCorpus();
    expect($stats['available'])->toBeTrue()
        ->and(array_keys($stats['kategorien']))->toContain('regelwerk', 'domain')
        ->and($this->svc->indexableKategorien())->toContain('regelwerk', 'domain');

    // searchDocIds gibt geordnete Doc-IDs quer über alle Kategorien zurück.
    $ids = $this->svc->searchDocIds('Pfeffer Naming Grundprodukt Zuschnitt', 5);
    expect($ids)->not->toBeEmpty()->and($ids[0])->toBe($rw);
});

it('findet ein Domain-Doc über einen Token im Inhalt, den der Slug nicht trägt', function () {
    // "topinambur" steht NUR im Inhalt — die Lexik (Slug/Titel/Alias) würde es verfehlen.
    ($this->mkDoc)('wurzelgemuese', 'domain', 'Wurzelgemüse', "# Wurzelgemüse\nTopinambur, Pastinake, Petersilienwurzel.\n");
    ($this->mkDoc)('zitrus', 'domain', 'Zitrus', "# Zitrus\nOrange, Zitrone, Limette.\n");

    $this->svc->embedCorpus(['domain']);

    $hits = $this->svc->searchSlugs('Topinambur Velouté', ['domain'], 2);

    expect($hits)->toContain('wurzelgemuese')
        ->and($hits)->not->toContain('zitrus');
});

it('verdrahtet den semantischen Fallback in discoverDomains (über contextFor)', function () {
    // Routing wie der Generator; nur Domain-Discovery relevant.
    DB::table('foodalchemist_knowledge_routings')->insert([
        ['feature' => 'ai_generate_recipe', 'category' => 'domain', 'mode' => 'discovery', 'created_at' => now(), 'updated_at' => now()],
    ]);
    ($this->mkDoc)('wurzelgemuese', 'domain', 'Wurzelgemüse', "# Wurzelgemüse\nTopinambur, Pastinake.\n");
    $this->svc->embedCorpus(['domain']);

    // "topinambur" matcht lexikalisch NICHT auf Slug "wurzelgemuese" → nur Semantik findet es.
    $ctx = app(KnowledgeContextService::class)->contextFor('ai_generate_recipe', 'Topinambur Velouté');

    expect($ctx['block'])->toContain('## DOMAIN: wurzelgemuese');
});

it('lässt die Lexik unangetastet, wenn das Flag aus ist (kein Embedding-Zugriff)', function () {
    config(['foodalchemist.semantic_search.enabled' => false]);
    ($this->mkDoc)('wurzelgemuese', 'domain', 'Wurzelgemüse', "# Wurzelgemüse\nTopinambur.\n");
    $this->svc->embedCorpus(['domain']);

    DB::table('foodalchemist_knowledge_routings')->insert([
        ['feature' => 'ai_generate_recipe', 'category' => 'domain', 'mode' => 'discovery', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $ctx = app(KnowledgeContextService::class)->contextFor('ai_generate_recipe', 'Topinambur Velouté');

    expect($this->svc->searchEnabled())->toBeFalse()
        ->and($ctx['block'])->not->toContain('## DOMAIN: wurzelgemuese');   // rein lexikalisch: kein Treffer
});

it('degradiert sauber ohne Provider — leere Treffer, kein Fehler', function () {
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, fn () => new EmbeddingProviderRegistry()); // leer
    $this->app->forgetInstance(EmbeddingService::class);

    expect($this->svc->isProviderAvailable())->toBeFalse()
        ->and($this->svc->searchEnabled())->toBeFalse()
        ->and($this->svc->searchSlugs('Trüffel', ['pairing'], 3))->toBe([])
        ->and($this->svc->embedCorpus(['pairing'])['available'])->toBeFalse();
});

it('ist idempotent — zweiter Lauf ohne Inhaltsänderung legt nicht neu an', function () {
    ($this->mkDoc)('pairing.trueffel', 'pairing', 'Trüffel', "# Trüffel\n## Pairings\n[[Pasta]]\n## Notizen\nx\n");

    $this->svc->embedCorpus(['pairing']);
    $row = DB::table('core_embeddings')->where('entity_type', KnowledgeEmbeddingService::ENTITY_TYPE)->first();
    expect($row)->not->toBeNull();

    $this->svc->embedCorpus(['pairing']);                            // Core überspringt (source_hash gleich)
    $after = DB::table('core_embeddings')->where('id', $row->id)->first();

    expect(DB::table('core_embeddings')->where('entity_type', KnowledgeEmbeddingService::ENTITY_TYPE)->count())->toBe(1)
        ->and($after->updated_at)->toBe($row->updated_at);
});

it('löst einen Anker semantisch über das Vokabular auf (B) — Schwelle hält Falsch-Treffer raus', function () {
    $mkAnker = function (string $slug, string $display): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $display,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $portwein = $mkAnker('portwein', 'Portwein');
    $mkAnker('rotwein', 'Rotwein');
    $mkAnker('apfel', 'Apfel');

    $anker = $this->svc->embedAnkers();

    expect($anker['available'])->toBeTrue()
        ->and($anker['candidates'])->toBe(3)
        ->and(DB::table('core_embeddings')->where('entity_type', KnowledgeEmbeddingService::ENTITY_TYPE_ANKER)->count())->toBe(3);

    // "Portwein weiss, 19 Vol%" teilt das Token 'portwein' → Portwein-Anker.
    expect($this->svc->resolveAnkerId('Portwein weiss, 19 Vol%', 0.0))->toBe($portwein);
    // Kein gemeinsames Token + hohe Schwelle → null (lieber „unbekannt" als falsch).
    expect($this->svc->resolveAnkerId('Schnittlauch frisch', 0.9))->toBeNull();
});
