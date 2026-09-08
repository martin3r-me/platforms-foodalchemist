<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · A4 — die Diagnose-Auskunft darf nicht im Kreis verweisen.
 *
 * Bis jetzt antwortete `regelwerk.GET` bei einem Prompt-Key ohne Kanon: „Der Generator lädt
 * dann das per Routing gebundene Regelwerk-Dossier; über ablauf.GET siehst du, welches das
 * ist." — `ablauf.GET` liest aber DENSELBEN Kanon und antwortet genauso leer. Der Agent lief
 * im Kreis und hielt „schau woanders" für „es gibt etwas".
 *
 * Drei Zustände, drei Antworten: `routing` (mit der Auswahl-Mechanik), `bindung` (Alt-Struktur,
 * mit dem Hinweis auf die Präfix-Streuung) und `ungesteuert` — das ist ein Befund, kein
 * Normalzustand.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a = []) => $this->registry->get($n)->execute($a, $this->kontext);

    $this->mkDoc = function (string $slug, string $kategorie = 'regelwerk'): int {
        $inhalt = "## Inhalt {$slug}\nRegeltext.";

        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug,
            'title' => $slug, 'category' => $kategorie, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    // updateOrInsert, nicht insert: die Migrationen seeden Routing-Zeilen mit, und
    // (feature, category) ist unique — ein blindes insert stirbt am Bestand.
    $this->mkRouting = function (string $feature, string $mode, ?int $maxDocs = null): void {
        DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
            ['feature' => $feature, 'category' => 'regelwerk'],
            ['mode' => $mode, 'max_docs' => $maxDocs, 'updated_at' => now(), 'created_at' => now()],
        );
    };
});

it('sagt ungesteuert, wenn diesen Prompt-Key kein Regelwerk erreicht', function () {
    // `recipe.geschmack` hat live weder Kanon noch Routing noch (nach F1) Bindung.
    $res = ($this->run)('foodalchemist.regelwerk.GET', ['prompt_key' => 'recipe.geschmack']);

    expect($res->success)->toBeTrue((string) ($res->error ?? ''))
        ->and($res->data['quelle'])->toBe('ungesteuert')
        ->and($res->data['dokumente'])->toBe([])
        ->and($res->data['hinweis'])->toContain('Befund')
        // Kein Verweis mehr auf ein Tool, das dieselbe leere Quelle liest.
        ->and($res->data['hinweis'])->not->toContain('ablauf.GET');
});

it('nennt bei always-Routing die ->first()-Mechanik, statt sie zu verschweigen', function () {
    // Der Frisch-DB-Zustand: `regelwerk:always 1×7000`. `regelwerkBlock()` holt per `->first()`
    // GENAU EIN Dossier — bei vielen §-Splits ist das keine Auswahl, sondern Zufall. Wer das
    // nicht sagt, lässt den Agenten glauben, er habe das passende Regelwerk.
    ($this->mkDoc)('regelwerk-basisrezepte-1-naming');
    ($this->mkRouting)('recipe.ueberarbeiten', 'always', 1);

    $res = ($this->run)('foodalchemist.regelwerk.GET', ['prompt_key' => 'recipe.ueberarbeiten']);

    expect($res->data['quelle'])->toBe('routing')
        ->and($res->data['routing']['mode'])->toBe('always')
        ->and($res->data['hinweis'])->toContain('->first()')
        ->and($res->data['hinweis'])->toContain('Zufall');
});

it('macht den Alt-Schluessel sichtbar: recipe.generator routet unter ai_generate_recipe', function () {
    // Befund B5 als Vertrag auf der Auskunfts-Seite: fragt jemand `regelwerk.GET` zum
    // Generator, muss die Antwort den Schlüssel nennen, unter dem das Routing wirklich läuft.
    ($this->mkRouting)('ai_generate_recipe', 'discovery', 3);

    $res = ($this->run)('foodalchemist.regelwerk.GET', ['prompt_key' => 'recipe.generator']);

    expect($res->data['quelle'])->toBe('routing')
        ->and($res->data['routing']['feature'])->toBe('ai_generate_recipe')
        ->and($res->data['routing']['alt_schluessel'])->toBeTrue();
});

it('nennt einen Key mit NUR einer Alt-Bindung ungesteuert — und sagt, warum sie nichts tut', function () {
    // ★ Umkehrung mit Spec 52 · F2. Vorher lautete die Antwort `quelle: bindung` mit dem
    // Hinweis, dass eine Bindung auf `recipe` dasselbe Dossier an ALLE 23 recipe.*-Prompts
    // hängt. Das war richtig — und ist es nicht mehr.
    //
    // Die Antwort muss jetzt zweierlei leisten: ehrlich `ungesteuert` sagen UND erklären,
    // warum der Wissens-Browser trotzdem eine Verdrahtung anzeigt. Ohne den zweiten Teil
    // sieht die Auskunft für den Kurator aus wie ein Widerspruch.
    $docId = ($this->mkDoc)('produktion-arbeitszeit');
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'knowledge_document_id' => $docId, 'binding_type' => 'layer', 'target_key' => 'recipe',
        'mode' => 'always', 'weight' => 0, 'active' => 1, 'source' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $res = ($this->run)('foodalchemist.regelwerk.GET', ['prompt_key' => 'recipe.geschmack']);

    expect($res->data['quelle'])->toBe('ungesteuert')
        ->and($res->data['dokumente'])->toBe([])
        ->and($res->data['alt_bindungen'])->toBe(['produktion-arbeitszeit'])
        ->and($res->data['hinweis'])->toContain('wirken seit Spec 52 aber NICHT mehr')
        ->and($res->data['hinweis'])->toContain('knowledge_canon.PUT');
});

it('zaehlt eine Bindung auf ein inaktives Dossier nicht als Auskunft', function () {
    $docId = ($this->mkDoc)('totes-regelwerk');
    DB::table('foodalchemist_knowledge_documents')->where('id', $docId)->update(['active' => 0]);
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'knowledge_document_id' => $docId, 'binding_type' => 'layer', 'target_key' => 'recipe.geschmack',
        'mode' => 'always', 'weight' => 0, 'active' => 1, 'source' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $res = ($this->run)('foodalchemist.regelwerk.GET', ['prompt_key' => 'recipe.geschmack']);

    expect($res->data['quelle'])->toBe('ungesteuert');
});

/**
 * Spec 52/A3 — `knowledge_bindings.GET`. Die Alt-Struktur war schreibbar (BIND/UNBIND), aber
 * nicht lesbar; deshalb blieb „Bindung auf inaktives Dossier" still. Drei Felder entscheiden
 * über die Wirkung und müssen deshalb in der Antwort stehen: `doc_active`, `ziel_art` und
 * `stumm_wegen_kanon`.
 */
function bindeDoc(int $docId, string $targetKey): void
{
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'knowledge_document_id' => $docId, 'binding_type' => 'layer', 'target_key' => $targetKey,
        'mode' => 'always', 'weight' => 0, 'active' => 1, 'source' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('nennt bei einem Bereichs-Ziel, auf wie viele Prompt-Keys es wirkt', function () {
    // Gemessen hängen `geschmacksbalance` und `workflow.rezept_anlegen_mcp` so an je 23 Keys.
    // Ohne diese Zahl sieht eine Bereichs-Bindung wie gezielte Kuration aus.
    bindeDoc(($this->mkDoc)('geschmacksbalance', 'cross_cutting'), 'recipe');

    $res = ($this->run)('foodalchemist.knowledge_bindings.GET', ['target_key' => 'recipe']);

    expect($res->success)->toBeTrue((string) ($res->error ?? ''))
        ->and($res->data['bindungen'][0]['ziel_art'])->toBe('bereich')
        ->and($res->data['bindungen'][0]['wirkt_auf_prompt_keys'])->toBeGreaterThan(5);
});

it('markiert eine Bindung auf ein inaktives Dossier als wirkungslos und sagt es im Hinweis', function () {
    $docId = ($this->mkDoc)('totes-dossier', 'cross_cutting');
    DB::table('foodalchemist_knowledge_documents')->where('id', $docId)->update(['active' => 0]);
    bindeDoc($docId, 'recipe.geschmack');

    $res = ($this->run)('foodalchemist.knowledge_bindings.GET', ['slug' => 'totes-dossier']);

    expect($res->data['bindungen'][0]['doc_active'])->toBeFalse()
        ->and($res->data['bindungen'][0]['wirkungslos'])->toBeTrue()
        ->and($res->data['auf_inaktive_dossiers'])->toBe(1)
        ->and($res->data['hinweis'])->toContain('INAKTIVE');
});

it('markiert eine Bindung als stumm, wenn der Prompt-Key einen Kanon hat', function () {
    // AiGatewayService:178 — hat der Prompt-Key Kanon-Zeilen, werden Bindungen fuer ihn gar
    // nicht erst abgefragt. Eine Bindung auf `recipe.generator` bewirkt also nichts.
    $kanonDoc = ($this->mkDoc)('kanon-regel');
    DB::table('foodalchemist_knowledge_canon')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'role' => 'root', 'ord' => 10,
        'knowledge_document_id' => $kanonDoc, 'mode' => 'pflicht', 'active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    bindeDoc(($this->mkDoc)('gebundenes-dossier', 'cross_cutting'), 'recipe.generator');

    $res = ($this->run)('foodalchemist.knowledge_bindings.GET', ['slug' => 'gebundenes-dossier']);

    expect($res->data['bindungen'][0]['stumm_wegen_kanon'])->toBe(['recipe.generator'])
        ->and($res->data['bindungen'][0]['wirkungslos'])->toBeTrue();
});

it('filtert auf nur_wirkungslos, damit der Aufraeum-Bestand direkt lesbar ist', function () {
    bindeDoc(($this->mkDoc)('lebendes-dossier', 'cross_cutting'), 'recipe.geschmack');
    $tot = ($this->mkDoc)('anderes-totes', 'cross_cutting');
    DB::table('foodalchemist_knowledge_documents')->where('id', $tot)->update(['active' => 0]);
    bindeDoc($tot, 'recipe.sensorik');

    $alle = ($this->run)('foodalchemist.knowledge_bindings.GET', []);
    $nur = ($this->run)('foodalchemist.knowledge_bindings.GET', ['nur_wirkungslos' => true]);

    expect($alle->data['total'])->toBe(2)
        ->and($nur->data['total'])->toBe(1)
        ->and($nur->data['bindungen'][0]['slug'])->toBe('anderes-totes');
});
