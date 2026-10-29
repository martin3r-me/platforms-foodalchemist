<?php

use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002: `foodalchemist_knowledge_runs.id` ist eine UUID, `ki_auftraege.knowledge_run_id` war BIGINT (Migration 2026_11_20_100000).
 * SQLite nimmt das still, MySQL (strict) lehnt den Insert ab („Data truncated for column", SQLSTATE 1265): jeder externe Auftrag, der unter einem
 * Wissens-Lauf entsteht, ließe sich dort nicht anlegen. Die Migration 2026_11_22_120000 macht die Spalte zur UUID.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();   // fährt die Migrationen der Test-DB
});

it('ki_auftraege.knowledge_run_id ist eine UUID-Spalte (BIGINT scheitert auf MySQL mit „Data truncated")', function () {
    $typ = collect(Schema::getColumns('foodalchemist_ki_auftraege'))->firstWhere('name', 'knowledge_run_id');

    expect(strtolower((string) ($typ['type_name'] ?? '')))->toBeIn(['char', 'varchar', 'uuid', 'text'], 'war BIGINT; Migration 2026_11_22_120000')
        ->and($typ['nullable'] ?? null)->toBeTrue();
});

it('der Auftrag lässt sich mit der UUID eines Wissens-Laufs anlegen und wieder lesen', function () {
    $uuid = (string) \Symfony\Component\Uid\UuidV7::generate();
    \Illuminate\Support\Facades\DB::table('foodalchemist_ki_auftraege')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => 1, 'feature' => 'recipe.sensorik', 'messages_hash' => str_repeat('a', 64),
        'messages' => '[]', 'knowledge_run_id' => $uuid, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(\Illuminate\Support\Facades\DB::table('foodalchemist_ki_auftraege')->value('knowledge_run_id'))->toBe($uuid);
});
