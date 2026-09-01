<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
});

it('begrenzt Rezeptwissen nach Zeichenbudget statt nach einer starren Dokumentzahl', function () {
    foreach (range(1, 14) as $i) {
        $category = 'testcat' . $i;
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(),
            'slug' => "rinderfilet-wissen-{$i}", 'title' => "Rinderfilet Wissen {$i}",
            'category' => $category, 'content_md' => str_repeat("Rinderfilet Technik {$i}. ", 300),
            'version' => 1, 'content_hash' => hash('sha256', (string) $i), 'char_count' => 6000,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('foodalchemist_knowledge_routings')->insert([
            'feature' => 'ai_generate_recipe', 'category' => $category,
            'mode' => 'discovery', 'max_docs' => 3, 'max_chars_per_doc' => 8000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $ctx = app(KnowledgeContextService::class)->contextFor(
        'ai_generate_recipe', 'Rinderfilet als Hauptgang', null, [], ['level' => 'gehoben']
    );

    expect($ctx['files_used'])->each->toContain('rinderfilet-wissen-')
        ->and($ctx['files_used'])->toHaveCount(14)
        ->and($ctx['total_chars'])->toBeLessThanOrEqual(KnowledgeContextService::RECIPE_MAX_KNOWLEDGE_CHARS + 40);
});

it('verwendet den Wissensplan des Gerichts als Scope für ein Basisrezept', function () {
    foreach ([
        ['franzoesische-kartoffeltechnik', 'Französische Kartoffeltechnik'],
        ['japanische-kartoffeltechnik', 'Japanische Kartoffeltechnik'],
    ] as [$slug, $title]) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $title,
            'category' => 'kueche', 'content_md' => $title . ' Kartoffel', 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => 40, 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('foodalchemist_knowledge_routings')->updateOrInsert([
        'feature' => 'ai_generate_recipe', 'category' => 'kueche',
    ], [
        'mode' => 'discovery', 'max_docs' => 3, 'max_chars_per_doc' => 8000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ctx = app(KnowledgeContextService::class)->contextFor(
        'ai_generate_recipe', 'Kartoffel französisch japanisch', null, [], [
            'rezept_typ' => 'basisrezept',
            '_knowledge_scope' => ['franzoesische-kartoffeltechnik@v1'],
        ]
    );

    expect($ctx['files_used'])->toContain('franzoesische-kartoffeltechnik@v1')
        ->not->toContain('japanische-kartoffeltechnik@v1');
});
