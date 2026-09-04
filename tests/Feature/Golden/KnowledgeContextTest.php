<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M5-06: GL-13 Golden GT-13-1 … GT-13-11 (1:1 aus vault_context.rs) + Budget-
 * Assembly (DoD). Quelle ist die DB (D4) statt Disk — Fixtures werden als
 * knowledge_documents/aliases/routings geseedet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(KnowledgeContextService::class);

    $this->mkDoc = function (string $slug, string $kategorie, string $inhalt) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug,
            'category' => $kategorie, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $inhalt), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->mkAlias = function (string $alias, int $docId) {
        DB::table('foodalchemist_knowledge_aliases')->insert([
            'alias_slug' => $alias, 'knowledge_document_id' => $docId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->mkRouting = function (string $feature, string $kategorie, string $modus, ?int $maxDocs = null, ?int $maxChars = null) {
        DB::table('foodalchemist_knowledge_routings')->insert([
            'feature' => $feature, 'category' => $kategorie, 'mode' => $modus,
            'max_docs' => $maxDocs, 'max_chars_per_doc' => $maxChars,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->seedGenerator = function (string $domainInhalt = 'Domain-Wissen') {
        foreach (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $slug) {
            ($this->mkDoc)($slug, 'cross_cutting', "Wissen zu {$slug}");
        }
        $fisch = ($this->mkDoc)('fisch_seafood', 'domain', $domainInhalt);
        $milch = ($this->mkDoc)('milchprodukte', 'domain', $domainInhalt);
        $nuesse = ($this->mkDoc)('nuesse_saaten', 'domain', $domainInhalt);
        ($this->mkAlias)('lachs', $fisch);
        ($this->mkAlias)('butter', $milch);
        ($this->mkAlias)('walnuss', $nuesse);
        ($this->mkRouting)('ai_generate_recipe', 'cross_cutting', 'always');
        ($this->mkRouting)('ai_generate_recipe', 'domain', 'discovery');
        ($this->mkRouting)('ai_generate_recipe', 'pairing', 'discovery');
    };
});

// GT-13-3/4/5-Fixture (1:1 aus vault_context.rs:680ff)
const PAIRING_FIXTURE = "# Salbei\n"
    . "## Aromaprofil\nignore-aroma\n"
    . "## Pairings\n"
    . "### Klassisch — italienisch\n[[salbei|Salbei]] · [[butter|Butter]]\n"
    . "### Modern — Foodpairing-Hypothese und Avantgarde\n[[yuzu|Yuzu]] · [[matcha|Matcha]]\n"
    . "### Kontrast\n[[anchovis|Anchovis]]\n"
    . "## Verbund-Pairings\n### Trinitas\n[[trinitasx|TrinitasX]]\n"
    . "## Notizen\nignore [[noise|Noise]]\n";

it('GT-13-1: Tokenizer — Umlaut-Expansion, Bindestrich splittet, ≥3 Zeichen ohne Funktionswörter', function () {
    $t = $this->svc->tokenize('Halve Hahn mit Holländer-Käse und Jus');

    expect($t)->toContain('hollaender')
        ->and($t)->toContain('kaese')
        // Die Mindestlänge von 3 gilt weiter — belegt an einem 3-Zeichen-FACHWORT.
        // `aal`, `oel`, `jus`, `roh`, `bio` sind bedeutungstragend und müssen bleiben.
        ->and($t)->toContain('jus')
        // W0-6: Funktionswörter sind ab jetzt draußen. Vorher wurde `mit` zu einem
        // Ranking-Token und traf über den Substring-Term beliebige Slugs
        // (real beobachtet: `der` ⊂ `moderne`).
        ->and($t)->not->toContain('mit')
        ->and($t)->not->toContain('und');
});

it('GT-13-2: Jaccard {butter,eigelb}×{butter,zucker} = 1/3', function () {
    expect(abs($this->svc->jaccard(['butter', 'eigelb'], ['butter', 'zucker']) - 1 / 3))->toBeLessThan(0.001);
});

it('GT-13-3: Filter None nimmt die ganze Pairings-Region inkl. Verbund, Notizen raus', function () {
    $all = $this->svc->extractPairingNames(PAIRING_FIXTURE, null);

    expect($all)->toContain('Butter')->toContain('Yuzu')->toContain('Anchovis')->toContain('TrinitasX')
        ->and($all)->not->toContain('Noise');
});

it('GT-13-4: Filter Klassisch — Modern und Kontrast bleiben draußen', function () {
    $k = $this->svc->extractPairingNames(PAIRING_FIXTURE, ['Klassisch']);

    expect($k)->toContain('Butter')
        ->and($k)->not->toContain('Yuzu')
        ->and($k)->not->toContain('Anchovis');
});

it('GT-13-5: Filter gewagt (Modern+Kontrast) — Klassisch UND Verbund draußen', function () {
    $g = $this->svc->extractPairingNames(PAIRING_FIXTURE, ['Modern', 'Kontrast']);

    expect($g)->toContain('Yuzu')->toContain('Anchovis')
        ->and($g)->not->toContain('Butter')
        ->and($g)->not->toContain('TrinitasX');                      // Verbund = eigene ##-Sektion → Filter aus
});

it('GT-13-6: Discovery Stufe 2a — alle 3 passenden Domains via Alias, alle 7 Cross-Cutting', function () {
    ($this->seedGenerator)();

    $ctx = $this->svc->contextFor(null, 'ai_generate_recipe', 'Lachs mit brauner Butter und Walnuss');

    $slugs = array_map(fn ($f) => explode('@', $f)[0], $ctx['files_used']);
    foreach (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $cc) {
        expect($slugs)->toContain($cc);
    }
    $domains = array_values(array_intersect($slugs, ['fisch_seafood', 'milchprodukte', 'nuesse_saaten']));
    expect($domains)->toBe(['fisch_seafood', 'milchprodukte', 'nuesse_saaten'])
        ->and(substr_count($ctx['block'], '## DOMAIN: '))->toBeLessThanOrEqual(KnowledgeContextService::DOMAIN_TOP_K);
});

it('GT-13-7: Budget hart — 10.000 Zeichen → 6.000 + Marker; exakt 6.000 → ungekürzt', function () {
    $lang = str_repeat('x', 10000);
    $exakt = str_repeat('y', 6000);

    expect($this->svc->truncate($lang, 6000))->toBe(str_repeat('x', 6000) . "\n\n[…gekürzt für KI-Kontext…]")
        ->and($this->svc->truncate($exakt, 6000))->toBe($exakt);
});

it('GT-13-8: leere Beschreibung → nur Cross-Cutting, keine Domain, kein Fehler', function () {
    ($this->seedGenerator)();

    $ctx = $this->svc->contextFor(null, 'ai_generate_recipe', '');

    expect(substr_count($ctx['block'], '## CROSS_CUTTING: '))->toBe(7)
        ->and(str_contains($ctx['block'], '## DOMAIN: '))->toBeFalse();
});

it('GT-13-9: Wissens-Quelle komplett leer → leerer Kontext, Call läuft weiter', function () {
    ($this->mkRouting)('ai_generate_recipe', 'cross_cutting', 'always');
    ($this->mkRouting)('ai_generate_recipe', 'domain', 'discovery');

    $ctx = $this->svc->contextFor(null, 'ai_generate_recipe', 'Lachs mit Butter');

    expect($ctx['block'])->toBe('')
        ->and($ctx['files_used'])->toBe([])
        ->and($ctx['total_chars'])->toBe(0);
});

it('GT-13-10: Pairing-Block klassisch — eine salbei-Zeile, nur Klassisch-Partner, Header-Hinweise', function () {
    ($this->seedGenerator)();
    ($this->mkDoc)('pairing.salbei', 'pairing', PAIRING_FIXTURE);        // Stem-Quelle (pairingStems)

    // Graph-first (2026-07-13): Partner kommen aus dem Anker-Graphen, nicht aus der md.
    $mkAnker = function (string $slug, string $disp) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $disp,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $mkKante = function (int $a, int $b, string $typ) {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => $typ, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };
    $salbei = $mkAnker('salbei', 'Salbei');
    $butter = $mkAnker('butter', 'Butter');
    $yuzu = $mkAnker('yuzu', 'Yuzu');
    $mkKante($salbei, $butter, 'erprobt');                             // klassisch → erprobt: sichtbar
    $mkKante($salbei, $yuzu, 'aroma');                                 // aroma → unter »klassisch« rausgefiltert

    $ctx = $this->svc->contextFor(null, 'ai_generate_recipe', 'Salbei-Gnocchi', 'klassisch');

    preg_match_all('/^- salbei: (.+)$/m', $ctx['block'], $m);
    expect(count($m[0]))->toBe(1)
        ->and($m[1][0])->toContain('Butter')
        ->and($m[1][0])->not->toContain('Yuzu')
        ->and($ctx['block'])->toContain('Stil KLASSISCH')
        ->and($ctx['block'])->toContain('erfinde KEINE unbelegten Paarungen');
});

it('GT-13-11: Grounding koriander → beide Sorten-Dokus per Präfix, je 1.400 Z., dedupliziert', function () {
    ($this->mkRouting)('ai_infer_ankers', 'pairing', 'grounding', 3, 1400);
    ($this->mkDoc)('pairing.koriander_blatt', 'pairing', str_repeat('B', 2000));
    ($this->mkDoc)('pairing.koriander_saat', 'pairing', str_repeat('S', 2000));
    // Seit 2026-08-07 zieht groundingBlock die Stems aus dem Anker-Vokabular, nicht aus knowledge_documents.
    foreach (['koriander_blatt', 'koriander_saat'] as $s) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $s, 'display_de' => $s,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $ctx = $this->svc->contextFor(null, 'ai_infer_ankers', '', null, ['koriander', 'koriander']);  // Dupe-Input

    expect(substr_count($ctx['block'], '### Pairing-Doku: koriander_blatt'))->toBe(1)
        ->and(substr_count($ctx['block'], '### Pairing-Doku: koriander_saat'))->toBe(1)
        ->and(substr_count($ctx['block'], '[…gekürzt für KI-Kontext…]'))->toBe(2)
        ->and(str_contains($ctx['block'], str_repeat('B', 1401)))->toBeFalse();
});

it('Inv. 7: ai_extract_recipe bleibt BEWUSST ohne Wissen (Routing none)', function () {
    ($this->seedGenerator)();
    ($this->mkRouting)('ai_extract_recipe', 'cross_cutting', 'none');

    expect($this->svc->contextFor(null, 'ai_extract_recipe', 'Lachs mit Butter')['block'])->toBe('');
});

it('DoD: Assembly hält das Gesamtbudget — Rezeptwissen auf RECIPE_MAX_KNOWLEDGE_CHARS gedeckelt', function () {
    ($this->seedGenerator)(str_repeat('D', 20000));
    DB::table('foodalchemist_knowledge_documents')->where('category', 'cross_cutting')
        ->update(['content_md' => str_repeat('C', 20000)]);
    ($this->mkDoc)('schwein', 'domain', str_repeat('D', 20000));      // 4. Domain via Fallback unmöglich — Aliase decken 3

    $ctx = $this->svc->contextFor(null, 'ai_generate_recipe', 'Lachs mit brauner Butter und Walnuss');

    // +40 Toleranz für den Kürzungs-Marker, den truncate() NACH dem Deckel anhängt
    // (gleiche Toleranz wie RecipeKnowledgeBudgetTest).
    expect($ctx['total_chars'])->toBeLessThanOrEqual(KnowledgeContextService::RECIPE_MAX_KNOWLEDGE_CHARS + 40)
        ->and($ctx['total_chars'])->toBe(mb_strlen($ctx['block']))
        // Der Block endet im Marker = das Gesamtbudget hat wirklich zugeschlagen.
        ->and($ctx['block'])->toEndWith('[…gekürzt für KI-Kontext…]')
        // Jedes geladene Dossier ist zusätzlich pro Doc gekappt. Eine EXAKTE Marker-Zahl
        // (früher 7+3) ist seit W0-4 nicht mehr aussagekräftig: bei 12.000 Gesamtdeckel
        // und 2.400 pro Doc ist der Block schon zu Ende, bevor alle zehn Dossiers
        // angehängt sind — die hinteren Marker existieren gar nicht mehr im Ergebnis.
        ->and(substr_count($ctx['block'], '[…gekürzt für KI-Kontext…]'))->toBeGreaterThanOrEqual(2)
        // W0-0/W0-6: das Verworfene wird ausgewiesen statt still zu verschwinden.
        ->and($ctx['built_chars'])->toBeGreaterThan($ctx['total_chars'])
        ->and($ctx['dropped_chars'])->toBeGreaterThan(0);
});

// ── S1 (2026-08-07): generische, skalierbare discovery für wachsende Kategorien ──

it('S1: Niveau-Docs werden parametrisch geladen — nur die aktive Stufe (top_k=1)', function () {
    ($this->seedGenerator)();
    // Stufen-Docs in der Vault-Slug-Konvention (niveau.<datei>). Routing kommt aus der
    // Migration 2026_08_07_000001 (niveau:discovery, top_k 1) — der Test beweist die Verdrahtung mit.
    ($this->mkDoc)('niveau.niveau-1-haute-cuisine', 'niveau', 'Haute-Cuisine: Reduktionen, Praezision, Mise en Place.');
    ($this->mkDoc)('niveau.niveau-2-gehoben', 'niveau', 'Gehoben: solide Klassik, gute Produkte.');
    ($this->mkDoc)('niveau.niveau-3-klassisch', 'niveau', 'Klassisch: bewaehrte Hausmannskost.');

    $haute = $this->svc->contextFor(null, 'ai_generate_recipe', 'Rotwein-Schalotten-Reduktion', null, [], ['niveau' => 'haute_cuisine', 'rezept_typ' => 'gericht']);
    expect($haute['files_used'])->toContain('niveau.niveau-1-haute-cuisine@v1')
        ->and($haute['files_used'])->not->toContain('niveau.niveau-2-gehoben@v1')      // top_k=1 + Param-Wahl
        ->and($haute['files_used'])->not->toContain('niveau.niveau-3-klassisch@v1')
        ->and($haute['block'])->toContain('Haute-Cuisine: Reduktionen');

    // Anderer Parameter → andere Stufe (Beweis: parametrisch, nicht statisch)
    $klass = $this->svc->contextFor(null, 'ai_generate_recipe', 'Rotwein-Schalotten-Reduktion', null, [], ['niveau' => 'klassisch', 'rezept_typ' => 'gericht']);
    expect($klass['files_used'])->toContain('niveau.niveau-3-klassisch@v1')
        ->and($klass['files_used'])->not->toContain('niveau.niveau-1-haute-cuisine@v1');
});

it('S1: eine Kategorie OHNE Routing bleibt draußen (search-only ist gültig, kein Bloat)', function () {
    ($this->seedGenerator)();
    ($this->mkDoc)('regelwerk.gp-naming', 'regelwerk', 'Lange normative GP-Naming-Regeln.');

    // regelwerk ist bewusst NICHT für ai_generate_recipe geroutet → trotz Wort-Treffer nicht im Grounding.
    $ctx = $this->svc->contextFor(null, 'ai_generate_recipe', 'GP naming Regelwerk Schalotten', null, [], []);
    expect($ctx['files_used'])->not->toContain('regelwerk.gp-naming@v1');
});

// ── Kontext-Inspektor (2026-08-07): used_by_category gruppiert das Grounding je Kanal fürs UI ──

it('Kontext-Inspektor: used_by_category gruppiert je Kanal und deckt sich exakt mit files_used', function () {
    ($this->seedGenerator)();
    ($this->mkDoc)('niveau.niveau-1-haute-cuisine', 'niveau', 'Haute-Cuisine: Reduktionen, Praezision.');

    $ctx = $this->svc->contextFor(null, 'ai_generate_recipe', 'Lachs mit brauner Butter und Walnuss', null, [], ['niveau' => 'haute_cuisine', 'rezept_typ' => 'gericht']);

    // Rückgabe-Kontrakt: neuer Key da, gruppiert nach den erwarteten Kanälen.
    expect($ctx)->toHaveKey('used_by_category')
        ->and($ctx['used_by_category'])->toHaveKey('cross_cutting')->toHaveKey('domain')->toHaveKey('niveau');
    expect($ctx['used_by_category']['cross_cutting'])->toHaveCount(7)
        ->and($ctx['used_by_category']['domain'])->toHaveCount(3)
        ->and($ctx['used_by_category']['niveau'])->toBe(['niveau.niveau-1-haute-cuisine@v1']);

    // Delta-Trick korrekt: Union aller Gruppen == files_used (nichts verloren, nichts dupliziert).
    $flach = array_merge(...array_values($ctx['used_by_category']));
    sort($flach);
    $erwartet = $ctx['files_used'];
    sort($erwartet);
    expect($flach)->toBe($erwartet);
});

// ── Spec 37 (2026-08-07): Niveau-Auswahl ist TYP-ABHÄNGIG (Baustein/Komponente vs. Teller) ──

it('Spec 37: Basisrezept zieht den Basis-Niveau-Doc, Gericht den Teller-Doc (gleicher Level-Token)', function () {
    ($this->seedGenerator)();
    ($this->mkDoc)('niveau.niveau-1-haute-cuisine', 'niveau', 'Teller: 7-10 Komponenten, Menuegang.');
    ($this->mkDoc)('niveau.niveau-basis-1-haute-cuisine', 'niveau', 'Baustein: Technik an EINER Komponente, KEIN 7-10-Teller.');

    // Basisrezept → der Basis-Doc (…basis…), NICHT der Teller-Doc.
    $basis = $this->svc->contextFor(null, 'ai_generate_recipe', 'Tomatensuppe', null, [], ['niveau' => 'haute_cuisine', 'rezept_typ' => 'basisrezept']);
    expect($basis['used_by_category']['niveau'] ?? [])->toBe(['niveau.niveau-basis-1-haute-cuisine@v1'])
        ->and($basis['block'])->toContain('EINER Komponente');

    // Gericht → der Teller-Doc (kein …basis…), obwohl der Level-Token derselbe ist.
    $gericht = $this->svc->contextFor(null, 'ai_generate_recipe', 'Tomatensuppe', null, [], ['niveau' => 'haute_cuisine', 'rezept_typ' => 'gericht']);
    expect($gericht['used_by_category']['niveau'] ?? [])->toBe(['niveau.niveau-1-haute-cuisine@v1']);
});

it('Spec 37: ohne Niveau kein Niveau-Doc (Default egal, kein Fehl-Load)', function () {
    ($this->seedGenerator)();
    ($this->mkDoc)('niveau.niveau-basis-1-haute-cuisine', 'niveau', 'x');

    $ctx = $this->svc->contextFor(null, 'ai_generate_recipe', 'Tomatensuppe', null, [], ['rezept_typ' => 'basisrezept']);
    expect($ctx['used_by_category']['niveau'] ?? null)->toBeNull();
});
