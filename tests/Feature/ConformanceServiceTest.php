<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConformanceService;
use Platform\FoodAlchemist\Tests\Support\CopilotStub;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Schicht 3 · Slice 1 — der generische Konformitäts-Critic ({@see ConformanceService}).
 *
 * Beweist die Verdrahtung mit {@see CopilotStub} (kanonische Befunde statt echtem LLM):
 *  1. der explizite Generator-Kanon landet vollständig im Systemblock,
 *  2. Roh-Befunde werden zu normierten Regelverstößen (paragraph/schweregrad/feld/begründung),
 *  3. Rauschen (Befund ohne Begründung) fällt raus,
 *  4. unbekannter Schweregrad → 'weich' (Default = nur Hinweis, nie Block).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    // Ein aktives Regelwerk-Dossier, gegen das geprüft wird — der Marker beweist
    // später, dass sein VOLLER Text im Prompt steht.
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => $this->rootTeam->id,
        'slug' => 'regelwerk-basisrezepte-6-mengen-einheiten-yield',
        'title' => 'Regelwerk Basisrezepte §6 — Mengen, Einheiten & Yield',
        'category' => 'regelwerk',
        'content_md' => 'SCHICHT3_REGELWERK_MARKER §6.1: Produktnamen im Singular/Lemma.',
        'version' => 1,
        'content_hash' => str_repeat('a', 64),
        'char_count' => 60,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)->set($this->rootTeam, [
        'scope' => 'prompt_key', 'scope_key' => 'recipe.generator',
        'slug' => 'regelwerk-basisrezepte-6-mengen-einheiten-yield', 'mode' => 'pflicht',
    ]);

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id,
        'recipe_key' => 'bx-schicht3',
        'name' => 'Tomaten Gewürfelt',
        'status' => 'draft',
        'is_sales_recipe' => false,
    ]);
});

it('Slice 1: Regelwerk landet im Prompt + Roh-Befunde werden zu normierten Regelverstößen', function () {
    CopilotStub::bind([
        ['paragraph' => '§6.1', 'schweregrad' => 'hart', 'feld' => 'name',
            'begruendung' => 'Plural statt Singular', 'vorschlag' => 'Tomate: gewürfelt', 'konfidenz' => 0.9],
        ['paragraph' => '§8', 'schweregrad' => 'quatsch', 'feld' => 'kategorie',
            'begruendung' => 'Pflichtangabe fehlt', 'konfidenz' => 'hoch'],          // unbekannter Schweregrad → weich
        ['paragraph' => '§3', 'schweregrad' => 'weich', 'feld' => 'x', 'begruendung' => ''], // Rauschen → raus
    ], 'Zwei Verstöße gefunden.');

    $ergebnis = app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->rezept->id);

    // (1) Volles Regelwerk im Prompt
    expect($GLOBALS['l6_all_prompt'] ?? '')->toContain('SCHICHT3_REGELWERK_MARKER');

    // (2)+(3) zwei valide Befunde (der begründungslose fiel raus)
    expect($ergebnis['befunde'])->toHaveCount(2);
    expect($ergebnis['befunde'][0]['paragraph'])->toBe('§6.1');
    expect($ergebnis['befunde'][0]['schweregrad'])->toBe('hart');
    expect($ergebnis['befunde'][0]['feld'])->toBe('name');
    expect($ergebnis['befunde'][0]['vorschlag'])->toBe('Tomate: gewürfelt');

    // (4) unbekannter Schweregrad wird zu 'weich' entschärft
    expect($ergebnis['befunde'][1]['schweregrad'])->toBe('weich');
    expect($ergebnis['befunde'][1]['konfidenz'])->toBe(0.9);        // 'hoch' → 0.9

    expect($ergebnis['gesamturteil'])->toBe('Zwei Verstöße gefunden.');
});

it('Spec 50: ein `<slug>-changelog`-Split trifft den Regelwerk-Präfix, landet aber NICHT im Prompt', function () {
    // Der Split-Builder trennt den `## Changelog` als eigenes Dossier ab — gleicher Präfix,
    // gleiche Kategorie, aktiv. Versionshistorie ist keine Regel: der Critic darf sie nicht laden.
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => $this->rootTeam->id,
        'slug' => 'regelwerk-basisrezepte-changelog',
        'title' => 'Regelwerk Basisrezepte — Changelog',
        'category' => 'regelwerk',
        'content_md' => 'SCHICHT3_CHANGELOG_MARKER v1.1 (2026-05-22): §6 ergänzt.',
        'version' => 1,
        'content_hash' => str_repeat('b', 64),
        'char_count' => 55,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    CopilotStub::bind([], 'Keine Verstöße.');

    app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->rezept->id);

    expect($GLOBALS['l6_all_prompt'] ?? '')
        ->toContain('SCHICHT3_REGELWERK_MARKER')
        ->not->toContain('SCHICHT3_CHANGELOG_MARKER');
});

it('Slice 1: ohne aktives Regelwerk-Dossier wirft die Prüfung (keine Blind-Prüfung)', function () {
    DB::table('foodalchemist_knowledge_documents')->update(['active' => 0]);
    CopilotStub::bind([]);

    expect(fn () => app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->rezept->id))
        ->toThrow(RuntimeException::class);
});

it('Slice 1: unbekannter Artefakt-Typ wirft', function () {
    expect(fn () => app(ConformanceService::class)->pruefe($this->rootTeam, 'foobar-unbekannt', 1))
        ->toThrow(InvalidArgumentException::class);
});


it('C4: übernimmt denselben Pflichtkanon wie der Generator mit identischem Text-Fingerprint', function () {
    CopilotStub::bind([]);
    app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('recipe.generator', []);
    $canonBlock = fn () => collect($GLOBALS['l6_messages'])->where('role', 'system')
        ->first(fn ($m) => str_starts_with($m['content'], '# VERBINDLICHES REGELWERK'))['content'];
    $generator = $canonBlock();
    app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->rezept->id);
    expect(hash('sha256', $canonBlock()))->toBe(hash('sha256', $generator))
        ->and($GLOBALS['l6_user_prompt'])->not->toContain('SCHICHT3_REGELWERK_MARKER');
});

it('C4: fällt bei fehlendem Kanon nicht auf passende Slug-Präfixe zurück', function () {
    DB::table('foodalchemist_knowledge_canon')->delete();
    CopilotStub::bind([]);
    expect(fn () => app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->rezept->id))
        ->toThrow(RuntimeException::class, 'Pflichtkanon');
    expect(DB::table('foodalchemist_ai_call_log')->count())->toBe(0);
});

it('C4: weist externes Wissen am migrierten Gateway-Eingang ab', function () {
    expect(fn () => app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
        'conformance.check', ['artefakt_typ' => 'Basisrezept/Komponente'], ['knowledge' => 'Fremde Regeln']))
        ->toThrow(InvalidArgumentException::class, 'externe Wissensoptionen');
});

it('C4: stoppt vor dem Modell wenn der Pflichtkanon nicht ins Prüfbudget passt', function () {
    config()->set('foodalchemist.ai.knowledge_budget', ['conformance.check' => 30]);
    CopilotStub::bind([]);
    expect(fn () => app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->rezept->id))
        ->toThrow(\Platform\FoodAlchemist\Services\Ai\KnowledgeBudgetExceeded::class);
    expect(DB::table('foodalchemist_ai_call_log')->count())->toBe(0);
});


it('C4: ergänzt geroutetes Fachwissen im Userblock und protokolliert die Quellen', function () {
    app(\Platform\FoodAlchemist\Services\KnowledgeService::class)->create($this->rootTeam, [
        'slug' => 'tomaten-fachwissen', 'title' => 'Tomaten Gewürfelt', 'category' => 'cross_cutting',
        'art' => 'fachwissen', 'content_md' => 'GEROUTETES_FACHWISSEN bis ENDE.',
    ]);
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'conformance.check', 'art' => 'fachwissen', 'mode' => 'discovery',
        'max_docs' => 1, 'max_chars_per_doc' => 5, 'created_at' => now(), 'updated_at' => now(),
    ]);
    config()->set('foodalchemist.semantic_search.enabled', false);
    CopilotStub::bind([]);
    app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->rezept->id);
    expect($GLOBALS['l6_user_prompt'])->toContain('GEROUTETES_FACHWISSEN bis ENDE.');
    $system = collect($GLOBALS['l6_messages'])->where('role', 'system')->pluck('content')->implode('\n');
    expect($system)->not->toContain('GEROUTETES_FACHWISSEN');
    $used = json_decode(DB::table('foodalchemist_ai_call_log')->latest('id')->value('knowledge_used'), true);
    expect($used)->toContain('tomaten-fachwissen@v1', 'regelwerk-basisrezepte-6-mengen-einheiten-yield@v1');
});

it('C4: eine fehlende zweite Pflichtquelle verhindert eine unvollständige Prüfung', function () {
    app(\Platform\FoodAlchemist\Services\KnowledgeService::class)->create($this->rootTeam, [
        'slug' => 'zweite-pflichtregel', 'title' => 'Zweite Regel', 'category' => 'regelwerk',
        'content_md' => 'Zweite Pflichtregel.',
    ]);
    app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)->set($this->rootTeam, [
        'scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'slug' => 'zweite-pflichtregel', 'mode' => 'pflicht',
    ]);
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'zweite-pflichtregel')->update(['active' => 0]);
    CopilotStub::bind([]);
    expect(fn () => app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->rezept->id))
        ->toThrow(RuntimeException::class, 'Pflichtkanon');
    expect(DB::table('foodalchemist_ai_call_log')->count())->toBe(0);
});
