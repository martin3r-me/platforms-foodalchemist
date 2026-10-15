<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\EmbeddingProviderContract;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\Core\Services\EmbeddingStoreRegistry;
use Platform\Core\Services\QdrantEmbeddingStore;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\SupplierItemEmbedBackfill;
use Platform\FoodAlchemist\Tests\Support\FakeEmbeddingProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * `foodalchemist:embed --pool=la` gezielt: nur die FEHLENDEN, nach IDs, mit Limit/Pause/Zeitfenster.
 * Anlass: ~84.000 per Roh-Insert (ohne Observer) eingespielte Necta-Artikel. Der Vollabgleich
 * macht je Artikel einen Hash-Abruf im Store (bei Qdrant ein GET) — hier wird der Bestand
 * einmal je Partition gelesen. Fake-Provider (kein HTTP) + ein In-Memory-Qdrant (Http::fake).
 */
/** Provider, der an einem Text-Marker scheitert — für die Fehlerpfade. */
class LaBoomProvider extends FakeEmbeddingProvider
{
    public int $calls = 0;

    public function embed(array $texts, string $type = 'document'): array
    {
        $this->calls++;
        foreach ($texts as $t) {
            if (str_contains((string) $t, 'boom')) {
                throw new RuntimeException('Provider 503');
            }
        }

        return parent::embed($texts, $type);
    }
}

beforeEach(function () {
    $this->seedTeamHierarchy();

    $core = base_path('vendor/martin3r/platform-core/database/migrations');
    $this->artisan('migrate', [
        '--realpath' => true,
        '--path' => [$core . '/2026_06_17_181355_create_core_embeddings_table.php'],
    ])->run();

    config([
        'embeddings.default_provider' => 'fake',
        'foodalchemist.semantic_search.enabled' => true,
        'foodalchemist.semantic_search.provider' => 'fake',
    ]);
    $this->provider = new LaBoomProvider(64);
    $this->app->forgetInstance(EmbeddingProviderRegistry::class);
    $this->app->singleton(EmbeddingProviderRegistry::class, function () {
        $r = new EmbeddingProviderRegistry();
        $r->register($this->provider);

        return $r;
    });
    $this->app->forgetInstance(EmbeddingService::class);
    Queue::fake();   // Observer-Jobs (created) erfassen, nicht ausführen — der Roh-Insert-Fall

    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Lieferant']);
    $this->mkLa = function (string $designation, ?string $brand = null): int {
        return (int) FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => $designation, 'brand' => $brand, 'unit_code' => 'kg',
        ])->id;
    };
    $this->vec = fn () => DB::table('core_embeddings')->where('entity_type', PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM);
    $this->lauf = fn (array $opt = []) => app(SupplierItemEmbedBackfill::class)->run($opt);
});

it('--missing bettet nur Artikel ohne Vektor ein und fasst Vorhandenes nicht an', function () {
    $ids = [];
    foreach (['Rinderhackfleisch 5 kg', 'Kartoffel festkochend', 'Zanderfilet TK', 'Olivenöl nativ', 'Basmatireis'] as $n) {
        $ids[] = ($this->mkLa)($n);
    }
    app(PoolEmbeddingService::class)->embedSupplierItems();
    expect(($this->vec)()->count())->toBe(5);

    // zwei Vektoren verlieren (= Roh-Insert ohne Observer), einen vorhandenen als Marker festhalten
    ($this->vec)()->whereIn('entity_id', [(string) $ids[1], (string) $ids[3]])->delete();
    $marker = ($this->vec)()->where('entity_id', (string) $ids[0])->value('updated_at');
    $this->provider->calls = 0;

    $res = ($this->lauf)(['missing' => true]);

    expect($res['scanned'])->toBe(5)
        ->and($res['missing'])->toBe(2)
        ->and($res['embedded'])->toBe(2)
        ->and($res['failed_batches'])->toBe(0)
        ->and($res['stopped'])->toBeNull()
        ->and(($this->vec)()->count())->toBe(5)
        ->and($this->provider->calls)->toBe(1)                       // EIN Provider-Aufruf, nur für die zwei
        ->and(($this->vec)()->where('entity_id', (string) $ids[0])->value('updated_at'))->toBe($marker);

    // zweiter Lauf: nichts mehr zu tun, kein Provider-Aufruf
    $this->provider->calls = 0;
    expect(($this->lauf)(['missing' => true])['missing'])->toBe(0)
        ->and($this->provider->calls)->toBe(0);
});

it('--ids bettet nur die genannten Artikel ein', function () {
    $a = ($this->mkLa)('Artikel A');
    $b = ($this->mkLa)('Artikel B');
    ($this->mkLa)('Artikel C');

    $res = ($this->lauf)(['ids' => [$a, $b]]);

    expect($res['embedded'])->toBe(2)
        ->and(($this->vec)()->pluck('entity_id')->sort()->values()->all())->toBe([(string) $a, (string) $b]);
});

it('--limit begrenzt den Lauf, der Folgelauf macht dort weiter', function () {
    foreach (range(1, 5) as $i) {
        ($this->mkLa)("Artikel {$i}");
    }

    $res = ($this->lauf)(['missing' => true, 'limit' => 3, 'batch' => 2]);
    expect($res['embedded'])->toBe(3)
        ->and($res['stopped'])->toBe('limit')
        ->and(($this->vec)()->count())->toBe(3);

    $res = ($this->lauf)(['missing' => true, 'limit' => 3, 'batch' => 2]);
    expect($res['embedded'])->toBe(2)
        ->and($res['stopped'])->toBeNull()
        ->and(($this->vec)()->count())->toBe(5);
});

it('ein Provider-Fehler verwirft nur den Stapel, der Lauf geht weiter und meldet die IDs', function () {
    $ok1 = ($this->mkLa)('Gut eins');
    $bad = ($this->mkLa)('boom Artikel');
    $ok2 = ($this->mkLa)('Gut zwei');

    $res = ($this->lauf)(['missing' => true, 'batch' => 1]);

    expect($res['embedded'])->toBe(2)
        ->and($res['failed_batches'])->toBe(1)
        ->and($res['failed_ids'])->toBe([$bad])
        ->and($res['stopped'])->toBeNull()
        ->and(($this->vec)()->pluck('entity_id')->sort()->values()->all())->toBe([(string) $ok1, (string) $ok2]);

    // der fehlerhafte Artikel bleibt „fehlend" → der Folgelauf versucht ihn erneut
    expect(($this->lauf)(['missing' => true, 'batch' => 1])['missing'])->toBe(1);
});

it('bricht nach zu vielen Stapelfehlern in Folge ab, statt den ganzen Bestand durchzuprobieren', function () {
    foreach (range(1, SupplierItemEmbedBackfill::MAX_FEHLER_IN_FOLGE + 5) as $i) {
        ($this->mkLa)("boom {$i}");
    }

    $res = ($this->lauf)(['missing' => true, 'batch' => 1]);

    expect($res['stopped'])->toBe('fehler')
        ->and($res['failed_batches'])->toBe(SupplierItemEmbedBackfill::MAX_FEHLER_IN_FOLGE)
        ->and($this->provider->calls)->toBe(SupplierItemEmbedBackfill::MAX_FEHLER_IN_FOLGE);
});

it('das Zeitfenster bricht sauber ab, bevor der nächste Stapel läuft', function () {
    ($this->mkLa)('Artikel A');

    $res = ($this->lauf)(['missing' => true, 'until' => now()->subMinute()]);

    expect($res['stopped'])->toBe('zeitfenster')
        ->and($res['embedded'])->toBe(0)
        ->and(($this->vec)()->count())->toBe(0);
});

it('--dry-run zählt nur und ruft den Provider nicht', function () {
    ($this->mkLa)('Artikel A');
    ($this->mkLa)('Artikel B');

    $res = ($this->lauf)(['missing' => true, 'dry_run' => true]);

    expect($res['embedded'])->toBe(2)
        ->and($this->provider->calls)->toBe(0)
        ->and(($this->vec)()->count())->toBe(0);
});

it('klassifiziert nichts: Text aus der Rohbezeichnung, spätere Klassifikation ändert den Hash und läuft im Vollabgleich nach', function () {
    $id = ($this->mkLa)('Rinderhackfleisch frisch', 'Hausmarke');

    ($this->lauf)(['missing' => true]);

    expect(FoodAlchemistSupplierItemStructure::query()->count())->toBe(0)   // --missing legt keine Structure an
        ->and(($this->vec)()->count())->toBe(1);
    $hashRoh = ($this->vec)()->value('source_hash');
    expect($hashRoh)->toBe(hash('sha256',
        app(PoolEmbeddingService::class)->supplierItemEmbedText((object) [
            'designation' => 'Rinderhackfleisch frisch', 'marketing_name' => null, 'brand' => 'Hausmarke',
            'main_ingredient_display' => null, 'gp_name_derived' => null,
        ])
    ));

    // später klassifiziert (eigener Vorgang, nicht dieser Befehl)
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $id,
        'main_ingredient_display' => 'Rindfleisch', 'gp_name_derived' => 'Rinderhack: frisch',
    ]);

    // --missing fasst den vorhandenen Vektor NICHT an …
    expect(($this->lauf)(['missing' => true])['missing'])->toBe(0)
        ->and(($this->vec)()->value('source_hash'))->toBe($hashRoh);

    // … der reguläre Vollabgleich holt den geänderten Text nach (Hash ändert sich)
    app(PoolEmbeddingService::class)->embedSupplierItems();
    expect(($this->vec)()->count())->toBe(1)
        ->and(($this->vec)()->value('source_hash'))->not->toBe($hashRoh);
});

it('Qdrant: liest den Bestand per Scroll statt je Artikel einen Hash-Abruf zu machen', function () {
    config(['embeddings.qdrant' => ['url' => 'http://qdrant.test', 'api_key' => null, 'timeout' => 5, 'collection_prefix' => 'emb']]);
    $points = [];     // collection → [pointId => payload]
    $created = [];
    $log = ['get_point' => 0, 'scroll' => 0, 'upsert' => 0];

    Http::fake(function (Request $req) use (&$points, &$created, &$log) {
        $path = parse_url($req->url(), PHP_URL_PATH);
        $m = [];
        if (preg_match('#^/collections/([^/]+)/points/scroll$#', $path, $m)) {
            $log['scroll']++;
            $must = collect($req['filter']['must'] ?? []);
            $team = $must->firstWhere('key', 'team_id')['match']['value'] ?? null;
            $type = $must->firstWhere('key', 'entity_type')['match']['value'] ?? null;
            $hits = collect($points[$m[1]] ?? [])
                ->filter(fn ($p) => $p['team_id'] === $team && $p['entity_type'] === $type)
                ->map(fn ($p) => ['payload' => ['entity_id' => $p['entity_id']]])->values()->all();

            return Http::response(['result' => ['points' => $hits, 'next_page_offset' => null]]);
        }
        if (preg_match('#^/collections/([^/]+)/points/([0-9a-f-]{36})$#', $path, $m) && $req->method() === 'GET') {
            $log['get_point']++;
            $p = $points[$m[1]][$m[2]] ?? null;

            return $p ? Http::response(['result' => ['payload' => $p]]) : Http::response(['status' => 'not found'], 404);
        }
        if (preg_match('#^/collections/([^/]+)/points$#', $path, $m) && $req->method() === 'PUT') {
            $log['upsert']++;
            foreach ($req['points'] as $pt) {
                $points[$m[1]][$pt['id']] = $pt['payload'];
            }

            return Http::response(['result' => ['status' => 'completed']]);
        }
        if (preg_match('#^/collections/([^/]+)/index$#', $path)) {
            return Http::response(['result' => true]);
        }
        if (preg_match('#^/collections/([^/]+)$#', $path, $m)) {
            if ($req->method() === 'PUT') {
                $created[$m[1]] = true;

                return Http::response(['result' => true]);
            }

            return isset($created[$m[1]])
                ? Http::response(['result' => ['config' => ['params' => ['vectors' => ['size' => 64]]]]])
                : Http::response(['status' => 'not found'], 404);
        }

        return Http::response(['status' => 'unhandled '.$path], 500);
    });

    $registry = app(EmbeddingStoreRegistry::class);
    $registry->register('qdrant', new QdrantEmbeddingStore('http://qdrant.test'));
    $registry->route(PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM, 'qdrant');
    $this->app->forgetInstance(EmbeddingService::class);

    $ids = [];
    foreach (range(1, 6) as $i) {
        $ids[] = ($this->mkLa)("Artikel {$i}");
    }

    // erster Lauf: nichts vorhanden (Collection existiert noch nicht → Scroll 404 = leer)
    $res = ($this->lauf)(['missing' => true]);
    expect($res['embedded'])->toBe(6);
    $coll = array_key_first($points);
    expect($coll)->toBe('emb_fake_fake_bow_1')->and(count($points[$coll]))->toBe(6);

    // zwei Punkte verschwinden → Folgelauf bettet genau diese ein, mit EINEM Scroll
    $keys = array_keys($points[$coll]);
    unset($points[$coll][$keys[0]], $points[$coll][$keys[1]]);
    $log = ['get_point' => 0, 'scroll' => 0, 'upsert' => 0];

    $res = ($this->lauf)(['missing' => true]);

    expect($res['missing'])->toBe(2)
        ->and($res['embedded'])->toBe(2)
        ->and($log['scroll'])->toBe(1)
        ->and($log['get_point'])->toBe(2)         // nur Core-Hash-Abruf für die zwei fehlenden, NICHT für alle sechs
        ->and(count($points[$coll]))->toBe(6);
});

it('Qdrant: fehlende Sammlung (frische Instanz, eigener Präfix) heißt „alles fehlt“ — kein Fehler, Sammlung wird vom Store angelegt', function () {
    config(['embeddings.qdrant' => ['url' => 'http://qdrant.test', 'api_key' => null, 'timeout' => 5, 'collection_prefix' => 'fa']]);
    $created = [];
    $points = [];
    $scrollStatus = [];

    Http::fake(function (Request $req) use (&$created, &$points, &$scrollStatus) {
        $path = parse_url($req->url(), PHP_URL_PATH);
        $m = [];
        if (preg_match('#^/collections/([^/]+)/points/scroll$#', $path, $m)) {
            $scrollStatus[] = isset($created[$m[1]]) ? 200 : 404;

            return isset($created[$m[1]])
                ? Http::response(['result' => ['points' => [], 'next_page_offset' => null]])
                : Http::response(['status' => ['error' => 'Not found: Collection `'.$m[1].'` doesn\'t exist!']], 404);
        }
        if (preg_match('#^/collections/([^/]+)/points/([0-9a-f-]{36})$#', $path)) {
            return Http::response(['status' => 'not found'], 404);
        }
        if (preg_match('#^/collections/([^/]+)/points$#', $path, $m) && $req->method() === 'PUT') {
            foreach ($req['points'] as $pt) {
                $points[$m[1]][$pt['id']] = $pt['payload'];
            }

            return Http::response(['result' => ['status' => 'completed']]);
        }
        if (preg_match('#^/collections/([^/]+)/index$#', $path)) {
            return Http::response(['result' => true]);
        }
        if (preg_match('#^/collections/([^/]+)$#', $path, $m)) {
            if ($req->method() === 'PUT') {
                $created[$m[1]] = true;

                return Http::response(['result' => true]);
            }

            return isset($created[$m[1]]) ? Http::response(['result' => ['config' => ['params' => ['vectors' => ['size' => 64]]]]]) : Http::response([], 404);
        }

        return Http::response([], 500);
    });
    $registry = app(EmbeddingStoreRegistry::class);
    $registry->register('qdrant', new QdrantEmbeddingStore('http://qdrant.test', null, 5, null, 'fa'));
    $registry->route(PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM, 'qdrant');
    $this->app->forgetInstance(EmbeddingService::class);

    foreach (range(1, 3) as $i) {
        ($this->mkLa)("Artikel {$i}");
    }

    $res = ($this->lauf)(['missing' => true]);

    expect($scrollStatus)->toBe([404])                         // Scroll traf die fehlende Sammlung …
        ->and($res['missing'])->toBe(3)                         // … = alles fehlt
        ->and($res['embedded'])->toBe(3)
        ->and($res['failed_batches'])->toBe(0)
        ->and($res['stopped'])->toBeNull()
        ->and(array_keys($created))->toBe(['fa_fake_fake_bow_1'])   // vom Store beim ersten Upsert angelegt, mit dem Präfix der Instanz
        ->and(count($points['fa_fake_fake_bow_1']))->toBe(3);

    // --dry-run gegen eine fehlende Sammlung legt nichts an
    $created = [];
    $points = [];
    $dry = ($this->lauf)(['missing' => true, 'dry_run' => true]);
    expect($dry['missing'])->toBe(3)->and($created)->toBe([]);
});

it('Qdrant-Scroll mit Serverfehler bricht den Lauf ab, statt „alles fehlt“ anzunehmen', function () {
    config(['embeddings.qdrant' => ['url' => 'http://qdrant.test', 'api_key' => null, 'timeout' => 5, 'collection_prefix' => 'emb']]);
    Http::fake(['*' => Http::response(['status' => 'boom'], 500)]);
    $registry = app(EmbeddingStoreRegistry::class);
    $registry->register('qdrant', new QdrantEmbeddingStore('http://qdrant.test'));
    $registry->route(PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM, 'qdrant');
    ($this->mkLa)('Artikel A');

    expect(fn () => ($this->lauf)(['missing' => true]))->toThrow(RuntimeException::class, 'Qdrant-Scroll');
});

it('Befehl: --missing/--ids/--limit nur mit --pool=la, Eingaben werden geprüft, Lauf meldet Tabelle', function () {
    ($this->mkLa)('Artikel A');

    $this->artisan('foodalchemist:embed', ['--pool' => 'gps', '--missing' => true])->assertExitCode(2);
    $this->artisan('foodalchemist:embed', ['--pool' => 'la', '--ids' => '1,x'])->assertExitCode(2);
    $this->artisan('foodalchemist:embed', ['--pool' => 'la', '--until' => '25:99'])->assertExitCode(2);
    $this->artisan('foodalchemist:embed', ['--pool' => 'la', '--limit' => '0'])->assertExitCode(2);

    $this->artisan('foodalchemist:embed', ['--pool' => 'la', '--missing' => true, '--limit' => '10', '--sleep' => '0'])
        ->expectsOutputToContain('Nur Artikel ohne Vektor')
        ->assertExitCode(0);
    expect(($this->vec)()->count())->toBe(1);

    // ohne die neuen Optionen bleibt es beim Vollabgleich (altes Verhalten)
    ($this->vec)()->delete();
    $this->artisan('foodalchemist:embed', ['--pool' => 'la'])->assertExitCode(0);
    expect(($this->vec)()->count())->toBe(1);
});
