<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket H — Golden-Test "Passionsfrucht-Gelee mit Acerola, 40 Portionen" (D1).
 *
 * Golden-Referenz "vorher" (MCP `knowledge.PREVIEW` gegen demo, Team 6, vor A+B+C):
 * `recipe.generator` (Budget 80.000) sandte `zutat.acerola_14--{steckbrief,verwendung,cave,verhalten}`
 * (ALLE 4 Aspekte einer Nebenzutat) + nur `zutat.passion_fruit--{steckbrief,verwendung}` (2 von 4)
 * und droppte `zutat.passion_fruit--cave` + `frucht-gelees-praxis-dosierungen-apfel-birne-ananas`
 * (dropped_chars 7.150). `recipe.steps` (Budget NUR 17.200) sandte gar keinen `passion_fruit`-Aspekt
 * mehr, nur 2 von 4 `acerola`-Aspekten + 1 generisches Tropenfrüchte-Doc, und droppte BEIDE
 * Gelée-Technik-Dossiers vollständig (dropped_chars 33.104 — mehr als das Doppelte dessen was
 * ankam). Dieser Test reproduziert das Korpus-Muster lokal (echte Doc-Größen ~3.2-3.9k Zeichen je
 * Aspekt, real existierender `recipe.steps`-Budget aus `config('foodalchemist.ai.knowledge_budget')`)
 * und beweist Aufgabe A+B: je Zutat GENAU EIN passender Aspekt, das Technik-Dossier bleibt drin.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkAnker = function (string $slug, string $displayDe) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $displayDe,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->mkDoc = function (string $slug, string $category, string $inhalt) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug,
            'category' => $category, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->mkRouting = function (string $feature, string $category, string $mode, int $maxDocs) {
        // updateOrInsert statt insert: `recipe.generator`/`recipe.steps` sind reale Prompt-Keys mit
        // eigener Seed-Config (KnowledgePolicySeedCommand) — eine `kueche`-Zeile kann dort schon
        // stehen, ein blindes insert() liefe in den unique(feature, category)-Konflikt.
        DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
            ['feature' => $feature, 'category' => $category],
            ['mode' => $mode, 'max_docs' => $maxDocs, 'max_chars_per_doc' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    };

    ($this->mkAnker)('passion_fruit', 'Passionsfrucht');
    ($this->mkAnker)('acerola', 'Acerola');
    // Realistische Doc-Groessen aus dem Korpus (~3.2-3.9k Zeichen je Aspekt-Dossier).
    foreach (['steckbrief' => 3460, 'verwendung' => 3731, 'cave' => 3226, 'verhalten' => 3776] as $aspekt => $groesse) {
        ($this->mkDoc)("zutat.passion_fruit--{$aspekt}", 'zutat', "Passionsfrucht {$aspekt}: " . str_repeat('x', $groesse - 25));
    }
    foreach (['steckbrief' => 3401, 'verwendung' => 3804, 'cave' => 3861, 'verhalten' => 3819] as $aspekt => $groesse) {
        ($this->mkDoc)("zutat.acerola_14--{$aspekt}", 'zutat', "Acerola {$aspekt}: " . str_repeat('x', $groesse - 20));
    }
    ($this->mkDoc)('frucht-gelees-praxis-dosierungen-apfel-birne-ananas', 'kueche',
        'Frucht-Gelees Praxis-Dosierungen Passionsfrucht Acerola Gelee: ' . str_repeat('x', 3737));
});

it('recipe.generator: je Zutat genau ein Aspekt (verwendung), das Gelee-Technik-Dossier bleibt drin', function () {
    ($this->mkRouting)('recipe.generator', 'zutat', 'grounding', 8);
    ($this->mkRouting)('recipe.generator', 'kueche', 'discovery', 3);

    $ctx = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'recipe.generator', 'Passionsfrucht-Gelee mit Acerola, 40 Portionen', null,
        ['passionsfrucht', 'gelee', 'acerola', 'portionen'],
    );

    $zutatFiles = array_values(array_filter($ctx['files_used'], fn ($f) => str_starts_with($f, 'zutat.')));
    expect($zutatFiles)->toHaveCount(2)
        ->and($zutatFiles)->toContain('zutat.passion_fruit--verwendung@v1')
        ->toContain('zutat.acerola_14--verwendung@v1')
        ->and($ctx['files_used'])->toContain('frucht-gelees-praxis-dosierungen-apfel-birne-ananas@v1');
});

it('recipe.steps: kleines Budget (17.200) reicht jetzt fuer beide Zutaten-Aspekte UND das Technik-Dossier', function () {
    ($this->mkRouting)('recipe.steps', 'zutat', 'grounding', 8);
    ($this->mkRouting)('recipe.steps', 'kueche', 'discovery', 3);

    $ctx = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'recipe.steps', 'Passionsfrucht-Gelee mit Acerola, 40 Portionen', null,
        ['passionsfrucht', 'gelee', 'acerola', 'portionen'],
    );

    $zutatFiles = array_values(array_filter($ctx['files_used'], fn ($f) => str_starts_with($f, 'zutat.')));
    // Vorher (ohne A+B): 0 passion_fruit-Aspekte, nur 2 von 4 acerola-Aspekten, BEIDE Technik-Docs gedroppt.
    expect($zutatFiles)->toHaveCount(2)
        ->and($zutatFiles)->toContain('zutat.passion_fruit--verhalten@v1')
        ->toContain('zutat.acerola_14--verhalten@v1')
        ->and($ctx['files_used'])->toContain('frucht-gelees-praxis-dosierungen-apfel-birne-ananas@v1')
        ->and($ctx['dropped_chars'])->toBe(0);
});
