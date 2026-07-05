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

it('GT-13-1: Tokenizer — Umlaut-Expansion, Bindestrich splittet, ≥3 Zeichen', function () {
    $t = $this->svc->tokenize('Halve Hahn mit Holländer-Käse');

    expect($t)->toContain('hollaender')
        ->and($t)->toContain('kaese')
        ->and($t)->toContain('mit');                                 // bleibt: genau 3 Zeichen
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

it('GT-13-6: Discovery Stufe 2a — 3 Domains via Alias, alphabetisch, alle 7 Cross-Cutting', function () {
    ($this->seedGenerator)();

    $ctx = $this->svc->contextFor('ai_generate_recipe', 'Lachs mit brauner Butter und Walnuss');

    $slugs = array_map(fn ($f) => explode('@', $f)[0], $ctx['files_used']);
    foreach (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $cc) {
        expect($slugs)->toContain($cc);
    }
    $domains = array_values(array_intersect($slugs, ['fisch_seafood', 'milchprodukte', 'nuesse_saaten']));
    expect($domains)->toBe(['fisch_seafood', 'milchprodukte', 'nuesse_saaten'])  // 3 ≥ 2 → kein Fallback; alphabetisch
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

    $ctx = $this->svc->contextFor('ai_generate_recipe', '');

    expect(substr_count($ctx['block'], '## CROSS_CUTTING: '))->toBe(7)
        ->and(str_contains($ctx['block'], '## DOMAIN: '))->toBeFalse();
});

it('GT-13-9: Wissens-Quelle komplett leer → leerer Kontext, Call läuft weiter', function () {
    ($this->mkRouting)('ai_generate_recipe', 'cross_cutting', 'always');
    ($this->mkRouting)('ai_generate_recipe', 'domain', 'discovery');

    $ctx = $this->svc->contextFor('ai_generate_recipe', 'Lachs mit Butter');

    expect($ctx['block'])->toBe('')
        ->and($ctx['files_used'])->toBe([])
        ->and($ctx['total_chars'])->toBe(0);
});

it('GT-13-10: Pairing-Block klassisch — eine salbei-Zeile, nur Klassisch-Partner, Header-Hinweise', function () {
    ($this->seedGenerator)();
    ($this->mkDoc)('pairing.salbei', 'pairing', PAIRING_FIXTURE);

    $ctx = $this->svc->contextFor('ai_generate_recipe', 'Salbei-Gnocchi', 'klassisch');

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

    $ctx = $this->svc->contextFor('ai_infer_ankers', '', null, ['koriander', 'koriander']);  // Dupe-Input

    expect(substr_count($ctx['block'], '### Pairing-Doku: koriander_blatt'))->toBe(1)
        ->and(substr_count($ctx['block'], '### Pairing-Doku: koriander_saat'))->toBe(1)
        ->and(substr_count($ctx['block'], '[…gekürzt für KI-Kontext…]'))->toBe(2)
        ->and(str_contains($ctx['block'], str_repeat('B', 1401)))->toBeFalse();
});

it('Inv. 7: ai_extract_recipe bleibt BEWUSST ohne Wissen (Routing none)', function () {
    ($this->seedGenerator)();
    ($this->mkRouting)('ai_extract_recipe', 'cross_cutting', 'none');

    expect($this->svc->contextFor('ai_extract_recipe', 'Lachs mit Butter')['block'])->toBe('');
});

it('DoD: Assembly hält das Gesamtbudget — übergroße Docs auf ≈52k Zeichen gedeckelt', function () {
    ($this->seedGenerator)(str_repeat('D', 20000));
    DB::table('foodalchemist_knowledge_documents')->where('category', 'cross_cutting')
        ->update(['content_md' => str_repeat('C', 20000)]);
    ($this->mkDoc)('schwein', 'domain', str_repeat('D', 20000));      // 4. Domain via Fallback unmöglich — Aliase decken 3

    $ctx = $this->svc->contextFor('ai_generate_recipe', 'Lachs mit brauner Butter und Walnuss');

    // 7×4.000 + ≤4×6.000 + Header/Marker-Overhead < 53.000 (Spec: ≈52.000 ≈ 13k Tokens)
    expect($ctx['total_chars'])->toBeLessThan(53000)
        ->and(substr_count($ctx['block'], '[…gekürzt für KI-Kontext…]'))->toBe(7 + 3)
        ->and($ctx['total_chars'])->toBe(mb_strlen($ctx['block']));
});
