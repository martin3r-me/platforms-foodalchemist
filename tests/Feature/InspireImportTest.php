<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\InspireImportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P1: Anker-Identität = Inspire-UUID. Der Import ist idempotent, erkennt vorhandene
 * Anker über inspire_id wieder und überschreibt gepflegte Namen nicht.
 */
function inspireQuelle(array $zutaten, array $paare): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE ingredients (ix INTEGER PRIMARY KEY, id TEXT, name TEXT, category TEXT, subcategory TEXT, has_pairing_data INTEGER)');
    $pdo->exec('CREATE TABLE pairings_strong (a INTEGER, b INTEGER, level INTEGER)');
    $z = $pdo->prepare('INSERT INTO ingredients VALUES (?, ?, ?, ?, ?, 1)');
    foreach ($zutaten as $r) {
        $z->execute($r);
    }
    $p = $pdo->prepare('INSERT INTO pairings_strong VALUES (?, ?, ?)');
    foreach ($paare as [$a, $b, $l]) {
        $p->execute([$a, $b, $l]);
        $p->execute([$b, $a, $l]);
    }

    return $pdo;
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->quelle = fn () => inspireQuelle([
        [0, 'uuid-almond', 'Almond', 'nuts', 'nuts'],
        [1, 'uuid-beet', 'Beetroot', 'vegetables', 'roots'],
        [2, 'uuid-gochu-a', 'Gochujang', 'condiments', 'pastes'],
        [3, 'uuid-gochu-b', 'Gochujang', 'condiments', 'pastes'],
    ], [[0, 1, 3], [1, 2, 2]]);
});

it('mintet je Inspire-Zutat einen Anker mit inspire_id und inspire_ix', function () {
    $stats = app(InspireImportService::class)->import(($this->quelle)(), true, 1);

    expect($stats['anchors_created'])->toBe(4)
        ->and($stats['anchors_known'])->toBe(0)
        ->and(DB::table('foodalchemist_vocab_pairing_anchors')->where('inspire_id', 'uuid-beet')->value('inspire_ix'))->toBe(1)
        // Dubletten-Namen bleiben zwei Anker, unterschieden über die Inspire-ID
        ->and(DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('inspire_id', ['uuid-gochu-a', 'uuid-gochu-b'])->count())->toBe(2)
        ->and(DB::table('foodalchemist_pairing_anchor_edges')->count())->toBe(4);
});

it('ist idempotent: zweiter Lauf legt nichts an und behält gepflegte Namen', function () {
    $svc = app(InspireImportService::class);
    $svc->import(($this->quelle)(), true, 1);
    $idVorher = DB::table('foodalchemist_vocab_pairing_anchors')->where('inspire_id', 'uuid-beet')->value('id');
    DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $idVorher)->update(['display_de' => 'Rote Bete']);

    $stats = $svc->import(($this->quelle)(), true, 1);

    expect($stats['anchors_created'])->toBe(0)
        ->and($stats['anchors_known'])->toBe(4)
        ->and(DB::table('foodalchemist_vocab_pairing_anchors')->whereNotNull('inspire_id')->count())->toBe(4)
        ->and(DB::table('foodalchemist_vocab_pairing_anchors')->where('inspire_id', 'uuid-beet')->value('id'))->toBe($idVorher)
        ->and(DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $idVorher)->value('display_de'))->toBe('Rote Bete')
        ->and(DB::table('foodalchemist_pairing_anchor_edges')->count())->toBe(4);
});

it('mintet bei neuem Lauf nur die neue Zutat', function () {
    $svc = app(InspireImportService::class);
    $svc->import(($this->quelle)(), true, 1);

    $neu = inspireQuelle([
        [0, 'uuid-almond', 'Almond', 'nuts', 'nuts'],
        [1, 'uuid-beet', 'Beetroot', 'vegetables', 'roots'],
        [2, 'uuid-gochu-a', 'Gochujang', 'condiments', 'pastes'],
        [3, 'uuid-gochu-b', 'Gochujang', 'condiments', 'pastes'],
        [4, 'uuid-cherry', 'Sweet Cherry', 'fruits', 'stone fruits'],
    ], [[0, 1, 3], [1, 2, 2], [1, 4, 3]]);
    $stats = $svc->import($neu, true, 1);

    expect($stats['anchors_created'])->toBe(1)
        ->and(DB::table('foodalchemist_vocab_pairing_anchors')->where('inspire_id', 'uuid-cherry')->value('display_en'))->toBe('Sweet Cherry')
        ->and(DB::table('foodalchemist_pairing_anchor_edges')->count())->toBe(6);
});

it('Backfill-Datei deckt jeden Slug genau einmal ab', function () {
    $fh = fopen(__DIR__.'/../../database/data/inspire_anchor_ids.csv', 'r');
    $kopf = fgetcsv($fh);
    $slugs = $ids = [];
    while (($z = fgetcsv($fh)) !== false) {
        $r = array_combine($kopf, $z);
        $slugs[] = $r['slug'];
        $ids[] = $r['inspire_id'];
    }
    fclose($fh);

    expect($kopf)->toBe(['slug', 'inspire_id', 'inspire_ix', 'name_en'])
        ->and(count($slugs))->toBe(2628)
        ->and(count(array_unique($slugs)))->toBe(2628)
        ->and(count(array_unique($ids)))->toBe(2628);
});
