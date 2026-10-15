<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Support\EmbeddingStapel;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Wissens-Embedding in Stapeln: Auf foodalchemist.io brach `foodalchemist:embed --pool=knowledge` nach dem Import von 14.250 Dossiers mit
 * HTTP 400 ab („Requested 987757 tokens, max 300000 tokens per request“): embedCorpus übergab ALLE 11.975 Zutat-Dossiers (je bis 4000 Zeichen)
 * in EINEM embedAndStoreBatch. Jetzt teilt EmbeddingStapel (gemeinsam mit dem PoolEmbeddingService) vor dem Core-Aufruf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config([
        'embeddings.default_provider' => 'fake',
        'foodalchemist.semantic_search.enabled' => true,
        'foodalchemist.semantic_search.provider' => 'fake',
    ]);
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, function () {
        $r = new EmbeddingProviderRegistry;
        $r->register(new FakeEmbeddingProvider(64));

        return $r;
    });

    // Aufnehmender Core-Service: jeder embedAndStoreBatch-Aufruf wird mit Anzahl und Zeichensumme festgehalten
    $this->aufrufe = [];
    $aufrufe = &$this->aufrufe;
    $core = Mockery::mock(EmbeddingService::class);
    $core->shouldReceive('embedAndStoreBatch')->andReturnUsing(function (...$args) use (&$aufrufe) {
        $eintraege = $args['entries'] ?? $args[2];
        $aufrufe[] = ['team' => $args['teamId'] ?? $args[0], 'n' => count($eintraege), 'zeichen' => array_sum(array_map(fn ($e) => mb_strlen($e['text']), $eintraege)),
            'ids' => array_column($eintraege, 'id')];

        return ['embedded' => count($eintraege), 'skipped' => 0];
    });
    app()->instance(EmbeddingService::class, $core);

    $this->mkDocs = function (int $anzahl, string $kategorie = 'zutat', ?int $teamId = null, int $laenge = 4000) {
        $ids = [];
        foreach (range(1, $anzahl) as $n) {
            $slug = $kategorie.'.'.($teamId ?? 'g').'.'.$n;
            DB::table('foodalchemist_knowledge_documents')->insert([
                'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => 'Dossier '.$n, 'category' => $kategorie,
                'content_md' => str_repeat('Füllsatz zur Länge. ', intdiv($laenge, 20) + 1), 'version' => 1, 'content_hash' => hash('sha256', $slug),
                'char_count' => $laenge, 'active' => 1, 'team_id' => $teamId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $ids[] = (int) DB::getPdo()->lastInsertId();
        }

        return $ids;
    };
});

it('viele große Dossiers gehen in MEHREREN Aufrufen an den Core, keiner liegt über der Zeichengrenze, keines geht verloren', function () {
    $ids = ($this->mkDocs)(300);   // ≈ 1,2 Mio. Zeichen: vorher ein Aufruf, jetzt mehrere

    $r = app(KnowledgeEmbeddingService::class)->embedCorpus(['zutat']);

    expect($r['available'])->toBeTrue()->and($r['candidates'])->toBe(300)
        ->and(count($this->aufrufe))->toBeGreaterThan(1)
        ->and(max(array_column($this->aufrufe, 'zeichen')))->toBeLessThanOrEqual(EmbeddingStapel::MAX_ZEICHEN)
        ->and(max(array_column($this->aufrufe, 'n')))->toBeLessThanOrEqual(EmbeddingStapel::MAX_EINTRAEGE);
    expect(collect($this->aufrufe)->pluck('ids')->flatten()->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());   // alle genau einmal
});

it('Gegenprobe: ohne Stapelung läge ein einziger Aufruf weit über der Grenze (so brach es auf foodalchemist.io)', function () {
    ($this->mkDocs)(300);
    $alt = [['id' => 1, 'text' => str_repeat('x', 300 * 4000)]];   // das Bild des alten Verhaltens: alles in einem Aufruf

    expect(mb_strlen($alt[0]['text']))->toBeGreaterThan(EmbeddingStapel::MAX_ZEICHEN * 2);
    // und die Probe selbst ist scharf: nach der Stapelung bleibt jeder Aufruf unter der Grenze, vorher nicht
    $mitStapel = EmbeddingStapel::teilen(array_map(fn ($n) => ['id' => $n, 'text' => str_repeat('x', 4000)], range(1, 300)));
    $ohneStapel = [array_map(fn ($n) => ['id' => $n, 'text' => str_repeat('x', 4000)], range(1, 300))];
    $summe = fn (array $stapelListe) => max(array_map(fn ($s) => array_sum(array_map(fn ($e) => mb_strlen($e['text']), $s)), $stapelListe));
    expect($summe($mitStapel))->toBeLessThanOrEqual(EmbeddingStapel::MAX_ZEICHEN)->and($summe($ohneStapel))->toBeGreaterThan(EmbeddingStapel::MAX_ZEICHEN);
});

it('Team-Partitionen bleiben getrennt: jedes Team bekommt seine eigenen Stapel, nie ein gemischter', function () {
    $a = $this->childA->id;
    $b = $this->childB->id;
    ($this->mkDocs)(150, 'zutat', $a);
    ($this->mkDocs)(150, 'zutat', $b);

    app(KnowledgeEmbeddingService::class)->embedCorpus(['zutat']);

    $nachTeam = collect($this->aufrufe)->groupBy('team');
    expect($nachTeam->keys()->map(fn ($k) => (int) $k)->sort()->values()->all())->toBe(collect([$a, $b])->sort()->values()->all())
        ->and($nachTeam[$a]->sum('n'))->toBe(150)->and($nachTeam[$b]->sum('n'))->toBe(150)
        ->and($nachTeam[$a]->count())->toBeGreaterThan(1);
});

it('wenige kleine Dossiers bleiben EIN Aufruf (kein unnötiges Zerhacken)', function () {
    ($this->mkDocs)(10, 'domain', null, 800);

    app(KnowledgeEmbeddingService::class)->embedCorpus(['domain']);

    expect($this->aufrufe)->toHaveCount(1)->and($this->aufrufe[0]['n'])->toBe(10);
});

it('Der Helper: Item-Obergrenze, einzelner Riese bekommt einen eigenen Stapel, Reihenfolge bleibt; der Pool-Dienst nutzt denselben', function () {
    $klein = fn (int $n) => array_map(fn ($i) => ['id' => $i, 'text' => 'a'], range(1, $n));
    expect(array_map('count', EmbeddingStapel::teilen($klein(2500))))->toBe([1000, 1000, 500]);

    $mit = [['id' => 1, 'text' => 'a'], ['id' => 2, 'text' => str_repeat('x', 600_000)], ['id' => 3, 'text' => 'b']];
    $teile = EmbeddingStapel::teilen($mit);
    expect(array_map(fn ($s) => array_column($s, 'id'), $teile))->toBe([[1], [2], [3]]);   // der Riese wird nie verworfen oder geteilt
    expect(EmbeddingStapel::teilen([]))->toBe([]);

    // Pool und Wissen teilen dieselbe Grenze (kein Auseinanderlaufen)
    $pool = app(PoolEmbeddingService::class)->chunkByBudget($klein(2500));
    expect($pool)->toBe(EmbeddingStapel::teilen($klein(2500)));
});

// ── Anker: dieselbe Fehlerklasse (alle 2.628 Anker in EINEM Aufruf; OpenAI erlaubt 2.048 Eingaben je Request) ──

it('embedAnkers: 2.628 Anker gehen in mehreren Aufrufen an den Core, keiner über 1000 Einträgen (OpenAI-Grenze 2.048), alle genau einmal', function () {
    $ids = [];
    foreach (range(1, 2628) as $n) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => 'anker_'.$n, 'display_de' => 'Anker '.$n, 'team_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids[] = (int) DB::getPdo()->lastInsertId();
    }

    $r = app(KnowledgeEmbeddingService::class)->embedAnkers();

    expect($r['available'])->toBeTrue()->and($r['candidates'])->toBe(2628)
        ->and(count($this->aufrufe))->toBe(3)
        ->and(max(array_column($this->aufrufe, 'n')))->toBeLessThanOrEqual(EmbeddingStapel::MAX_EINTRAEGE)->toBeLessThan(2048)
        ->and(max(array_column($this->aufrufe, 'zeichen')))->toBeLessThanOrEqual(EmbeddingStapel::MAX_ZEICHEN);
    expect(collect($this->aufrufe)->pluck('ids')->flatten()->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());
});

it('embedAnkers Gegenprobe: ohne Stapelung wäre es ein Aufruf mit 2.628 Einträgen, über der OpenAI-Grenze von 2.048', function () {
    $alle = array_map(fn ($n) => ['id' => $n, 'text' => 'Anker '.$n], range(1, 2628));

    expect(count($alle))->toBeGreaterThan(2048);                                       // das alte Verhalten: EIN Aufruf
    expect(max(array_map('count', EmbeddingStapel::teilen($alle))))->toBeLessThan(2048);   // mit Stapelung nie
});

it('embedAnkers: ein kleines Anker-Vokabular bleibt EIN Aufruf; „neutral“ wird weiter ausgelassen', function () {
    foreach (['neutral', 'zitrone', 'basilikum'] as $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug), 'team_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $r = app(KnowledgeEmbeddingService::class)->embedAnkers();

    expect($r['candidates'])->toBe(2)->and($this->aufrufe)->toHaveCount(1)->and($this->aufrufe[0]['n'])->toBe(2);
});
