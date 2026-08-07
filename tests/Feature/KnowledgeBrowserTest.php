<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #469 Wissens-Browser — Semantiksuche (Embedding-Recall statt SQL-LIKE) mit
 * FakeEmbeddingProvider (deterministisch, kein API-Key). Prüft: Ranking nach
 * Relevanz + graceful Fallback auf die Textsuche, wenn kein Provider da ist.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // core_embeddings nachziehen (nicht im selektiven Core-Satz von seedTeamHierarchy).
    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', [
        '--realpath' => true,
        '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php'],
    ])->run();

    config([
        'embeddings.default_provider'             => 'fake',
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

    $this->mkDoc = function (string $slug, string $kategorie, string $titel, string $inhalt): int {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $titel,
            'category' => $kategorie, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $inhalt), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'team_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };

    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('rankt bei aktivem Semantik-Modus nach Relevanz und ignoriert den LIKE-Filter', function () {
    $treffer = ($this->mkDoc)('regelwerk.pfeffer', 'regelwerk', 'Pfeffer-Regel', "# Pfeffer\nSchwarzer Pfeffer, Grundprodukt-Naming und Zuschnitt.\n");
    ($this->mkDoc)('zitrus', 'domain', 'Zitrus', "# Zitrus\nOrange, Zitrone, Limette.\n");

    app(KnowledgeEmbeddingService::class)->embedCorpus();

    Livewire::test(Browser::class)
        ->set('semantic', true)
        ->set('search', 'Pfeffer Naming Grundprodukt Zuschnitt')
        ->assertViewHas('semanticAktiv', true)
        ->assertViewHas('semanticNote', null)
        ->assertViewHas('docs', fn ($docs) => $docs->isNotEmpty() && (int) $docs->first()->id === $treffer);
});

it('degradiert ohne Provider sauber: Hinweis + Fallback auf die Textsuche', function () {
    ($this->mkDoc)('regelwerk.pfeffer', 'regelwerk', 'Pfeffer-Regel', "# Pfeffer\nGrundprodukt-Naming.\n");

    // Provider entfernen → kein Embedding verfügbar.
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, fn () => new EmbeddingProviderRegistry());
    $this->app->forgetInstance(EmbeddingService::class);

    Livewire::test(Browser::class)
        ->set('semantic', true)
        ->set('search', 'Pfeffer')
        ->assertViewHas('semanticAktiv', false)
        ->assertViewHas('semanticNote', fn ($n) => $n !== null && str_contains($n, 'nicht verfügbar'))
        ->assertViewHas('docs', fn ($docs) => $docs->contains(fn ($d) => $d->slug === 'regelwerk.pfeffer'));
});

it('löscht ein eigenes Dokument endgültig (Hard-Delete) samt Aliasen/Bindungen (FK-Cascade)', function () {
    // Eigenes Doc des aktiven Teams (rootTeam) — nicht ($this->mkDoc), das legt global (team_id NULL) an.
    $id = DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'regelwerk.eigen', 'title' => 'Eigenes Doc',
        'category' => 'regelwerk', 'content_md' => "# Eigen\nInhalt.\n", 'version' => 1,
        'content_hash' => hash('sha256', 'x'), 'char_count' => 12, 'active' => 1,
        'team_id' => $this->rootTeam->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Kind-Zeilen anhängen, um den FK-Cascade zu belegen.
    DB::table('foodalchemist_knowledge_aliases')->insert([
        'alias_slug' => 'eigen_alias', 'knowledge_document_id' => $id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'knowledge_document_id' => $id, 'binding_type' => 'layer', 'target_key' => 'gp',
        'mode' => 'discovery', 'weight' => 0, 'active' => 1, 'source' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(Browser::class)
        ->call('select', $id)
        ->assertSet('selectedId', $id)
        ->assertViewHas('editable', true)          // Besitzer → Löschen-Button sichtbar
        ->call('delete', $id)
        ->assertSet('selectedId', null)            // Auswahl abgeräumt
        ->assertSet('fehler', null)
        ->assertViewHas('docs', fn ($docs) => $docs->doesntContain(fn ($d) => $d->id === $id));

    // Hard-Delete: die Zeile ist WEG (nicht nur deleted_at gesetzt).
    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $id)->exists())->toBeFalse();
    // Cascade: Kind-Zeilen mitgenommen.
    expect(DB::table('foodalchemist_knowledge_aliases')->where('knowledge_document_id', $id)->exists())->toBeFalse();
    expect(DB::table('foodalchemist_knowledge_bindings')->where('knowledge_document_id', $id)->exists())->toBeFalse();
});

it('lehnt das Löschen von geerbtem/globalem Wissen ab (read-only für Nicht-Besitzer)', function () {
    $id = ($this->mkDoc)('regelwerk.global', 'regelwerk', 'Globales Doc', "# Global\nMaster-Wissen.\n");

    // Als Kind-Team handeln: das globale/Master-Doc ist sichtbar, aber nicht editierbar.
    $this->actingAs($this->makeUser($this->childA, 'Kind A User'));

    Livewire::test(Browser::class)
        ->call('select', $id)
        ->assertViewHas('editable', false)         // kein Besitz → kein Löschen-Button
        ->call('delete', $id)
        ->assertSet('fehler', fn ($f) => $f !== null && str_contains($f, 'Besitzer-Team'));

    // Doc bleibt unangetastet.
    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $id)->whereNull('deleted_at')->exists())->toBeTrue();
});
