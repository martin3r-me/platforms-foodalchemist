<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeSearchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket B Aufgabe 3 — Kompositum-Grenze.
 *
 * Befund (PREVIEW-Messung `recipe.generator` gegen den Tomatensuppen-Brief): `tomatensuppe` ist
 * EIN Query-Token. `KnowledgeSearchService` verglich Tokens nur per Jaccard (exakter Overlap) und
 * einer GERICHTETEN Substring-Regel (`str_contains($word, $token)`) — ein Doc-Wort wie „tomate"
 * (6 Zeichen) kann das längere Kompositum „tomatensuppe" (12 Zeichen) nie ENTHALTEN. Domain-Dossiers
 * zur Hauptzutat scorten deshalb 0, obwohl sie fachlich der Kerntreffer waren.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = function (string $slug, string $title, string $category = 'domain') {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $title,
            'category' => $category, 'content_md' => "Wissen zu {$title}", 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => 20,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('Tomatensuppe findet ein Domain-Dossier mit "tomate" im Slug (Decompounding)', function () {
    ($this->mkDoc)('fruchtgemuese-tomate-verwendung', 'Fruchtgemüse Tomate Verwendung');
    ($this->mkDoc)('gaenseblume-dekoration', 'Gänseblümchen zur Dekoration');   // Distraktor, kein Overlap

    $base = DB::table('foodalchemist_knowledge_documents')->where('category', 'domain')->where('active', 1);
    $hits = app(KnowledgeSearchService::class)->search($base, 'Tomatensuppe', 5, semantic: false);

    $slugs = array_column($hits, 'slug');
    expect($slugs)->toContain('fruchtgemuese-tomate-verwendung')
        ->and($slugs)->not->toContain('gaenseblume-dekoration');
});

it('bidirektionale Substring-Regel: kurzes Doc-Wort "suppen" matcht das laengere Kompositum "Tomatensuppe"', function () {
    // Vorher nur $word CONTAINS $token — "suppen" (6 Z.) kann "tomatensuppe" (12 Z.) nicht enthalten.
    // Nach dem Fix genuegt die Decompoundierung (Kopf "suppe" wird eigener Token) ODER die jetzt
    // beidseitige Substring-Regel, je nachdem welches Doc-Wort real vorkommt.
    ($this->mkDoc)('suppen-und-fonds-grundlagen', 'Suppen und Fonds Grundlagen');
    ($this->mkDoc)('gaenseblume-dekoration', 'Gänseblümchen zur Dekoration');

    $base = DB::table('foodalchemist_knowledge_documents')->where('category', 'domain')->where('active', 1);
    $hits = app(KnowledgeSearchService::class)->search($base, 'Tomatensuppe', 5, semantic: false);

    $slugs = array_column($hits, 'slug');
    expect($slugs)->toContain('suppen-und-fonds-grundlagen')
        ->and($slugs)->not->toContain('gaenseblume-dekoration');
});

it('bleibt praezise: ein kurzes unverwandtes Wort matcht "Tomatensuppe" nicht per Substring', function () {
    // "Salat" (5 Z.) ist weder Substring von "tomatensuppe" noch dessen Decompoundierung
    // (COMPOUND_HEADS kennt "suppe", nicht "salat") — die neue beidseitige Regel darf hier
    // trotzdem nicht feuern, sonst waere jedes 5-Zeichen-Wort ein Treffer.
    ($this->mkDoc)('salat-anrichten', 'Salat Anrichten');

    $base = DB::table('foodalchemist_knowledge_documents')->where('category', 'domain')->where('active', 1);
    $hits = app(KnowledgeSearchService::class)->search($base, 'Tomatensuppe', 5, semantic: false);

    expect(array_column($hits, 'slug'))->not->toContain('salat-anrichten');
});
