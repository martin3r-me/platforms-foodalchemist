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
        // Auf „es gibt einen Fehler" prüfen, nicht auf den Wortlaut: der Satz ist
        // Oberflächentext und ändert sich (2026-09-11: „nur das Besitzer-Team" →
        // „Besitzer bzw. Master-Team", weil global jetzt einen Eigentümer hat). Der Vertrag
        // ist editable=false + Fehler gesetzt + Doc unangetastet, nicht die Formulierung.
        ->assertSet('fehler', fn ($f) => $f !== null && $f !== '');

    // Doc bleibt unangetastet.
    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $id)->whereNull('deleted_at')->exists())->toBeTrue();
});

it('öffnet ein Doc per Deep-Link (?doc=) ohne 500 — mount() befüllt das Form', function () {
    $id = DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'regelwerk.deeplink', 'title' => 'Deeplink Doc',
        'category' => 'regelwerk', 'content_md' => "# Deeplink\nInhalt.\n", 'version' => 1,
        'content_hash' => hash('sha256', 'dl'), 'char_count' => 14, 'active' => 1,
        'team_id' => $this->rootTeam->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // #[Url(as: 'doc')] hydratisiert selectedId aus der Query — ohne mount() bliebe $form
    // leer und der Editor-Render kippte an $form['title'] (Undefined array key → 500).
    Livewire::withQueryParams(['doc' => $id])
        ->test(Browser::class)
        ->assertOk()
        ->assertSet('selectedId', $id)
        ->assertSet('form.title', 'Deeplink Doc')
        ->assertSee('Deeplink Doc');
});

/**
 * Spec 52/A4 — der Chip-Hinweis für `cross_cutting` behauptete bei 158 von 165 Dossiers, die
 * Laufzeit lade „nur die 7 Kern-Files". Dreifach falsch: die 7 Originale sind seit Welle 2
 * deaktiviert, die Generatoren ziehen die Kategorie per `discovery` über den ganzen Korpus,
 * und der Rat „binde es an einen Einsatzort" führte in die Alt-Struktur, die bei Prompt-Keys
 * mit Kanon stumm ist. Der Hinweis darf nur noch erscheinen, wenn er zutrifft.
 */
it('warnt NICHT bei cross_cutting, wenn die Kategorie per discovery gezogen wird', function () {
    $id = ($this->mkDoc)('menue_architektur--gaenge-logik', 'cross_cutting', 'Gänge-Logik', "# Gänge\nText.\n");
    DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
        ['feature' => 'ai_generate_recipe', 'category' => 'cross_cutting'],
        ['mode' => 'discovery', 'max_docs' => 6, 'max_chars_per_doc' => 8000, 'created_at' => now(), 'updated_at' => now()],
    );

    Livewire::test(Browser::class)
        ->set('selectedId', $id)
        ->assertViewHas('autoGeladen', true);
});

it('warnt bei cross_cutting nur, wenn ausschliesslich always-Routen greifen und der Slug fehlt', function () {
    // Nur `always`, und das Dossier steht in keiner aufgelösten Slug-Liste → der Hinweis ist
    // berechtigt. Er nennt jetzt aber das FEATURE und den Weg (Kanon-Zeile), statt eine
    // 7er-Liste zu behaupten, die es nicht mehr gibt.
    $id = ($this->mkDoc)('irgendein-cc-doc', 'cross_cutting', 'Irgendwas', "# Irgendwas\nText.\n");
    DB::table('foodalchemist_knowledge_routings')->where('category', 'cross_cutting')->delete();
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'concept.wording', 'category' => 'cross_cutting', 'mode' => 'always',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(Browser::class)
        ->set('selectedId', $id)
        ->assertViewHas('autoGeladen', false)
        ->assertViewHas('ccAlwaysFeatures', fn ($f) => in_array('concept.wording', $f, true))
        ->assertSee('Kanon-Zeile');
});

/**
 * Spec 52 — die Rückwärts-Sicht fragte bis 2026-09-11 die abgeschafften Bindungen.
 *
 * Sie bot „Einsatzorte" aus `knowledge_layers` an und zeigte, was per
 * `knowledge_bindings` daran hing. Beides ist seit Paket 3 wirkungslos: die Laufzeit
 * liest keine Bindung mehr. Gemessen auf demo waren es neun Zeilen, die nichts
 * steuerten — und anders als der Block darüber sagte die Fläche das nicht.
 *
 * Die FRAGE bleibt richtig, die Quelle war falsch: jetzt der Kanon.
 */
it('Spec 52: die Rückwärts-Sicht bietet Arbeitsschritte MIT KANON an, keine Einsatzorte', function () {
    $id = ($this->mkDoc)('rw-trace', 'regelwerk', 'Regelwerk §2', 'Inhalt.');
    app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)->set($this->rootTeam, [
        'scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'slug' => 'rw-trace', 'mode' => 'pflicht',
    ]);

    Livewire::test(Browser::class)
        ->assertViewHas('traceKeys', fn ($k) => in_array('recipe.generator', $k->all(), true))
        ->set('traceTarget', 'recipe.generator')
        ->assertViewHas('traceResults', fn ($r) => $r->pluck('title')->contains('Regelwerk §2'));
});

it('Spec 52: eine Alt-Bindung taucht in der Rückwärts-Sicht NICHT mehr auf', function () {
    $id = ($this->mkDoc)('rw-alt', 'regelwerk', 'Nur gebunden, kein Kanon', 'Inhalt.');
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'knowledge_document_id' => $id, 'binding_type' => 'layer', 'target_key' => 'recipe.generator',
        'mode' => 'always', 'weight' => 0, 'source' => 'test', 'active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(Browser::class)
        ->set('traceTarget', 'recipe.generator')
        // Ohne Kanon-Zeile ist das Dossier fuer diesen Schritt NICHT verbindlich —
        // die Bindung allein darf es nicht mehr erscheinen lassen.
        ->assertViewHas('traceResults', fn ($r) => ! $r->pluck('title')->contains('Nur gebunden, kein Kanon'));
});
