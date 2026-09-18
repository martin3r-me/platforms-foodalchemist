<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket H Aufgabe B — Zutaten-Grounding je Aspekt.
 *
 * Befund (PREVIEW-Messung "Passionsfrucht-Gelee mit Acerola, 40 Portionen"): die generische
 * Fuzzy-Discovery liess `zutat.acerola_14--*` alle 4 Aspekte belegen, `zutat.passion_fruit--*` nur
 * 2 von 4, ein Gelée-Technik-Dossier fiel komplett raus. Grounding lädt stattdessen deterministisch
 * GENAU EIN Aspekt-Dossier je Hauptzutat, Aspekt aus dem Prompt-Key (`zutatAspektFuer()`).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkAnker = function (string $slug, string $displayDe) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $displayDe,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->mkZutatDoc = function (string $slug, string $inhalt) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug,
            'category' => 'zutat', 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->mkGroundingRouting = function (string $feature, int $maxDocs = 8) {
        DB::table('foodalchemist_knowledge_routings')->insert([
            'feature' => $feature, 'category' => 'zutat', 'mode' => 'grounding',
            'max_docs' => $maxDocs, 'max_chars_per_doc' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('laedt je Hauptzutat genau ein Aspekt-Dossier statt aller Aspekte gegeneinander konkurrieren zu lassen', function () {
    $feature = 'test.zutat_grounding_generator';
    config(['foodalchemist.prompts' => array_merge(
        (array) config('foodalchemist.prompts', []),
        [$feature => ['tier' => 'B', 'task' => 'x']],
    )]);
    ($this->mkAnker)('passionsfrucht', 'Passionsfrucht');
    ($this->mkAnker)('acerola', 'Acerola');
    foreach (['steckbrief', 'verwendung', 'verhalten', 'cave'] as $aspekt) {
        ($this->mkZutatDoc)("zutat.passionsfrucht--{$aspekt}", "Passionsfrucht {$aspekt}");
        ($this->mkZutatDoc)("zutat.acerola--{$aspekt}", "Acerola {$aspekt}");
    }
    ($this->mkGroundingRouting)($feature);

    $ctx = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, $feature, 'Passionsfrucht-Gelee mit Acerola', null,
        ['passionsfrucht', 'acerola'],
    );

    expect($ctx['files_used'])
        ->toContain('zutat.passionsfrucht--verwendung@v1')
        ->toContain('zutat.acerola--verwendung@v1')
        ->not->toContain('zutat.passionsfrucht--steckbrief@v1')
        ->not->toContain('zutat.passionsfrucht--verhalten@v1')
        ->not->toContain('zutat.passionsfrucht--cave@v1')
        ->not->toContain('zutat.acerola--steckbrief@v1')
        ->not->toContain('zutat.acerola--verhalten@v1')
        ->not->toContain('zutat.acerola--cave@v1');
    // genau 2 Zutaten-Dossiers, keine Aspekt-Häufung einer einzelnen Entität
    expect(array_filter($ctx['files_used'], fn ($f) => str_starts_with($f, 'zutat.')))->toHaveCount(2);
});

it('waehlt den Aspekt nach Prompt-Key: recipe.steps will "verhalten", gp.suggest will "steckbrief"', function () {
    ($this->mkAnker)('passionsfrucht', 'Passionsfrucht');
    foreach (['steckbrief', 'verwendung', 'verhalten', 'cave'] as $aspekt) {
        ($this->mkZutatDoc)("zutat.passionsfrucht--{$aspekt}", "Passionsfrucht {$aspekt}");
    }
    ($this->mkGroundingRouting)('recipe.steps');
    ($this->mkGroundingRouting)('gp.suggest');

    $steps = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.steps', 'Passionsfrucht-Gelee', null, ['passionsfrucht']);
    expect($steps['files_used'])->toContain('zutat.passionsfrucht--verhalten@v1');

    $gp = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'gp.suggest', 'Passionsfrucht', null, ['passionsfrucht']);
    expect($gp['files_used'])->toContain('zutat.passionsfrucht--steckbrief@v1');
});

it('findet nummerierte Anker-Varianten per Praefix (zutat.acerola_14--… bei Anker "acerola")', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkZutatDoc)('zutat.acerola_14--verwendung', 'Acerola verwendung');
    ($this->mkGroundingRouting)('recipe.generator');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Acerola-Sirup', null, ['acerola']);

    expect($ctx['files_used'])->toContain('zutat.acerola_14--verwendung@v1');
});

it('LIKE-Praefix trifft KEIN unverwandtes Dossier mit gemeinsamem Wortstamm (Apfel vs. Apfelsine)', function () {
    // Regression: `LIKE 'zutat.apfel_%--verwendung'` OHNE Regex-Nachpruefung traf frueher auch
    // "zutat.apfelsine--verwendung", weil '_' in LIKE ein BELIEBIGES Zeichen matcht (Orchestrierung,
    // 2026-09-18). Nur ein `_<Zahl>`-Suffix darf durchgehen.
    ($this->mkAnker)('apfel', 'Apfel');
    ($this->mkZutatDoc)('zutat.apfelsine--verwendung', 'Apfelsine verwendung');
    ($this->mkGroundingRouting)('recipe.generator');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Apfel-Kompott', null, ['apfel']);

    expect($ctx['files_used'])->not->toContain('zutat.apfelsine--verwendung@v1');
});

it('laedt BEIDE Teil-Dossiers eines Aspekts (Regelwerk Zutaten-Dossier §2: Teilung innerhalb der Frage)', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkZutatDoc)('zutat.acerola--verhalten-hitze', 'Acerola Verhalten unter Hitze');
    ($this->mkZutatDoc)('zutat.acerola--verhalten-saeure', 'Acerola Verhalten unter Saeure');
    ($this->mkZutatDoc)('zutat.acerola--verwendung', 'Acerola Verwendung');   // darf NICHT mitgezogen werden
    ($this->mkGroundingRouting)('recipe.steps');   // Aspekt "verhalten"

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.steps', 'Acerola-Sirup', null, ['acerola']);

    expect($ctx['files_used'])
        ->toContain('zutat.acerola--verhalten-hitze@v1')
        ->toContain('zutat.acerola--verhalten-saeure@v1')
        ->not->toContain('zutat.acerola--verwendung@v1');
});

it('laedt genau ein Dossier, wenn der Aspekt NICHT geteilt ist', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkZutatDoc)('zutat.acerola--verhalten', 'Acerola Verhalten');
    ($this->mkGroundingRouting)('recipe.steps');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.steps', 'Acerola-Sirup', null, ['acerola']);

    $zutatFiles = array_values(array_filter($ctx['files_used'], fn ($f) => str_starts_with($f, 'zutat.')));
    expect($zutatFiles)->toBe(['zutat.acerola--verhalten@v1']);
});

it('markiert eine Zutat ohne Anker-Treffer ehrlich statt sie zu ignorieren oder zu raten', function () {
    ($this->mkGroundingRouting)('recipe.generator');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Erfundenzutat-Extrakt', null, ['erfundenzutat-extrakt-ohne-anker']);

    expect(array_filter($ctx['files_used'], fn ($f) => str_starts_with($f, 'zutat.')))->toBeEmpty();
});

/**
 * Fund (Orchestrierung, 2026-09-18, Live-PREVIEW nach Deploy 15): NUR
 * RecipeGenerationContextService::build() übergibt Hauptzutat-Slugs an contextFor() — jeder andere
 * Aufrufer (KnowledgePreviewService, RecipeOneShotService::steps, StepEditor, DetailPanel,
 * Review/Conformance) liefert `[]`. Das Grounding zündete dadurch nirgends außer im
 * Generator-Pfad; die Routing-Zeile stand ohne Landebahn, und PREVIEW spiegelte die reale Pipeline
 * nicht. Fix: contextFor() leitet bei leeren $hauptzutatSlugs selbst Tokens aus der Beschreibung ab
 * (dieselbe Funktion wie build()s Vorsondierung, B4) und markiert das ehrlich in der Herkunft.
 */
it('leitet Hauptzutaten aus der Beschreibung ab, wenn der Aufrufer keine liefert (PREVIEW/steps-Pfad)', function () {
    ($this->mkAnker)('passionsfrucht', 'Passionsfrucht');
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkZutatDoc)('zutat.passionsfrucht--verwendung', 'Passionsfrucht Verwendung');
    ($this->mkZutatDoc)('zutat.acerola_14--verwendung', 'Acerola Verwendung');
    ($this->mkGroundingRouting)('recipe.generator');

    // Genau der PREVIEW-/RecipeOneShotService::steps-Fall: contextFor() OHNE Hauptzutat-Slugs.
    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Passionsfrucht-Gelee mit Acerola, 40 Portionen');

    expect($ctx['files_used'])
        ->toContain('zutat.passionsfrucht--verwendung@v1')
        ->toContain('zutat.acerola_14--verwendung@v1')
        ->and($ctx['herkunft']['zutat.passionsfrucht--verwendung']['via'])->toBe('zutat_grounding')
        ->and($ctx['herkunft']['zutat.passionsfrucht--verwendung']['hauptzutaten_quelle'])->toBe('abgeleitet')
        ->and($ctx['herkunft']['zutat.acerola_14--verwendung']['hauptzutaten_quelle'])->toBe('abgeleitet');
});

it('greift auch für recipe.steps ohne Caller-Slugs (RecipeOneShotService-Pfad)', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkZutatDoc)('zutat.acerola--verhalten', 'Acerola Verhalten');
    ($this->mkGroundingRouting)('recipe.steps');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.steps', 'Acerola-Sirup');

    expect($ctx['files_used'])->toContain('zutat.acerola--verhalten@v1')
        ->and($ctx['herkunft']['zutat.acerola--verhalten']['hauptzutaten_quelle'])->toBe('abgeleitet');
});

/**
 * Fund (Orchestrierung, 2026-09-18, Live-PREVIEW nach Deploy 16): `zutatDocs()` nutzte
 * `nurFuerPrompt()`, das bei aktivem Arten-Routing (fachwissen/referenz/datenwerk-Zeilen existieren
 * für das Feature — auf demo für `recipe.steps` der Fall) ALLE Dokumente mit gesetztem `art`
 * komplett ausblendet, weil die separate art-Discovery-Schleife sie holen soll. Zutaten-Dossiers
 * tragen `art = fachwissen` (Regelwerk Zutaten-Dossier §8.2) — für den Grounding-Slug-Lookup wurden
 * sie dadurch unsichtbar (`ohne_dossier`), obwohl dieselben Docs im selben Lauf über die
 * art-Discovery gefunden wurden. Test-Fixture spiegelt exakt den demo-Stand: `art = fachwissen`
 * AUF DEM DOSSIER UND eine aktive Arten-Routing-Zeile fürs Feature — ohne beides bleibt der Test
 * grün, während demo rot ist (das ist der Fehler, der gerade passiert ist).
 */
it('findet Zutat-Dossiers auch bei aktivem Arten-Routing (art=fachwissen war unter nurFuerPrompt() unsichtbar)', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'zutat.acerola--verhalten', 'title' => 'zutat.acerola--verhalten',
        'category' => 'zutat', 'art' => 'fachwissen', 'content_md' => 'Acerola Verhalten unter Hitze und Saeure',
        'version' => 1, 'content_hash' => hash('sha256', 'zutat.acerola--verhalten'), 'char_count' => 40,
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    ($this->mkGroundingRouting)('recipe.steps');
    // Arten-Routing aktiv wie auf demo: eine fachwissen-Zeile fuers selbe Feature.
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'recipe.steps', 'category' => '', 'art' => 'fachwissen', 'mode' => 'discovery',
        'max_docs' => 3, 'max_chars_per_doc' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.steps', 'Acerola-Sirup', null, ['acerola']);

    expect($ctx['files_used'])->toContain('zutat.acerola--verhalten@v1')
        ->and($ctx['herkunft']['zutat.acerola--verhalten']['status'] ?? null)->not->toBe('ohne_dossier');
});

it('laedt dasselbe Zutat-Dossier nicht doppelt, wenn Grounding UND die art-Discovery-Schleife es faenden', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'zutat.acerola--verhalten', 'title' => 'zutat.acerola--verhalten',
        'category' => 'zutat', 'art' => 'fachwissen', 'content_md' => 'Acerola Verhalten unter Hitze und Saeure — '.str_repeat('x', 400),
        'version' => 1, 'content_hash' => hash('sha256', 'zutat.acerola--verhalten'), 'char_count' => 440,
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    ($this->mkGroundingRouting)('recipe.steps');
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'recipe.steps', 'category' => '', 'art' => 'fachwissen', 'mode' => 'discovery',
        'max_docs' => 3, 'max_chars_per_doc' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Query enthaelt "acerola verhalten hitze saeure" -> dasselbe Dossier waere auch fuer die
    // generische art-Discovery ein Treffer, wenn es nicht ausgeschlossen wuerde.
    $ctx = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'recipe.steps', 'Acerola-Sirup mit Verhalten unter Hitze und Saeure', null, ['acerola'],
    );

    $treffer = array_count_values($ctx['files_used']);
    expect($treffer['zutat.acerola--verhalten@v1'] ?? 0)->toBe(1);
});

it('markiert Caller-gelieferte Hauptzutaten weiterhin als "caller", keine Vermischung mit der Ableitung', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkZutatDoc)('zutat.acerola--verwendung', 'Acerola Verwendung');
    ($this->mkGroundingRouting)('recipe.generator');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Ein Dessert', null, ['acerola']);

    expect($ctx['herkunft']['zutat.acerola--verwendung']['hauptzutaten_quelle'])->toBe('caller');
});

/**
 * Fund (Orchestrierung, 2026-09-18, Live-PREVIEW nach Deploy 17): weil `--verwendung` durch das
 * Grounding aus dem Discovery-Kandidatenpool verschwunden ist, wählte der Gruppen-Dedup (Aufgabe A)
 * den nächsten Vertreter derselben Familie (`--steckbrief`) — jede Zutat landete ZWEIMAL im Prompt.
 * Genau das "zweite zufällige" Dossier, das Grounding verhindern sollte. Fixture spiegelt den
 * demo-Stand: eine `zutat`-Kategorie-Discovery-Zeile UND eine Grounding-Zeile fürs selbe Feature.
 */
it('sperrt die ganze Zutat-Familie fuer Discovery, wenn Grounding sie schon versorgt hat', function () {
    // Demo-realistisch: Zutat-Dossiers tragen art=fachwissen (Regelwerk Zutaten-Dossier §8.2) und
    // werden ohne eigene category=zutat-Zeile ueber das art-Auffangnetz gefunden (§4a).
    ($this->mkAnker)('acerola', 'Acerola');
    DB::table('foodalchemist_knowledge_documents')->insert([
        ['uuid' => (string) UuidV7::generate(), 'slug' => 'zutat.acerola--verwendung', 'title' => 'zutat.acerola--verwendung',
            'category' => 'zutat', 'art' => 'fachwissen', 'content_md' => 'Acerola Verwendung unter Hitze und Saeure',
            'version' => 1, 'content_hash' => hash('sha256', 'zutat.acerola--verwendung'), 'char_count' => 40,
            'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => (string) UuidV7::generate(), 'slug' => 'zutat.acerola--steckbrief', 'title' => 'zutat.acerola--steckbrief',
            'category' => 'zutat', 'art' => 'fachwissen', 'content_md' => 'Acerola Steckbrief Einkauf Handelsformen unter Hitze und Saeure',
            'version' => 1, 'content_hash' => hash('sha256', 'zutat.acerola--steckbrief'), 'char_count' => 60,
            'active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);
    ($this->mkGroundingRouting)('recipe.generator');
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'recipe.generator', 'category' => '', 'art' => 'fachwissen', 'mode' => 'discovery',
        'max_docs' => 3, 'max_chars_per_doc' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Acerola-Sirup', null, ['acerola']);

    $zutatFiles = array_values(array_filter($ctx['files_used'], fn ($f) => str_starts_with($f, 'zutat.')));
    expect($zutatFiles)->toBe(['zutat.acerola--verwendung@v1'])
        ->and($zutatFiles)->not->toContain('zutat.acerola--steckbrief@v1');
});

/**
 * Fund (Orchestrierung, 2026-09-18): `leitTokens()` liefert aus "Passionsfrucht-Gelee" auch das
 * Token "gelee" — die alte fuzzige Auflösung (`resolveByName()`/Wort-Fragmente ≥4 Zeichen) fand
 * damit einen Anker, dessen `display_de` NUR zufällig "Gelee" enthält (hier: "Apfel Gelee"), nicht
 * die gemeinte Zutat. Exakte Auflösung darf das nicht tun.
 */
it('loest Anker nur bei EXAKTER Gleichheit auf, kein Wort-Fragment-Treffer ("gelee" darf nicht auf "Apfel Gelee" matchen)', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkAnker)('apple_jelly', 'Apfel Gelee');
    ($this->mkZutatDoc)('zutat.acerola--verwendung', 'Acerola Verwendung');
    ($this->mkZutatDoc)('zutat.apple_jelly--verwendung', 'Apfelgelee Verwendung');
    ($this->mkGroundingRouting)('recipe.generator');

    $ctx = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'recipe.generator', 'Passionsfrucht-Gelee mit Acerola', null, ['gelee', 'acerola'],
    );

    expect($ctx['files_used'])
        ->toContain('zutat.acerola--verwendung@v1')
        ->not->toContain('zutat.apple_jelly--verwendung@v1')
        ->and($ctx['herkunft']['zutat:gelee']['status'] ?? null)->toBe('ohne_anker');
});

/**
 * Fund (Orchestrierung, 2026-09-18, Live-PREVIEW nach Deploy 17): PREVIEW zeigte `sent: 0` für
 * Grounding-Treffer, obwohl der Text im Prompt stand (total_chars stimmte). Ursache: die
 * Herkunft wurde mit `"{$doc->slug}@v{$doc->version}"` als Key geschrieben, contextFor()s
 * Schluss-Korrektur vergleicht aber gegen nackte Slugs — der Vergleich traf nie, `sent` wurde immer
 * auf 0 zurückgesetzt.
 */
it('meldet "sent" korrekt fuer geladene Grounding-Dossiers, nicht 0 trotz gesendetem Text', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkZutatDoc)('zutat.acerola--verwendung', 'Acerola Verwendung unter Hitze und Saeure');
    ($this->mkGroundingRouting)('recipe.generator');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Acerola-Sirup', null, ['acerola']);

    expect($ctx['herkunft']['zutat.acerola--verwendung']['sent'])->toBeGreaterThan(0);
});

/**
 * Fund (Orchestrierung, 2026-09-18, Briefing Zutaten-Bulk-Import Punkt 5, bestätigt): `max_docs`
 * zählt ZUTATEN (Anker), nicht Dokumente — eine Zutat mit geteiltem Aspekt darf nicht zwei der
 * Plätze für sich beanspruchen. Vorher zählte `zutatGroundingBlock()` `count($blocks)` (Dokumente):
 * bei `max_docs=2` hätte eine geteilte Zutat (2 Teil-Dossiers) den Deckel allein erreicht und eine
 * zweite, vollständig unbeteiligte Zutat verdrängt.
 */
it('deckelt nach Zutaten, nicht nach Dokumenten — eine geteilte Zutat verdraengt keine andere', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkAnker)('passionsfrucht', 'Passionsfrucht');
    ($this->mkAnker)('vanille', 'Vanille');
    ($this->mkZutatDoc)('zutat.acerola--verwendung-hitze', 'Acerola Verwendung Hitze');
    ($this->mkZutatDoc)('zutat.acerola--verwendung-saeure', 'Acerola Verwendung Saeure');
    ($this->mkZutatDoc)('zutat.passionsfrucht--verwendung', 'Passionsfrucht Verwendung');
    ($this->mkZutatDoc)('zutat.vanille--verwendung', 'Vanille Verwendung');
    ($this->mkGroundingRouting)('recipe.generator', maxDocs: 2);

    $ctx = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, 'recipe.generator', 'Acerola-Dessert', null, ['acerola', 'passionsfrucht', 'vanille'],
    );

    $zutatFiles = array_values(array_filter($ctx['files_used'], fn ($f) => str_starts_with($f, 'zutat.')));
    // Zutat 1 (acerola, 2 Teil-Dossiers) + Zutat 2 (passionsfrucht, 1 Dossier) = 3 Dokumente, aber
    // genau 2 ZUTATEN — der Deckel greift erst vor der dritten Zutat (vanille).
    expect($zutatFiles)->toHaveCount(3)
        ->toContain('zutat.acerola--verwendung-hitze@v1')
        ->toContain('zutat.acerola--verwendung-saeure@v1')
        ->toContain('zutat.passionsfrucht--verwendung@v1')
        ->not->toContain('zutat.vanille--verwendung@v1');
});

/**
 * Fund (Orchestrierung, 2026-09-18, Zutaten-Bulk-Import Punkt 6): 194 von 11.966 realen Slugs
 * tragen einen Zähler INNERHALB des Teilstück-Suffix (`--verhalten-aroma-2`, `--steckbrief-sorten-2`),
 * weil ein Teilstück selbst nochmal geteilt werden musste. Das alte Suffix-Muster
 * `(-[a-z0-9_]+)?` (ohne '-' in der Zeichenklasse) bricht am zweiten Bindestrich ab.
 */
it('findet Teilstuecke mit Zaehler-Suffix (--verhalten-aroma-2)', function () {
    ($this->mkAnker)('acai_berry', 'Açai-Beere');
    ($this->mkZutatDoc)('zutat.acai_berry--verhalten-aroma-2', 'Acai Beere Verhalten Aroma Teil 2');
    ($this->mkGroundingRouting)('recipe.steps');   // Aspekt "verhalten"

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.steps', 'Acai-Bowl', null, ['acai_berry']);

    expect($ctx['files_used'])->toContain('zutat.acai_berry--verhalten-aroma-2@v1');
});

/**
 * Fund (Orchestrierung, 2026-09-18, Zutaten-Bulk-Import Punkt 4): ~12.000 zutat-Dossiers (art=
 * fachwissen) würden JEDE art-Discovery fluten (RRF-Score-Abstand Rang1↔Rang18 nur 12% bei 18
 * Kandidaten — bei 12.000 ist der Budget-Schnitt beliebig). Zutaten-Wissen kommt ab jetzt
 * AUSSCHLIESSLICH über Grounding, nie über den generischen art=fachwissen-Auffangtopf.
 */
it('schliesst category=zutat aus der generischen art-Discovery aus — nur Grounding liefert Zutaten-Wissen', function () {
    ($this->mkAnker)('tomate', 'Tomate');
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'zutat.tomate--verwendung', 'title' => 'zutat.tomate--verwendung',
        'category' => 'zutat', 'art' => 'fachwissen', 'content_md' => 'Tomate Verwendung in der Suppe unter Hitze',
        'version' => 1, 'content_hash' => hash('sha256', 'zutat.tomate--verwendung'), 'char_count' => 40,
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Kein Grounding-Routing gesetzt -> nur die generische art-Discovery koennte das Dossier finden.
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'recipe.generator', 'category' => '', 'art' => 'fachwissen', 'mode' => 'discovery',
        'max_docs' => 3, 'max_chars_per_doc' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Tomatensuppe mit Basilikum');

    expect($ctx['files_used'])->not->toContain('zutat.tomate--verwendung@v1');
});
