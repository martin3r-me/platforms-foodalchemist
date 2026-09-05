<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 Strang III — Kanon auf DOSSIERS (nicht Sections): Packliste je Feature/Prompt-Key,
 * Tenancy-Invariante (globale Zeile → nur globale Dossiers; Team-Zeile → globale + eigene;
 * Fremd-Team unsichtbar), Changelog-Guard, Größen-Hinweis, documentsFor-Vertrag für den Peer.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->svc = app(KnowledgeCanonService::class);

    $this->mkDoc = function (string $slug, ?int $teamId, string $inhalt = "# T\n\nInhalt.", string $kategorie = 'regelwerk', bool $aktiv = true): int {
        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug, 'title' => 'Titel '.$slug,
            'category' => $kategorie, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $inhalt), 'char_count' => mb_strlen($inhalt),
            'active' => $aktiv ? 1 : 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('registriert knowledge_canon.GET/PUT/DELETE', function () {
    foreach (['GET', 'PUT', 'DELETE'] as $op) {
        expect($this->registry->get("foodalchemist.knowledge_canon.{$op}"))->not->toBeNull();
    }
});

it('hat die Kanon-Tabelle auf Dossiers umgestellt (knowledge_document_id statt Section)', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_knowledge_canon', 'knowledge_document_id'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_knowledge_canon', 'knowledge_section_id'))->toBeFalse();
});

it('setzt eine Team-Kanon-Zeile per PUT und liefert sie in documentsFor in ord-Reihenfolge', function () {
    ($this->mkDoc)('rw_p1', null, "# §1\n\nNaming.");
    ($this->mkDoc)('rw_p2', (int) $this->rootTeam->id, "# §2\n\nReduktion.");

    $put = $this->registry->get('foodalchemist.knowledge_canon.PUT');
    $a = $put->execute(['scope' => 'feature', 'scope_key' => 'ai_generate_recipe', 'slug' => 'rw_p2', 'ord' => 20], $this->kontext);
    $b = $put->execute(['scope' => 'feature', 'scope_key' => 'ai_generate_recipe', 'slug' => 'rw_p1', 'ord' => 10, 'mode' => 'wenn_platz'], $this->kontext);

    expect($a->success)->toBeTrue()->and($b->success)->toBeTrue()
        ->and($a->data['canon']['global'])->toBeFalse()
        ->and($a->data['hinweise'])->toBe([]);

    $docs = $this->svc->documentsFor('feature', 'ai_generate_recipe', $this->rootTeam);
    expect($docs->pluck('slug')->all())->toBe(['rw_p1', 'rw_p2'])
        ->and($docs->first()->mode)->toBe('wenn_platz')
        ->and($docs->first()->content_md)->toContain('Naming')
        ->and($docs->first())->toHaveProperties(['document_id', 'slug', 'title', 'category', 'content_md', 'mode', 'version', 'char_count']);

    // Anderer Scope-Key → leer = kein Kanon (Peer fällt auf always-Docs zurück).
    expect($this->svc->documentsFor('feature', 'vk.generator', $this->rootTeam))->toBeEmpty()
        ->and($this->svc->hasCanon('feature', 'vk.generator', $this->rootTeam))->toBeFalse();
});

it('PUT ist Upsert (gleiches Dossier → ord/mode aktualisiert, keine zweite Zeile)', function () {
    ($this->mkDoc)('rw_x', null);
    $put = $this->registry->get('foodalchemist.knowledge_canon.PUT');
    $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_x', 'ord' => 10], $this->kontext);
    $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_x', 'ord' => 5, 'mode' => 'wenn_platz'], $this->kontext);

    $rows = DB::table('foodalchemist_knowledge_canon')->where('scope_key', 'f')->get();
    expect($rows)->toHaveCount(1)->and((int) $rows[0]->ord)->toBe(5)->and($rows[0]->mode)->toBe('wenn_platz');
});

it('lehnt ein Kanon-Dossier mit Changelog-Überschrift ab (Kurationsregel)', function () {
    ($this->mkDoc)('rw_cl', null, "# §3\n\nRegel.\n\n## Changelog\n\n- v1.1 …");
    $res = $this->registry->get('foodalchemist.knowledge_canon.PUT')
        ->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_cl'], $this->kontext);

    expect($res->success)->toBeFalse()->and($res->error)->toContain('Changelog')
        ->and(DB::table('foodalchemist_knowledge_canon')->count())->toBe(0);
});

it('globale Kanon-Zeile: nur Master, nur globale Dossiers', function () {
    ($this->mkDoc)('rw_glob', null);
    ($this->mkDoc)('rw_team', (int) $this->rootTeam->id);
    $put = $this->registry->get('foodalchemist.knowledge_canon.PUT');

    // Master + globales Dossier → ok
    $ok = $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_glob', 'global' => true], $this->kontext);
    expect($ok->success)->toBeTrue()->and($ok->data['canon']['global'])->toBeTrue();

    // Master + team-eigenes Dossier in globale Zeile → Invariante verletzt
    $inv = $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_team', 'global' => true], $this->kontext);
    expect($inv->success)->toBeFalse()->and($inv->error)->toContain('GLOBALE');

    // Kind-Team darf keine globale Zeile
    $kind = new ToolContext($this->makeUser($this->childA), $this->childA);
    $nein = $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_glob', 'global' => true], $kind);
    expect($nein->success)->toBeFalse()->and($nein->error)->toContain('Master');

    // Kind-Team darf das globale Dossier in SEINE Team-Zeile nehmen
    $eigene = $put->execute(['scope' => 'feature', 'scope_key' => 'g', 'slug' => 'rw_glob'], $kind);
    expect($eigene->success)->toBeTrue()->and($eigene->data['canon']['global'])->toBeFalse();
});

it('Tenancy: Kind sieht globale + eigene Zeilen, nicht die des Geschwister-Teams; Team-Zeile gewinnt gegen globale', function () {
    ($this->mkDoc)('rw_g1', null);
    ($this->mkDoc)('rw_g2', null);
    ($this->mkDoc)('rw_b', (int) $this->childB->id);
    $put = $this->registry->get('foodalchemist.knowledge_canon.PUT');

    // Master: globaler Kanon g1 (ord 10), g2 (ord 20)
    $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_g1', 'ord' => 10, 'global' => true], $this->kontext);
    $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_g2', 'ord' => 20, 'global' => true], $this->kontext);
    // Kind A: überschreibt g2 nach vorn (ord 1, wenn_platz)
    $ktxA = new ToolContext($this->makeUser($this->childA), $this->childA);
    $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_g2', 'ord' => 1, 'mode' => 'wenn_platz'], $ktxA);
    // Kind B: eigenes Dossier dazu
    $ktxB = new ToolContext($this->makeUser($this->childB), $this->childB);
    $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_b', 'ord' => 30], $ktxB);

    $a = $this->svc->documentsFor('feature', 'f', $this->childA);
    expect($a->pluck('slug')->all())->toBe(['rw_g2', 'rw_g1'])
        ->and($a->first()->mode)->toBe('wenn_platz')
        ->and($a->first()->canon_team_id)->toBe((int) $this->childA->id);

    $b = $this->svc->documentsFor('feature', 'f', $this->childB);
    expect($b->pluck('slug')->all())->toBe(['rw_g1', 'rw_g2', 'rw_b']);

    // Master sieht nur globale (B-Dossier ist fremd)
    expect($this->svc->documentsFor('feature', 'f', $this->rootTeam)->pluck('slug')->all())->toBe(['rw_g1', 'rw_g2']);

    // GET aus Kind-A-Sicht: 3 Zeilen (2 global + 1 eigene), keine von B
    $get = $this->registry->get('foodalchemist.knowledge_canon.GET')->execute(['scope_key' => 'f'], $ktxA);
    expect($get->success)->toBeTrue()->and($get->data['total'])->toBe(3)
        ->and(collect($get->data['canon'])->pluck('slug'))->not->toContain('rw_b');
});

it('inaktive Dossiers und inaktive Zeilen fallen aus documentsFor; DELETE entfernt nur die eigene Zeile', function () {
    ($this->mkDoc)('rw_a', null);
    ($this->mkDoc)('rw_off', null, "# X\n\nInaktiv.", 'regelwerk', false);
    $put = $this->registry->get('foodalchemist.knowledge_canon.PUT');
    $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_a'], $this->kontext);
    $off = $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_off'], $this->kontext);
    expect($off->success)->toBeTrue()->and(implode(' ', $off->data['hinweise']))->toContain('inaktiv');

    expect($this->svc->documentsFor('feature', 'f', $this->rootTeam)->pluck('slug')->all())->toBe(['rw_a']);

    $del = $this->registry->get('foodalchemist.knowledge_canon.DELETE')
        ->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_a'], $this->kontext);
    expect($del->success)->toBeTrue()->and($del->data['deleted'])->toBe(1)
        ->and($this->svc->documentsFor('feature', 'f', $this->rootTeam))->toBeEmpty()
        ->and(DB::table('foodalchemist_knowledge_documents')->where('slug', 'rw_a')->exists())->toBeTrue();

    // Re-PUT nach Soft-Delete reaktiviert dieselbe Zeile (kein Unique-Crash)
    $re = $put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_a'], $this->kontext);
    expect($re->success)->toBeTrue()
        ->and(DB::table('foodalchemist_knowledge_canon')->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'knowledge_document_id')->where('d.slug', 'rw_a')->count())->toBe(1);
});

it('warnt über dem Dossier-Deckel (PUT-Hinweis, knowledge.PUT-Hinweis, GET-Sammelhinweis) — blockiert nicht', function () {
    config()->set('foodalchemist.semantic_search.dossier_max_chars', 300);
    $gross = "# Groß\n\n## A\n\n".str_repeat('a', 200)."\n\n## B\n\n".str_repeat('b', 200);
    ($this->mkDoc)('rw_gross', (int) $this->rootTeam->id, $gross);

    $put = $this->registry->get('foodalchemist.knowledge_canon.PUT')
        ->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_gross'], $this->kontext);
    expect($put->success)->toBeTrue()->and(implode(' ', $put->data['hinweise']))->toContain('Deckel');

    $get = $this->registry->get('foodalchemist.knowledge_canon.GET')->execute(['scope_key' => 'f'], $this->kontext);
    expect($get->data['hinweis'])->toContain('rw_gross');

    $upd = $this->registry->get('foodalchemist.knowledge.PUT')
        ->execute(['slug' => 'rw_gross', 'title' => 'Groß v2'], $this->kontext);
    expect($upd->success)->toBeTrue()->and($upd->data['hinweis'])->toContain('2 ##-Abschnitte');

    // Unter dem Deckel: kein Hinweis
    expect($this->svc->groessenHinweis(100, ''))->toBeNull();
});

it('validiert scope/role/mode und unbekannte Slugs', function () {
    $put = $this->registry->get('foodalchemist.knowledge_canon.PUT');
    expect($put->execute(['scope' => 'egal', 'scope_key' => 'f', 'slug' => 'x'], $this->kontext)->success)->toBeFalse()
        ->and($put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'x', 'mode' => 'immer'], $this->kontext)->success)->toBeFalse()
        ->and($put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'gibt_es_nicht'], $this->kontext)->error)->toContain('nicht gefunden');

    // fremdes Team-Dossier ist „nicht gefunden", nicht „verboten" (kein Existenz-Leak)
    ($this->mkDoc)('rw_fremd', (int) $this->childB->id);
    $ktxA = new ToolContext($this->makeUser($this->childA), $this->childA);
    expect($put->execute(['scope' => 'feature', 'scope_key' => 'f', 'slug' => 'rw_fremd'], $ktxA)->error)->toContain('nicht gefunden');
});

it('Embedding-Fenster ist konfigurierbar, Default 2000', function () {
    $svc = app(KnowledgeEmbeddingService::class);
    expect($svc->leadChars())->toBe(2000);
    config()->set('foodalchemist.semantic_search.embed_lead_chars', 4000);
    expect($svc->leadChars())->toBe(4000);
    config()->set('foodalchemist.semantic_search.embed_lead_chars', 0);
    expect($svc->leadChars())->toBe(2000);
});

it('knowledge-oversized listet zu große Dossiers mit ##-Struktur und Fenster-Markierung', function () {
    config()->set('foodalchemist.semantic_search.dossier_max_chars', 300);
    config()->set('foodalchemist.semantic_search.embed_lead_chars', 200);   // 2. Überschrift beginnt bei Zeichen 247
    $gross = "# Groß\n\n## Erstes Thema\n\n".str_repeat('a', 220)."\n\n## Zweites Thema\n\n".str_repeat('b', 200);
    // eigene Kategorie: die Seed-Migrationen bringen selbst große Dossiers mit.
    ($this->mkDoc)('rw_gross', null, $gross, 'test_kanon');
    ($this->mkDoc)('rw_klein', null, "# K\n\nklein.", 'test_kanon');

    // Artisan::call statt $this->artisan(): die JSON-Ausgabe ist EIN writeln — der Mock von
    // expectsOutputToContain verbraucht pro Write nur die erste Erwartung.
    Artisan::call('foodalchemist:knowledge-oversized', ['--json' => true, '--kategorie' => 'test_kanon']);
    $json = json_decode(Artisan::output(), true);
    expect($json['total'])->toBe(1)
        ->and($json['dossiers'][0]['slug'])->toBe('rw_gross')
        ->and($json['dossiers'][0]['abschnitte'][1]['titel'])->toBe('Zweites Thema')
        ->and($json['dossiers'][0]['abschnitte'][1]['jenseits_fenster'])->toBeTrue()
        ->and($json['dossiers'][0]['abschnitte'][0]['jenseits_fenster'])->toBeFalse();

    Artisan::call('foodalchemist:knowledge-oversized', ['--struktur' => true, '--kategorie' => 'test_kanon']);
    $text = Artisan::output();
    expect($text)->toContain('rw_gross')->toContain('Zweites Thema')->not->toContain('rw_klein');
});
