<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\WissensProfilService;
use Platform\FoodAlchemist\Tests\Support\SeedsKanon;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsKanon::class);

/**
 * Spec 52 · F4 — der `->first()`-Pfad ist weg, und sein Wegfall ist NICHT still.
 *
 * `regelwerkBlock()` wählte das Regelwerk per Slug-Muster und nahm davon `orderBy('slug')
 * ->first()`. Bei `%basisrezept%` sind das rund zwanzig §-Dossiers — das ist keine Auswahl,
 * das ist ein Los. Verbindliches Wissen kommt seit Spec 50 aus dem Kanon und seit F2
 * ausschliesslich von dort.
 *
 * ★ **Der eigentliche Grund für diese Datei ist nicht das Löschen, sondern das Loch dahinter.**
 * Der generische Discovery-Pfad verarbeitet ausschliesslich `mode = 'discovery'`. Eine stehen
 * gebliebene `regelwerk:always`-Zeile lädt damit **gar nichts** — kein Fehler, kein Log, nur
 * ein Prompt ohne Regeln. Genau die Sorte Verlust, gegen die diese Spec antritt. Eine
 * Migration hat den Bestand umgestellt; diese Tests fangen die nächste Zeile, die jemand von
 * Hand setzt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    $this->mkDoc = function (string $slug, int $chars = 900): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) Str::uuid(), 'team_id' => (int) $this->rootTeam->id, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => 'regelwerk',
            'content_md' => str_repeat('Regel ', (int) ceil($chars / 6)),
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => $chars,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->mkRouting = function (string $feature, string $mode, ?int $maxDocs = null, ?int $chars = null): void {
        DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
            ['feature' => $feature, 'category' => 'regelwerk'],
            ['mode' => $mode, 'max_docs' => $maxDocs, 'max_chars_per_doc' => $chars,
                'created_at' => now(), 'updated_at' => now()],
        );
    };

    // Die Migration direkt laden statt `artisan migrate`: die Fixture hat sie beim Aufbau
    // schon gefahren, geprüft werden soll aber ihr Verhalten auf einem GESTELLTEN Zustand.
    // `require` (nicht `require_once`) liefert bei jedem Aufruf eine neue anonyme Klasse.
    $this->migration = fn () => (require dirname(__DIR__, 2)
        .'/database/migrations/2026_09_08_000004_regelwerk_always_abschalten.php')->up();
});

it('eine regelwerk:always-Zeile liefert KEINEN Block mehr', function () {
    ($this->mkDoc)('regelwerk-basisrezepte-1-naming');
    ($this->mkDoc)('regelwerk-basisrezepte-2-verarbeitung');
    ($this->mkRouting)('ai_generate_recipe', 'always', 1, 6000);

    $wissen = app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'recipe.generator', 'Karottenpüree mit Ingwer');

    // Kein Block, keine Datei im Audit — und vor allem: kein alphabetisch gewürfeltes §-Dossier.
    expect($wissen['block'])->not->toContain('REGELWERK BASISREZEPTE')
        ->and(implode(' ', $wissen['files_used']))->not->toContain('regelwerk-basisrezepte-1-naming');
});

it('meldet die tote Zeile als BLOCKIERENDEN Befund — sonst wäre der Wegfall still', function () {
    // ★ Das ist der Kern. Ohne diesen Befund hätte F4 ein Loch derselben Bauart hinterlassen,
    // das es zumacht: Konfiguration, die aussieht wie „lädt immer" und nichts tut.
    config(['foodalchemist.prompts' => ['recipe.generator' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkRouting)('ai_generate_recipe', 'always', 1, 6000);   // Alt-Schlüssel, wie live

    $p = app(WissensProfilService::class)->profil('recipe.generator', $this->rootTeam);

    $befund = collect($p['befunde'])->firstWhere('code', 'routing_always_tot');
    expect($befund)->not->toBeNull()
        ->and($befund['schwere'])->toBe('blockiert')
        ->and($befund['text'])->toContain('knowledge_canon.PUT')
        ->and($p['zustand'])->toBe('fehlerhaft');
});

it('zählt eine tote Zeile NICHT als Pflichtmenge — sonst prüft die Invariante Phantasiewerte', function () {
    ($this->mkRouting)('w0tot.key', 'always', 5, 6000);

    expect(app(KnowledgeContextService::class)->pflichtZeichen('w0tot.key'))->toBe(0);
});

it('der Kanon liefert stattdessen — und zwar genau das kuratierte Dossier', function () {
    // Die Gegenprobe: nicht der Prompt ist ärmer geworden, die Quelle ist eine andere.
    ($this->mkDoc)('rwpfad-foodbook-grundgerust', 1886);
    ($this->mkDoc)('rwpfad-basisrezepte-1-naming');            // Ablenkung: darf NICHT kommen
    $this->kanonZeile((int) $this->rootTeam->id, 'foodbook.grundgeruest', 'rwpfad-foodbook-grundgerust');

    app(AiGatewayService::class)->propose('foodbook.grundgeruest', ['brief' => 'Sommerfest, 120 Gäste']);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'foodbook.grundgeruest')->latest('id')->first();
    expect((string) $log->knowledge_used)->toContain('rwpfad-foodbook-grundgerust')
        ->not->toContain('rwpfad-basisrezepte-1-naming')
        ->and(json_decode((string) $log->prompt_parts, true)['kanon'])->toBeGreaterThan(1800);
});

it('die Migration stellt Bestandszeilen um und legt den Ersatz-Kanon an', function () {
    // Der Frisch-DB- und der Bestandsfall in einem: eine `always`-Zeile plus GENAU EIN
    // passendes Dossier ⇒ Routing auf `none`, Kanon-Zeile darauf.
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'like', '%foodbook%')->delete();
    DB::table('foodalchemist_knowledge_canon')->where('scope_key', 'foodbook.grundgeruest')->delete();
    ($this->mkDoc)('regelwerk-foodbook-grundgerust', 1886);
    ($this->mkRouting)('foodbook.grundgeruest', 'always', null, null);

    ($this->migration)();

    expect(DB::table('foodalchemist_knowledge_routings')
        ->where('feature', 'foodbook.grundgeruest')->where('category', 'regelwerk')->value('mode'))->toBe('none')
        ->and(DB::table('foodalchemist_knowledge_canon as c')
            ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'c.knowledge_document_id')
            ->where('c.scope_key', 'foodbook.grundgeruest')->whereNull('c.deleted_at')
            ->pluck('d.slug')->all())->toBe(['regelwerk-foodbook-grundgerust']);
});

it('setzt Features OHNE Kanon-Ersatz auf discovery, nicht auf none', function () {
    // ★ Der Fehler, den mein erster Entwurf hatte: er setzte JEDES `always` auf `none`. Damit
    // wäre `concept.brief_geruest` durch diese Migration ärmer geworden als vorher — kein
    // always-Block UND keine Suche. Eine Aufräum-Migration darf nicht wegnehmen.
    //
    // `ai_generate_recipe` ist die Gegenprobe: dort IST `none` richtig, weil der Kanon trägt.
    // Deshalb eine explizite Ziel-Liste statt einer generischen Regel — genau wie in
    // `2026_09_08_000002`.
    ($this->mkRouting)('concept.brief_geruest', 'always', 1, 9000);
    ($this->mkRouting)('ai_generate_recipe', 'always', 1, 7000);
    ($this->mkRouting)('irgendein.fremdes_feature', 'always', 1, 5000);

    ($this->migration)();

    $modus = fn (string $f) => DB::table('foodalchemist_knowledge_routings')
        ->where('feature', $f)->where('category', 'regelwerk')->value('mode');

    expect($modus('concept.brief_geruest'))->toBe('discovery')
        ->and($modus('ai_generate_recipe'))->toBe('none')          // Kanon trägt es
        ->and($modus('irgendein.fremdes_feature'))->toBe('discovery');   // nie ärmer machen
});

it('die Migration ist idempotent — ein zweiter Lauf legt keine zweite Kanon-Zeile an', function () {
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'like', '%foodbook%')->delete();
    DB::table('foodalchemist_knowledge_canon')->where('scope_key', 'foodbook.grundgeruest')->delete();
    ($this->mkDoc)('regelwerk-foodbook-grundgerust', 1886);
    ($this->mkRouting)('foodbook.grundgeruest', 'always', null, null);

    ($this->migration)();
    ($this->migration)();

    expect(DB::table('foodalchemist_knowledge_canon')
        ->where('scope_key', 'foodbook.grundgeruest')->count())->toBe(1);
});

it('die Anti-Pattern-Regel steht im DOSSIER, nicht im Prompt — sie gehoert Dominique', function () {
    // ★ Dominique, 2026-09-08, nachdem ich sie in den Prompt-Task geschrieben hatte:
    // „dadurch kann ich den nicht mehr anpassen ueber das Wissen oder?" — genau so.
    //
    // Ich hatte den geloeschten Code-Header „ersetzen" wollen und dabei uebersehen, dass das
    // Dossier `regelwerk-foodbook-grundgerust` die Regel in §5 laengst traegt. Eine Kopie im
    // Prompt haette die Hoheit ueber eine kuratierbare Regel in den Code geholt — die
    // Doppelung, die diese Spec abbaut, im Abbau selbst gebaut.
    //
    // Der Test pinnt BEIDE Richtungen, weil nur eine davon zu wenig waere: die Regel muss im
    // Prompt FEHLEN und ueber den Kanon ANKOMMEN.
    $prompt = (array) config('foodalchemist.prompts.foodbook.grundgeruest');
    $promptText = mb_strtolower(($prompt['system'] ?? '').' '.($prompt['task'] ?? ''));

    expect($promptText)->not->toContain('vorspeisen')
        ->and($promptText)->not->toContain('desserts');

    // … und dieselbe Aussage kommt aus dem Dossier in den Prompt.
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) Str::uuid(), 'team_id' => (int) $this->rootTeam->id,
        'slug' => 'rwpfad-fb-antipattern', 'title' => 'Regelwerk Foodbook', 'category' => 'regelwerk',
        'content_md' => "# Regelwerk Foodbook\n\n## §5 Anti-Pattern\n- NIE «Vorspeisen / Hauptgänge / Desserts» als Top-Level-Kapitel.",
        'version' => 1, 'content_hash' => hash('sha256', 'rwpfad-fb-antipattern'), 'char_count' => 120,
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->kanonZeile((int) $this->rootTeam->id, 'foodbook.grundgeruest', 'rwpfad-fb-antipattern');

    app(AiGatewayService::class)->propose('foodbook.grundgeruest', ['brief' => 'Sommerfest']);

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'foodbook.grundgeruest')->latest('id')->first();
    expect((string) $log->knowledge_used)->toContain('rwpfad-fb-antipattern')
        ->and(json_decode((string) $log->prompt_parts, true)['kanon'])->toBeGreaterThan(100);
});
