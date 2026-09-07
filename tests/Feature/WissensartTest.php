<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\Knowledge\WissensProfilService;
use Platform\FoodAlchemist\Services\Knowledge\Wissensart;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · H1 — die Wissensart, und was sie am ersten Tag TUT.
 *
 * Die Kategorie sagt, worum es geht. Die Art sagt, wie das Wissen benutzt werden darf. Der
 * Anlass ist konkret: `workflow` mischt Handwerkswissen für den Prompt
 * (`workflow.basisrezept_erstellungs_dossier`) mit Agenten-Anleitungen
 * (`workflow.basisrezept_regeln`) — deshalb hat die Kategorie bis heute gar kein Routing, sie
 * ist als Steuergrösse zu grob.
 *
 * Das Feld ist deshalb nicht dekorativ: **`ablauf` kommt in keinen Prompt.**
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = function (string $slug, ?string $art = null, string $kategorie = 'cross_cutting'): int {
        $inhalt = "## {$slug}\nInhalt zum Suchen: Karotte Ingwer Püree.";
        $daten = [
            'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => $kategorie, 'content_md' => $inhalt,
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
        ];
        if ($art !== null) {
            $daten['art'] = $art;
        }

        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId($daten);
    };

    $this->mkKanon = function (int $docId, string $scopeKey): void {
        DB::table('foodalchemist_knowledge_canon')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null,
            'scope' => 'prompt_key', 'scope_key' => $scopeKey, 'role' => 'root', 'ord' => 10,
            'knowledge_document_id' => $docId, 'mode' => 'pflicht', 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('haelt ein ablauf-Dossier aus dem Kanon-Block heraus', function () {
    ($this->mkKanon)(($this->mkDoc)('handwerk', Wissensart::FACHWISSEN), 'test.key');
    ($this->mkKanon)(($this->mkDoc)('agenten-anleitung', Wissensart::ABLAUF), 'test.key');

    $docs = app(KnowledgeCanonService::class)->documentsFor('prompt_key', 'test.key', $this->rootTeam);

    expect($docs->pluck('slug')->all())->toBe(['handwerk']);
});

it('meldet das ablauf-Dossier im Kanon als Befund, statt es still zu schlucken', function () {
    // Genau die Lehre aus dieser Spec: was der Prompt-Bau weglaesst, muss irgendwo AUFTAUCHEN.
    // Sonst wundert sich der Kurator, warum seine Kanon-Zeile nichts tut.
    config(['foodalchemist.prompts' => ['test.key' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkKanon)(($this->mkDoc)('agenten-anleitung', Wissensart::ABLAUF), 'test.key');

    $p = app(WissensProfilService::class)->profil('test.key', $this->rootTeam);
    $befund = collect($p['befunde'])->firstWhere('code', 'art_nie_im_prompt');

    expect($befund)->not->toBeNull()
        ->and($befund['schwere'])->toBe('blockiert')
        ->and($befund['text'])->toContain('ablauf.GET')
        ->and($p['zustand'])->toBe('fehlerhaft');
});

it('zieht ein ablauf-Dossier nicht per Discovery in den Prompt', function () {
    ($this->mkDoc)('technik-handwerk', Wissensart::FACHWISSEN, 'kueche');
    ($this->mkDoc)('agenten-schritte', Wissensart::ABLAUF, 'kueche');
    DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
        ['feature' => 'test.discovery', 'category' => 'kueche'],
        ['mode' => 'discovery', 'max_docs' => 5, 'max_chars_per_doc' => 4000, 'created_at' => now(), 'updated_at' => now()],
    );

    $wissen = app(KnowledgeContextService::class)
        ->contextFor($this->rootTeam, 'test.discovery', 'Karotte Ingwer Püree');

    expect($wissen['files_used'])->not->toContain('agenten-schritte');
});

it('laesst knowledge.SEARCH ein ablauf-Dossier weiter FINDEN', function () {
    // Der Filter sitzt bewusst nicht in `nurSichtbar()`: Agenten holen sich ihre Anleitung
    // ueber Suche und `ablauf.GET`. Waere sie unauffindbar, haette H1 den Agenten-Weg gekappt.
    ($this->mkDoc)('agenten-schritte-karotte', Wissensart::ABLAUF, 'workflow');

    $treffer = app(KnowledgeContextService::class)
        ->searchDocuments($this->rootTeam, 'Karotte Ingwer Püree', null, 20);

    expect(collect($treffer)->pluck('slug'))->toContain('agenten-schritte-karotte');
});

it('weist eine erfundene Wissensart ab', function () {
    // Die Art ist eine Code-Konstante, kein pflegbares Vokabular — der Prompt-Bau entscheidet
    // anhand dieser Werte. Ein frei erfundener Wert waere still wirkungslos.
    expect(fn () => app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Test', 'category' => 'cross_cutting', 'art' => 'quatsch', 'content_md' => 'x',
    ]))->toThrow(RuntimeException::class, 'Unbekannte Wissensart');
});

it('MCP: knowledge.POST nimmt art an und gibt sie zurueck', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $res = app(ToolRegistry::class)->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'Ablauf-Dossier', 'category' => 'workflow', 'art' => Wissensart::ABLAUF,
        'content_md' => 'Schritt 1 — Rahmen laden.',
    ], new ToolContext($user, $this->rootTeam));

    expect($res->success)->toBeTrue((string) ($res->error ?? ''))
        ->and($res->data['document']['art'])->toBe(Wissensart::ABLAUF);
});

it('erlaubt art=null, damit der noch nicht eingeordnete Bestand weiter geliefert wird', function () {
    // Nullable und ohne Backfill: der Korpus wird gerade neu aufgebaut. Wuerde `null`
    // ausgeschlossen, fiele der gesamte Bestand aus jedem Prompt.
    ($this->mkKanon)(($this->mkDoc)('alt-ohne-art', null), 'test.key');

    $docs = app(KnowledgeCanonService::class)->documentsFor('prompt_key', 'test.key', $this->rootTeam);

    expect($docs->pluck('slug')->all())->toBe(['alt-ohne-art'])
        ->and(Wissensart::darfInPrompt(null))->toBeTrue();
});

it('gibt die Art in der Inventar-Sicht zurueck', function () {
    // Beim Korpus-Umbau ist „welche Dossiers sind noch nicht eingeordnet" genau die Frage,
    // die man an LIST stellt. Ohne das Feld dort waere die Einordnung nicht nachhaltbar —
    // und ich hatte in PR #51 faelschlich behauptet, LIST liefere sie bereits.
    ($this->mkDoc)('mit-art', Wissensart::DATENWERK, 'cross_cutting');
    ($this->mkDoc)('ohne-art', null, 'cross_cutting');

    $liste = app(KnowledgeContextService::class)
        ->listDocuments($this->rootTeam, 'cross_cutting', 0, 50, false, false);

    $nach = collect($liste['documents'])->keyBy('slug');

    expect($nach['mit-art']['art'])->toBe(Wissensart::DATENWERK)
        ->and($nach['ohne-art']['art'])->toBeNull();
});
