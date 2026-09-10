<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\Knowledge\WissensProfilService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · C0 + D6 — das Profil und seine drei Zustände.
 *
 * Der Anlass ist konkret: der Korpus wird inhaltlich neu aufgebaut. Der Kanon hängt an
 * `knowledge_document_id` — **Umbenennen ist harmlos, Löschen und Neuanlegen nicht.** Ohne die
 * Prüfungen hier liefe der Umbau in dieselbe stille Falle wie der 155-Originale-Cutover: die
 * Verknüpfung bleibt stehen, das Ziel ist weg, und `documentsFor()` überspringt lautlos.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = function (string $slug, bool $aktiv = true, int $zeichen = 500, bool $geloescht = false): int {
        $inhalt = str_repeat('x', max(10, $zeichen));

        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => 'regelwerk', 'content_md' => $inhalt,
            'version' => 3, 'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => $aktiv ? 1 : 0, 'created_via' => 'ui',
            'deleted_at' => $geloescht ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->mkKanon = function (int $docId, string $scopeKey, string $mode = 'pflicht'): void {
        DB::table('foodalchemist_knowledge_canon')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null,
            'scope' => 'prompt_key', 'scope_key' => $scopeKey, 'role' => 'root', 'ord' => 10,
            'knowledge_document_id' => $docId, 'mode' => $mode, 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->mkBindung = function (int $docId, string $targetKey): void {
        DB::table('foodalchemist_knowledge_bindings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null,
            'knowledge_document_id' => $docId, 'binding_type' => 'layer', 'target_key' => $targetKey,
            'mode' => 'always', 'weight' => 0, 'active' => 1, 'source' => 'ui',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('meldet eine Kanon-Zeile auf ein DEAKTIVIERTES Dossier als blockierenden Befund', function () {
    // Der stille Fall: documentsFor() filtert d.active=1, hasCanon() sagt weiter true. Der
    // Gateway baut den Block aus dem REST und faellt auch nicht auf die Bindungen zurueck.
    config(['foodalchemist.prompts' => ['test.key' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkKanon)(($this->mkDoc)('lebt'), 'test.key');
    ($this->mkKanon)(($this->mkDoc)('deaktiviert', aktiv: false), 'test.key');

    $p = app(WissensProfilService::class)->profil('test.key', $this->rootTeam);

    expect($p['zustand'])->toBe('fehlerhaft')
        ->and($p['kanon_aufgeloest'])->toBe(1)
        ->and($p['kanon_zeilen_vorhanden'])->toBeTrue()
        ->and(collect($p['befunde'])->pluck('code'))->toContain('dossier_inaktiv');
});

it('sieht eine Kanon-Zeile auf ein GELOESCHTES Dossier, die sonst nirgends sichtbar ist', function () {
    // zeilenQuery() filtert whereNull('d.deleted_at') — die Zeile ist damit auch aus list()
    // und hasCanon() verschwunden. Sie existiert in der DB und ist fuer jeden Lesepfad weg.
    config(['foodalchemist.prompts' => ['test.key' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkKanon)(($this->mkDoc)('geloescht', geloescht: true), 'test.key');

    $kaputt = app(KnowledgeCanonService::class)->unaufloesbareZeilen($this->rootTeam, 'test.key');

    expect($kaputt)->toHaveCount(1)
        ->and($kaputt[0]['grund'])->toBe('dossier_geloescht')
        ->and($kaputt[0]['schwere'])->toBe('blockiert')
        // Gegenprobe: der normale Lesepfad sieht sie NICHT.
        ->and(app(KnowledgeCanonService::class)->hasCanon('prompt_key', 'test.key', $this->rootTeam))->toBeFalse();
});

it('meldet Alt-Bindungen als Ballast — nicht mehr als lauernde Gefahr', function () {
    // ★ Die Aussage hat sich mit Spec 52 · F2 abgeschwaecht, und das ist der Fortschritt.
    //
    // Vorher hiess der Befund `bindung_wuerde_scharf`: die Bindungen waren stumm, SOLANGE
    // ein Kanon stand. Wurde ein Dossier HART geloescht, nahm der cascade-FK die Kanon-Zeile
    // mit, `hasCanon()` wurde false — und der Bindungs-Zweig hob veraltete Originale in den
    // Prompt. Ein Key wechselte still seine Wissensquelle.
    //
    // Der Gateway liest die Tabelle nicht mehr. Die Zeilen sind Ballast; der Befund sagt das
    // und nennt den Rueckweg, statt vor einer Zukunft zu warnen, die es nicht mehr gibt.
    config(['foodalchemist.prompts' => ['recipe.generator' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkKanon)(($this->mkDoc)('kanon-regel'), 'recipe.generator');
    ($this->mkBindung)(($this->mkDoc)('alte-bindung'), 'recipe.generator');

    $p = app(WissensProfilService::class)->profil('recipe.generator', $this->rootTeam);

    expect($p['alt_bindungen'])->toBe(['alte-bindung'])
        ->and(collect($p['befunde'])->pluck('code'))->toContain('bindung_altlast')
        ->and(collect($p['befunde'])->pluck('code'))->not->toContain('bindung_wuerde_scharf')
        ->and(collect($p['befunde'])->firstWhere('code', 'bindung_altlast')['text'])->toContain('UNBIND')
        // Ein Hinweis darf den Zustand NICHT kippen, sonst waeren die gesunden Keys fehlerhaft.
        ->and($p['zustand'])->toBe('gesteuert');
});

it('meldet Alt-Bindungen AUCH ohne Kanon — sie versorgen den Key ja nicht mehr', function () {
    // Vorher war der Befund an `$docs->isNotEmpty()` gehaengt: ohne Kanon galten die
    // Bindungen als die Versorgung und waren kein Befund. Heute versorgt sie niemand mehr,
    // also ist auch so ein Key ungesteuert — mit Ballast daneben.
    //
    // ⚠ Fixture-Hinweis: NICHT `recipe.generator` nehmen. Der loest ueber den Alt-Schluessel
    // auf `ai_generate_recipe` auf, und dafuer seeden die Migrationen Routings — der Key
    // waere zu Recht `gesteuert`, und der Test pruefte etwas anderes als er behauptet.
    // (Genau so gebaut und vom Lauf gefangen; vgl. feedback_testfixture_zeigt_migrationsstand.)
    config(['foodalchemist.prompts' => ['test.nur_altbindung' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkBindung)(($this->mkDoc)('nur-bindung-da'), 'test.nur_altbindung');

    $p = app(WissensProfilService::class)->profil('test.nur_altbindung', $this->rootTeam);

    expect(collect($p['befunde'])->pluck('code'))->toContain('bindung_altlast')
        ->and($p['zustand'])->toBe('ungesteuert');
});

it('meldet Pflichtwissen ueber Budget, weil Pflicht nie gekappt wird', function () {
    // `pflicht` ignoriert das Budget (WissenKanonBlockTest). Reisst es den Deckel, schrumpft
    // nicht die Pflicht, sondern alles andere — still.
    config([
        'foodalchemist.prompts' => ['test.eng' => ['tier' => 'B', 'task' => 'x']],
        'foodalchemist.ai.knowledge_budget' => ['test.eng' => 1000],
    ]);
    ($this->mkKanon)(($this->mkDoc)('gross', zeichen: 3000), 'test.eng');

    $p = app(WissensProfilService::class)->profil('test.eng', $this->rootTeam);

    expect($p['zustand'])->toBe('fehlerhaft')
        ->and(collect($p['befunde'])->pluck('code'))->toContain('pflicht_ueber_budget');
});

it('haelt den Fingerabdruck stabil und aendert ihn bei jeder Steuer-Aenderung', function () {
    config(['foodalchemist.prompts' => ['test.fp' => ['tier' => 'B', 'task' => 'x']]]);
    $doc = ($this->mkDoc)('regel-a');
    ($this->mkKanon)($doc, 'test.fp');
    $dienst = app(WissensProfilService::class);

    $vorher = $dienst->profil('test.fp', $this->rootTeam)['fingerabdruck'];
    expect($dienst->profil('test.fp', $this->rootTeam)['fingerabdruck'])->toBe($vorher);

    // Eine INHALTLICHE Ueberarbeitung aendert das Verhalten genauso wie eine neue Kanon-Zeile —
    // deshalb geht die Dossier-Version in den Fingerabdruck ein.
    DB::table('foodalchemist_knowledge_documents')->where('id', $doc)->update(['version' => 4]);
    expect($dienst->profil('test.fp', $this->rootTeam)['fingerabdruck'])->not->toBe($vorher);
});

it('unterscheidet bewusst_leer von ungesteuert', function () {
    config(['foodalchemist.prompts' => [
        'test.leer' => ['tier' => 'B', 'task' => 'x'],
        'test.nichts' => ['tier' => 'B', 'task' => 'x'],
    ]]);
    DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
        ['feature' => 'test.leer', 'category' => 'cross_cutting'],
        ['mode' => 'none', 'created_at' => now(), 'updated_at' => now()],
    );

    $dienst = app(WissensProfilService::class);

    expect($dienst->profil('test.leer', $this->rootTeam)['zustand'])->toBe('bewusst_leer')
        ->and($dienst->profil('test.nichts', $this->rootTeam)['zustand'])->toBe('ungesteuert');
});

it('MCP: knowledge_profil.GET liefert dasselbe und weist unbekannte Keys ab', function () {
    config(['foodalchemist.prompts' => ['test.key' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkKanon)(($this->mkDoc)('deaktiviert', aktiv: false), 'test.key');

    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $kontext = new ToolContext($user, $this->rootTeam);
    $tool = app(ToolRegistry::class)->get('foodalchemist.knowledge_profil.GET');

    $einzeln = $tool->execute(['prompt_key' => 'test.key'], $kontext);
    $alle = $tool->execute([], $kontext);

    expect($einzeln->success)->toBeTrue((string) ($einzeln->error ?? ''))
        ->and($einzeln->data['profil']['zustand'])->toBe('fehlerhaft')
        ->and($alle->data['blockierend'])->toBe(1)
        // Der Hinweis unterscheidet fehlende Quellen vom expliziten Budgetabbruch.
        ->and($alle->data['hinweis'])->toContain('dossier_*')
        ->and($alle->data['hinweis'])->toContain('der Modellaufruf wird bis zur Korrektur abgebrochen')
        ->and($tool->execute(['prompt_key' => 'gibt.es.nicht'], $kontext)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($tool->execute(['role' => 'quatsch'], $kontext)->errorCode)->toBe('VALIDATION_ERROR');
});

it('Kommando faellt mit Exit 1 durch, sobald Pflichtwissen nicht ankommt', function () {
    config(['foodalchemist.prompts' => ['test.key' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkKanon)(($this->mkDoc)('deaktiviert', aktiv: false), 'test.key');

    $this->artisan('foodalchemist:wissen-profil', ['--team' => $this->rootTeam->id, '--pruefen' => true])
        ->expectsOutputToContain('dossier_inaktiv')
        ->assertExitCode(1);
});
