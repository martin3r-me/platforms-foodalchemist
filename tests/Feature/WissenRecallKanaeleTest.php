<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Wissens-Recall — die drei Kanal-Befunde aus Lauf 65 („Crème-Suppe: Tomate-Speck").
 *
 * Gemessener Ist-Zustand vor dieser Runde: der Domain-Kanal lieferte für einen
 * Tomaten-Speck-Brief `blattgemuse-krauter-…`, `fermentierte-milchprodukte-…`,
 * `kaffee-tee-…`, `krauter-frisch-getrocknet` — also KEIN Tomaten-Dossier, obwohl vier
 * `fruchtgemuse-*` existieren. Drei Ursachen:
 *
 *  C1  `searchSlugs` holte global die Top-`limit*3` und filterte ERST DANACH auf die
 *      Kategorie ⇒ kleine Kategorien hungerten aus.
 *  C2  `discoverDomains` sortierte die Kandidaten ALPHABETISCH vor dem Top-4-Schnitt
 *      ⇒ der Anfangsbuchstabe entschied (die vier Chips standen exakt b < f < ka < kr).
 *  C4  Die Query war Brief + Reglerwerte — ohne Zutaten. Der Brief sagt „Tomatensuppe",
 *      nicht „Tomate".
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = function (string $slug, string $kategorie, ?string $inhalt = null): int {
        $inhalt ??= "Fachtext zu {$slug}. ".str_repeat('Inhalt ', 120);
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => (int) $this->rootTeam->id, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => $kategorie, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->alias = function (int $docId, string $aliasSlug): void {
        // Kein `uuid` — die Alias-Tabelle hat nur id/alias_slug/knowledge_document_id/timestamps.
        DB::table('foodalchemist_knowledge_aliases')->insert([
            'knowledge_document_id' => $docId, 'alias_slug' => $aliasSlug,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->route = function (string $feature, string $kategorie, string $mode = 'discovery', ?int $maxDocs = null): void {
        DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
            ['feature' => $feature, 'category' => $kategorie],
            ['mode' => $mode, 'max_docs' => $maxDocs, 'max_chars_per_doc' => 4000, 'created_at' => now(), 'updated_at' => now()],
        );
    };
    $this->slugs = fn (array $wissen) => array_map(fn ($f) => preg_replace('/@v\d+$/', '', $f), $wissen['files_used']);
});

// ── C2: Signal statt Alphabet ────────────────────────────────────────────────────────────

it('C2: der Domain-Kanal waehlt nach SIGNAL, nicht alphabetisch', function () {
    // Fünf Domänen, damit der Top-4-Schnitt wirklich schneidet. Der fachlich richtige
    // Treffer steht alphabetisch GANZ HINTEN — vorher hätte ihn `sort()` genau deshalb
    // verloren, denn a/b/c/d kommen alle vor „z…".
    ($this->mkDoc)('a-blattgemuese-krauter', 'domain');
    ($this->mkDoc)('b-fermentierte-milchprodukte', 'domain');
    ($this->mkDoc)('c-kaffee-tee-abgrenzung', 'domain');
    ($this->mkDoc)('d-weltkueche-uruguayisch', 'domain');
    $frucht = ($this->mkDoc)('z-fruchtgemuese-sorten', 'domain');
    // Alias = die stärkste, weil kuratierte Aussage „dieses Doc gehört zu Tomate".
    ($this->alias)($frucht, 'tomate');
    ($this->route)('ai_generate_recipe', 'domain');

    $wissen = app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'ai_generate_recipe', 'Cremesuppe mit Tomate und Speck');

    expect(($this->slugs)($wissen))->toContain('z-fruchtgemuese-sorten');
});

// ── C4: Komposita — „Tomatensuppe" muss „Tomate" erreichen ──────────────────────────────

it('C4: das Decompounding bringt die Hauptzutat aus dem Kompositum in die Query', function () {
    // Der Brief nennt NUR „Tomatensuppe" — genau der Wortlaut aus Session 119. Ohne
    // Decompounding trägt die Query kein Token „tomate", der Alias greift nicht.
    $frucht = ($this->mkDoc)('fruchtgemuese-sorten-uebersicht', 'domain');
    ($this->alias)($frucht, 'tomate');
    ($this->mkDoc)('blattgemuese-krauter', 'domain');
    ($this->route)('ai_generate_recipe', 'domain');

    $wissen = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'ai_generate_recipe',
        'Ich brauche ein Basisrezept mit einer Tomatensuppe, die mit Speck im Ansatz ist.',
    );

    expect(($this->slugs)($wissen))->toContain('fruchtgemuese-sorten-uebersicht');
});

it('C4: explizit übergebene Zutaten-Terme wirken ebenfalls (_zutat_terme)', function () {
    $frucht = ($this->mkDoc)('fruchtgemuese-sorten-uebersicht', 'domain');
    ($this->alias)($frucht, 'tomate');
    ($this->mkDoc)('blattgemuese-krauter', 'domain');
    ($this->route)('ai_generate_recipe', 'domain');

    // Beschreibung OHNE jeden Tomaten-Bezug — der Treffer kann nur über _zutat_terme kommen.
    $wissen = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'ai_generate_recipe', 'Ein cremiger Eintopf mit Speck.',
        null, [], ['_zutat_terme' => ['tomate']],
    );

    expect(($this->slugs)($wissen))->toContain('fruchtgemuese-sorten-uebersicht');
});

// ── C3: Stummel-Dossiers belegen keinen Slot ────────────────────────────────────────────

it('C3: ein Stummel-Dossier (Spec-50-Split „…--suchbegriffe") verdraengt kein echtes Dossier', function () {
    // 425 Zeichen ist die real gemessene Länge von
    // `produktion-arbeitszeit-und-personenminuten--suchbegriffe` auf demo.
    // `alias_slug` ist global UNIQUE — ein Alias gehoert genau EINEM Dossier. Beide
    // Aliase treffen das Query-Token „tomate" (Substring-Regel), also sind beide
    // Alias-Kandidaten mit gleichem Rang; entscheiden muss die Laenge.
    $stummel = ($this->mkDoc)('a-tomate--suchbegriffe', 'domain', str_repeat('x', 425));
    ($this->alias)($stummel, 'tomate');
    $echt = ($this->mkDoc)('z-fruchtgemuese-sorten', 'domain');
    ($this->alias)($echt, 'tomatensorte');
    ($this->route)('ai_generate_recipe', 'domain');

    $slugs = ($this->slugs)(app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'ai_generate_recipe', 'Cremesuppe mit Tomate'));

    expect($slugs)->toContain('z-fruchtgemuese-sorten')
        ->and($slugs)->not->toContain('a-tomate--suchbegriffe');
});

// ── C1: Kategorie-Filter VOR dem Schnitt ───────────────────────────────────────────────

it('C1: searchScoredSlugs schneidet je Kategorie, nicht global — kleine Kategorien hungern nicht aus', function () {
    // Der Beweis braucht keinen Provider: geprüft wird, dass der Recall-Pool WEIT ist und der
    // Kategorie-Filter danach greift. Vorher war der Pool `limit * 3` (bei limit=4 also 12) und
    // der Filter kam danach — eine Kategorie mit wenigen Docs fiel systematisch heraus.
    // 60 Docs in einer FREMDEN Kategorie drängen sich vor das eine gesuchte.
    for ($i = 0; $i < 60; $i++) {
        ($this->mkDoc)(sprintf('fremd-%02d', $i), 'kueche');
    }
    $gesucht = ($this->mkDoc)('fruchtgemuese-sorten-uebersicht', 'domain');

    // Fake-Provider: liefert ALLE Docs score-absteigend, das gesuchte als LETZTES —
    // also erst weit hinter Position 12. Nur ein weiter Pool + Filter-danach findet es.
    $alle = DB::table('foodalchemist_knowledge_documents')->orderBy('id')->pluck('id')->all();
    $reihenfolge = array_values(array_diff($alle, [$gesucht]));
    $reihenfolge[] = $gesucht;

    app()->bind(KnowledgeEmbeddingService::class, function () use ($reihenfolge) {
        return new class($reihenfolge) extends KnowledgeEmbeddingService
        {
            public function __construct(private array $reihenfolge)
            {
            }

            public function searchEnabled(): bool
            {
                return true;
            }

            public function isProviderAvailable(): bool
            {
                return true;
            }

            public function searchScoredSlugs(string $query, array $kategorien, int $limit = 4, ?float $minScore = null): array
            {
                // Genau die zu prüfende Reihenfolge: weiter Pool → Kategorie-Filter → Schnitt.
                $pool = max(1, (int) config('foodalchemist.semantic_search.recall_pool', 200));
                $ids = array_slice($this->reihenfolge, 0, $pool);
                $docs = DB::table('foodalchemist_knowledge_documents')
                    ->whereIn('id', $ids)->whereIn('category', $kategorien)
                    ->pluck('slug', 'id');
                $out = [];
                foreach ($ids as $id) {
                    if (($slug = $docs->get($id)) !== null) {
                        $out[(string) $slug] = 0.9;
                    }
                    if (count($out) >= $limit) {
                        break;
                    }
                }

                return $out;
            }
        };
    });
    config()->set('foodalchemist.semantic_search.enabled', true);
    ($this->route)('ai_generate_recipe', 'domain');

    $slugs = ($this->slugs)(app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'ai_generate_recipe', 'Cremesuppe'));

    expect($slugs)->toContain('fruchtgemuese-sorten-uebersicht');
});
