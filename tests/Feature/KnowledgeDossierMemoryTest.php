<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase 1 — Dossier-Laden auf die bewährte Tauri-Disziplin zurückbauen:
 * `discoverDomains` darf den Domain-Dossier-VOLLTEXT (`content_md`) NUR für die ≤ TOP_K
 * ausgewählten Slugs aus der DB ziehen — nie für ALLE Domain-Dossiers (der PHP-Port
 * lud vorher alles in den Speicher, „kein Wunder war der Speicher voll"). Das Scoring
 * läuft über die Slugs allein (kein content_md). Spiegelt `vault_context.rs`.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    // Routing wird sonst nur vom Import-Command geseedet (nicht per Migration) → im Test setzen,
    // damit contextFor('ai_generate_recipe') die Domain-Discovery überhaupt fährt.
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'ai_generate_recipe', 'category' => 'domain', 'mode' => 'discovery',
        'max_docs' => null, 'max_chars_per_doc' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

/** Legt ein Domain-Wissens-Dokument an (global sichtbar), wie der Import es täte. */
function makeDomainDoc(string $slug, string $title): void
{
    $md = "# {$title}\n\n" . str_repeat("Fachinhalt zu {$title}. ", 300);
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) Str::uuid(),
        'team_id' => null,
        'slug' => $slug,
        'title' => $title,
        'category' => 'domain',
        'content_md' => $md,
        'char_count' => mb_strlen($md),
        'content_hash' => hash('sha256', $md),
        'version' => 1,
        'active' => 1,
        'created_via' => 'import',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('zieht content_md nur für die Top-K Domain-Dossiers, nie für alle (DB-seitig gebounded)', function () {
    // Mehr Domain-Dossiers als TOP_K, alle lexikalisch zur Beschreibung passend.
    for ($i = 1; $i <= 8; $i++) {
        makeDomainDoc("kartoffel-thema-{$i}", "Kartoffel Thema {$i}");
    }

    DB::enableQueryLog();
    $res = app(KnowledgeContextService::class)->contextFor(null, 'ai_generate_recipe', 'Kartoffel Gratin', null);
    $log = DB::getQueryLog();

    // KERN-GARANTIE: keine content_md-Query gegen knowledge_documents OHNE Slug-Filter
    // (der alte domainDocs()-Volltext-Scan über ALLE Domains hätte genau so eine Query erzeugt).
    $ungebundenerVolltext = collect($log)->filter(fn ($e) => str_contains($e['query'], 'content_md')
        && str_contains($e['query'], 'foodalchemist_knowledge_documents')
        && ! str_contains($e['query'], 'slug'));
    expect($ungebundenerVolltext)->toHaveCount(0);

    // Feature intakt: Domain-Dossiers landen weiter im Wissensblock, aber gedeckelt auf TOP_K.
    expect($res['block'])->toContain('DOMAIN:');
    $domainTreffer = substr_count($res['block'], '## DOMAIN:');
    expect($domainTreffer)->toBeLessThanOrEqual(KnowledgeContextService::DOMAIN_TOP_K);
    expect($domainTreffer)->toBeGreaterThan(0);
});

it('Scoring läuft über Slugs allein — die Volltext-Query bindet höchstens TOP_K Slugs', function () {
    for ($i = 1; $i <= 8; $i++) {
        makeDomainDoc("gratin-variante-{$i}", "Gratin Variante {$i}");
    }

    DB::enableQueryLog();
    app(KnowledgeContextService::class)->contextFor(null, 'ai_generate_recipe', 'Gratin', null);
    $log = DB::getQueryLog();

    // Die gebundene Domain-Volltext-Query (whereIn slug) darf höchstens TOP_K Slugs binden.
    $domainVolltext = collect($log)->first(fn ($e) => str_contains($e['query'], 'content_md')
        && str_contains($e['query'], 'foodalchemist_knowledge_documents')
        && str_contains($e['query'], ' in ('));

    expect($domainVolltext)->not->toBeNull();
    // Bindings dieser Query = die ausgewählten Slugs (+ evtl. category/active als Nicht-Slug-Bindings,
    // aber die Slug-Menge ist ≤ TOP_K). Konservativ: Gesamt-Bindings ≤ TOP_K + 2 (category/active).
    expect(count($domainVolltext['bindings']))->toBeLessThanOrEqual(KnowledgeContextService::DOMAIN_TOP_K + 2);
});
