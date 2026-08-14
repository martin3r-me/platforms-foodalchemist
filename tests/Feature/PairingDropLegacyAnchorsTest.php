<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

// Die Suite bindet bewusst kein RefreshDatabase — die Migration läuft über den Trait.
beforeEach(fn () => $this->seedTeamHierarchy());

// Eindeutiger Helfer-Name (nicht `mkAnker` — kollidiert im Single-Process-Filterlauf
// mit PairingNetzTest::mkAnker).
function mkAnkerSrc(string $slug, ?string $sourcePath, ?int $teamId = null): int
{
    DB::table('foodalchemist_vocab_pairing_anchors')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
        'source_path' => $sourcePath, 'team_id' => $teamId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (int) DB::getPdo()->lastInsertId();
}

/**
 * Inspire-Umbau: `foodalchemist:pairing-drop-legacy-anchors` entfernt die alten
 * Nicht-Inspire-Anker — und prunet ihre Qdrant-Vektoren mit (kein Bulk-Delete im
 * Core-Contract → per Entität via EmbeddingService::delete), damit keine Waisen
 * bleiben, die die semantische Anker-Auflösung verfälschen.
 */
it('apply: löscht Legacy-Anker, behält Inspire, prunet deren Qdrant-Vektoren', function () {
    $inspire = mkAnkerSrc('coffee', 'foodpairing_inspire');
    $legacyA = mkAnkerSrc('altanker_a', null);              // source_path NULL = Legacy
    $legacyB = mkAnkerSrc('altanker_b', 'vault_irgendwas'); // <> foodpairing_inspire = Legacy

    // Prune-Erwartung: genau die 2 Legacy-Anker, global (team_id NULL → globalTeamId=0),
    // entityType = Anker-Vokabular. Inspire-Anker darf NICHT gepruned werden.
    $geloescht = [];
    $this->mock(EmbeddingService::class)
        ->shouldReceive('delete')->twice()
        ->withArgs(function ($teamId, $typ, $id) use (&$geloescht, $inspire) {
            $geloescht[] = $id;

            return $teamId === 0
                && $typ === KnowledgeEmbeddingService::ENTITY_TYPE_ANKER
                && $id !== $inspire;
        });

    $this->artisan('foodalchemist:pairing-drop-legacy-anchors', ['--apply' => true])
        ->assertExitCode(0);

    expect(collect($geloescht)->sort()->values()->all())->toBe(collect([$legacyA, $legacyB])->sort()->values()->all());

    $rest = DB::table('foodalchemist_vocab_pairing_anchors')->pluck('id')->all();
    expect($rest)->toBe([$inspire]);
});

it('dry-run: löscht nichts und prunet nichts', function () {
    mkAnkerSrc('coffee', 'foodpairing_inspire');
    mkAnkerSrc('altanker_a', null);

    $this->mock(EmbeddingService::class)->shouldReceive('delete')->never();

    $this->artisan('foodalchemist:pairing-drop-legacy-anchors') // ohne --apply
        ->assertExitCode(0);

    expect(DB::table('foodalchemist_vocab_pairing_anchors')->count())->toBe(2);
});

it('abbruch: ohne Inspire-Anker wird NICHT gelöscht (Schutz gegen Total-Wipe)', function () {
    mkAnkerSrc('altanker_a', null);
    mkAnkerSrc('altanker_b', null);

    // Kein einziger Inspire-Anker → Command bricht ab, bevor irgendetwas gelöscht/gepruned wird.
    $this->mock(EmbeddingService::class)->shouldReceive('delete')->never();

    $this->artisan('foodalchemist:pairing-drop-legacy-anchors', ['--apply' => true])
        ->assertExitCode(1);

    expect(DB::table('foodalchemist_vocab_pairing_anchors')->count())->toBe(2);
});
