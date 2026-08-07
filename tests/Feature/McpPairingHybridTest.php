<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\PairingsGetTool;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Hybrid-Auflösung von pairings.GET / neighborsForName (analog gps.SEARCH):
 * exakter Slug → lexikalischer Index MIT Wortgrenzen-Gate → semantischer
 * Fallback (Opt-in). Jeder Treffer trägt seinen Match-Weg in
 * anker.resolution.via (slug|lexical|semantic; semantic zusätzlich mit score).
 *
 * Das Wortgrenzen-Gate ist der Kern: resolveByName matcht auch Substrings, so
 * dass „Aubergine" still den „Gin"-Anker trifft (auberGINe). Das Gate verwirft
 * solche Zufalls-Substrings → der semantische Fallback kann greifen statt den
 * falschen Anker zurückzugeben.
 *
 * Der FakeEmbeddingProvider ist Bag-of-Words und kann KEINE Cross-Lingual-Nähe
 * (Aubergine↔eggplant teilen kein Token) — darum wird die semantische Auflösung
 * als KnowledgeEmbeddingService-Doppel gemockt. Geprüft wird die VERDRAHTUNG
 * (Gate, Fallback-Reihenfolge, via/score-Kennzeichnung, graceful-off), nicht die
 * echte Embedding-Qualität — die hängt an der Live-API und wird post-Deploy
 * gegen echte Begriffe geeicht (anker_min_score, Slice 2).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->svc = app(PairingService::class);

    $this->mkAnker = function (string $slug, ?string $display = null): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug,
            'display_de' => $display ?? ucfirst($slug), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->mkKante = function (int $a, int $b, string $typ = 'aroma'): void {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $a, 'anchor_b_id' => $b,
            'type' => $typ, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    // Fixtur wie der englische Inspire-Graph: eggplant (+Kante) und die
    // „Gin"-Kollision, an der „Aubergine" lexikalisch strandet.
    $this->egg = ($this->mkAnker)('eggplant', 'Eggplant');
    $this->tom = ($this->mkAnker)('tomato', 'Tomato');
    ($this->mkKante)($this->egg, $this->tom);
    ($this->mkAnker)('gin_1083', 'Gin');
});

it('löst einen exakten Slug auf und markiert resolution.via = slug (ohne Semantik-Zugriff)', function () {
    config(['foodalchemist.semantic_search.enabled' => true]);            // an — darf trotzdem nicht greifen (lexical-first)
    $this->mock(KnowledgeEmbeddingService::class, fn ($m) => $m->shouldNotReceive('resolveAnkerWithScore'));

    $res = $this->svc->neighborsForName('eggplant');

    expect($res['anker'])->not->toBeNull()
        ->and($res['anker']['slug'])->toBe('eggplant')
        ->and($res['anker']['resolution']['via'])->toBe('slug')
        ->and($res['anker']['resolution'])->not->toHaveKey('score')
        ->and(collect($res['partner'])->pluck('slug'))->toContain('tomato');
});

it('akzeptiert einen Wortgrenzen-Treffer als via = lexical', function () {
    config(['foodalchemist.semantic_search.enabled' => true]);
    $this->mock(KnowledgeEmbeddingService::class, fn ($m) => $m->shouldNotReceive('resolveAnkerWithScore'));

    // „grilled eggplant" trägt „eggplant" als ganzes Wort → solider lexikalischer Treffer.
    $res = $this->svc->neighborsForName('grilled eggplant');

    expect($res['anker'])->not->toBeNull()
        ->and($res['anker']['slug'])->toBe('eggplant')
        ->and($res['anker']['resolution']['via'])->toBe('lexical')
        ->and($res['anker']['resolution'])->not->toHaveKey('score');
});

it('verwirft den Substring-Zufall (Aubergine≠Gin) — Semantik AUS ⇒ NOT_FOUND statt falschem Anker', function () {
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->mock(KnowledgeEmbeddingService::class, fn ($m) => $m->shouldNotReceive('resolveAnkerWithScore'));

    // Ohne Gate würde resolveByName „auberGINe" → Gin liefern (non-null). Mit Gate: verworfen.
    $res = $this->svc->neighborsForName('Aubergine');

    expect($res['anker'])->toBeNull()->and($res['partner'])->toBe([]);
});

it('routet den verworfenen Substring-Zufall in die Semantik (Aubergine→eggplant, via = semantic + score)', function () {
    config(['foodalchemist.semantic_search.enabled' => true]);

    // Gate verwirft den Gin-Substring → semantischer Fallback löst korrekt auf eggplant auf.
    $this->mock(KnowledgeEmbeddingService::class, fn ($m) => $m
        ->shouldReceive('resolveAnkerWithScore')->once()
        ->andReturn(['id' => $this->egg, 'score' => 0.8231]));

    $res = $this->svc->neighborsForName('Aubergine');

    expect($res['anker'])->not->toBeNull()
        ->and($res['anker']['slug'])->toBe('eggplant')
        ->and($res['anker']['resolution']['via'])->toBe('semantic')
        ->and($res['anker']['resolution']['score'])->toEqualWithDelta(0.823, 0.0005)   // round(…, 3)
        ->and(collect($res['partner'])->pluck('slug'))->toContain('tomato');
});

it('pairings.GET reicht die resolution durch und meldet NOT_FOUND ohne Treffer', function () {
    config(['foodalchemist.semantic_search.enabled' => false]);
    $ctx = new ToolContext($this->user, $this->childA);

    // Treffer über exakten Slug → resolution steht im Response.
    $ok = (new PairingsGetTool())->execute(['zutat' => 'eggplant', 'limit' => 20], $ctx);
    expect($ok->success)->toBeTrue()
        ->and($ok->data['anker']['resolution']['via'])->toBe('slug')
        ->and(collect($ok->data['partner'])->pluck('slug'))->toContain('tomato');

    // Fantasie-Begriff, Semantik aus → sauberes NOT_FOUND (kein Crash).
    $miss = (new PairingsGetTool())->execute(['zutat' => 'Xyzzurpf', 'limit' => 20], $ctx);
    expect($miss->success)->toBeFalse()->and($miss->errorCode)->toBe('NOT_FOUND');
});
