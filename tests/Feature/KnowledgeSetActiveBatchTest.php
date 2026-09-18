<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\QueueKnowledgeEmbedJob;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Briefing Zutaten-Bulk-Import, 2026-09-18: blockweises Aktivieren via slugs[], Embedding
 * gestaffelt statt als Burst — `GenerateEmbeddingJob` (Core) hat keine Rate-Limiting-Middleware,
 * demo faehrt nur 2 Worker auf der default-Queue.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = app(ToolRegistry::class)->get('foodalchemist.knowledge.SET_ACTIVE');

    $this->mkDoc = function (string $slug) {
        $doc = app(KnowledgeService::class)->create($this->rootTeam, [
            'title' => $slug, 'slug' => $slug, 'category' => 'cross_cutting', 'content_md' => 'Inhalt.',
        ]);
        DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->update(['active' => false]);

        return $doc;
    };
});

it('aktiviert mehrere Slugs in einem Aufruf und stoesst je Slug einen Embed-Job an', function () {
    Queue::fake();
    ($this->mkDoc)('zutat.a--verwendung');
    ($this->mkDoc)('zutat.b--verwendung');

    $res = $this->tool->execute(['slugs' => ['zutat.a--verwendung', 'zutat.b--verwendung'], 'active' => true], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['geaendert'])->toBe(2)
        ->and($res->data['embedding_fertig_ca'])->not->toBeNull();
    Queue::assertPushed(QueueKnowledgeEmbedJob::class, 2);
    expect((bool) DB::table('foodalchemist_knowledge_documents')->where('slug', 'zutat.a--verwendung')->value('active'))->toBeTrue();
});

it('staffelt Embed-Jobs ueber Chunks — Slug 51 bekommt eine Verzoegerung, Slug 1 nicht', function () {
    Queue::fake();
    $slugs = [];
    for ($i = 1; $i <= 51; $i++) {
        $slug = "zutat.charge_{$i}--verwendung";
        ($this->mkDoc)($slug);
        $slugs[] = $slug;
    }

    $this->tool->execute(['slugs' => $slugs, 'active' => true], $this->kontext);

    // Erster Chunk (Verzögerung 0) wird OHNE delay() dispatcht -> $job->delay bleibt null.
    Queue::assertPushed(QueueKnowledgeEmbedJob::class, fn ($job) => $job->slug === 'zutat.charge_1--verwendung' && $job->delay === null);
    Queue::assertPushed(QueueKnowledgeEmbedJob::class, fn ($job) => $job->slug === 'zutat.charge_51--verwendung' && $job->delay !== null);
});

it('deaktiviert im Block ohne Embed-Job (nur Vektor-Purge, kein Provider-Aufruf)', function () {
    Queue::fake();
    $doc = ($this->mkDoc)('zutat.aktiv--verwendung');
    DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->update(['active' => true]);

    $res = $this->tool->execute(['slugs' => ['zutat.aktiv--verwendung'], 'active' => false], $this->kontext);

    expect($res->data['geaendert'])->toBe(1)
        ->and($res->data['embedding_fertig_ca'])->toBeNull();
    Queue::assertNotPushed(QueueKnowledgeEmbedJob::class);
});

it('meldet einen unbekannten Slug im Block statt den ganzen Aufruf abzubrechen', function () {
    ($this->mkDoc)('zutat.gut--verwendung');

    $res = $this->tool->execute(['slugs' => ['zutat.gut--verwendung', 'zutat.gibtsnicht--verwendung'], 'active' => true], $this->kontext);

    expect($res->data['geaendert'])->toBe(1)
        ->and($res->data['nicht_gefunden'])->toBe(1);
});

it('verlangt genau eines von slug/slugs, nicht beides und nicht keins', function () {
    $keins = $this->tool->execute(['active' => true], $this->kontext);
    $beides = $this->tool->execute(['slug' => 'a', 'slugs' => ['b'], 'active' => true], $this->kontext);

    expect($keins->success)->toBeFalse()
        ->and($beides->success)->toBeFalse();
});

it('weist einen zu grossen Block ab, statt ihn zu fahren', function () {
    $slugs = array_fill(0, KnowledgeService::SET_ACTIVE_BATCH_MAX + 1, 'x');

    $res = $this->tool->execute(['slugs' => $slugs, 'active' => true], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->error)->toContain('Bloecken');
});
