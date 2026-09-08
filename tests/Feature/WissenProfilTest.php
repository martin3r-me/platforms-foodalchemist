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

it('warnt, dass stumme Bindungen sich wieder scharf schalten wuerden', function () {
    // Der schwerste Umbau-Fall: wird ein Dossier HART geloescht, nimmt der cascade-FK die
    // Kanon-Zeile mit, hasCanon() wird false — und der Bindungs-Zweig im Gateway greift wieder.
    // Auf demo sind das 9 Bindungen auf den zwei Generator-Keys.
    config(['foodalchemist.prompts' => ['recipe.generator' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkKanon)(($this->mkDoc)('kanon-regel'), 'recipe.generator');
    ($this->mkBindung)(($this->mkDoc)('alte-bindung'), 'recipe.generator');

    $p = app(WissensProfilService::class)->profil('recipe.generator', $this->rootTeam);

    expect($p['lauernde_bindungen'])->toBe(['alte-bindung'])
        ->and(collect($p['befunde'])->pluck('code'))->toContain('bindung_wuerde_scharf')
        // Ein Hinweis darf den Zustand NICHT kippen, sonst waeren die gesunden Keys fehlerhaft.
        ->and($p['zustand'])->toBe('gesteuert');
});

it('meldet Pflichtwissen ueber Budget, weil Pflicht nie gekappt wird', function () {
    // `pflicht` ignoriert das Budget (WissenKanonBlockTest). Reisst es den Deckel, schrumpft
    // nicht die Pflicht, sondern alles andere — still.
    config([
        'foodalchemist.prompts' => ['test.eng' => ['tier' => 'B', 'task' => 'x']],
        'foodalchemist.ai.bound_knowledge_budget' => ['test.eng' => ['docs' => 5, 'chars_per_doc' => 4000, 'total' => 1000]],
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
        // Der Sammeltext MUSS die Faelle unterscheiden: `pflicht` ignoriert das Budget per
        // Vertrag, ein `pflicht_ueber_budget` heisst also nicht „kommt nicht an". Die erste
        // Fassung warf beides in einen Satz — dieser Test hielt den Ueberclaim fest.
        ->and($alle->data['hinweis'])->toContain('dossier_*')
        ->and($alle->data['hinweis'])->toContain('kommt an, aber der Deckel ist zu klein')
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
