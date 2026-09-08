<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · F3 — die Bindungs-Schreibpfade sind zu, der Rückweg ist offen.
 *
 * Diese Datei hiess wörtlich `KnowledgeBindToolTest` und pinnte die Alt-Struktur: dass
 * `knowledge.BIND` ein Vault-Dossier verdrahtet, das `knowledge.PUT` mit LOCKED abweist,
 * dass es idempotent ist, dass ein unbekannter Einsatzort abgewiesen wird. Alles korrekt —
 * für einen Mechanismus, der seit F2 nichts mehr bewirkt.
 *
 * Sie pinnt jetzt die Gegenrichtung, und zwar in der Reihenfolge ihrer Wichtigkeit:
 *   1. es gibt KEINEN Weg mehr, eine Bindung anzulegen — auch nicht über POST/PUT,
 *   2. ein Versuch WIRFT (statt still nichts zu tun) und nennt den richtigen Weg,
 *   3. UNBIND funktioniert weiter, denn die Alt-Zeilen müssen wegkönnen.
 *
 * Punkt 2 ist der eigentliche Grund für diese Datei: „ignoriert" wäre exakt die
 * Fehlerklasse, gegen die Spec 52 antritt — der Aufrufer bekäme Erfolg und hätte nichts
 * erreicht (Befund `J`: die Oberfläche wies den Kurator aktiv in die tote Struktur).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

/**
 * Legt ein Vault/Seed-Doc an (source_path gesetzt, team_id NULL = globaler Seed) und
 * gibt {id, slug} zurück. Der Slug ist pro Aufruf EINDEUTIG (UUID-Suffix), ebenso der
 * content_hash — so bleiben die Assertions pollution-fest, obwohl die Modul-Suite ohne
 * DB-Isolation läuft (kein RefreshDatabase/DatabaseTransactions, Konvention). Vorher
 * teilten alle Docs Slug UND content_hash → ein zweiter Insert in nicht-frischer DB und
 * ein per Slug (statt Doc-ID) gegriffenes Doc kippten den Test reihenfolgen-abhängig
 * (Flakiness-Analyse 2026-07-24). Konsument filtert IMMER über die zurückgegebene id.
 *
 * @return array{id:int, slug:string}
 */
function seedVaultDoc(?string $slug = null): array
{
    $slug ??= 'regelwerk_grundprodukte_' . substr(str_replace('-', '', (string) UuidV7::generate()), 0, 12);
    $id = DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'slug' => $slug,
        'title' => 'Regelwerk Grundprodukte', 'category' => 'regelwerk',
        'content_md' => '# Regelwerk', 'version' => 3, 'content_hash' => hash('sha256', $slug),
        'char_count' => 10, 'active' => 1, 'source_path' => '07_WISSEN/…/Regelwerk_Grundprodukte.md',
        'created_via' => 'import', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['id' => (int) $id, 'slug' => $slug];
}

it('das Tool knowledge.BIND existiert nicht mehr', function () {
    // Kein „verweigerndes" Tool: ein Werkzeug, das nur scheitern kann, ist Rauschen im
    // Katalog, den die Agenten durchsuchen. Der richtige Weg steht in der Beschreibung von
    // knowledge_canon.PUT und in der von UNBIND.
    expect($this->registry->get('foodalchemist.knowledge.BIND'))->toBeNull();

    $namen = collect($this->registry->all())->map(fn ($t) => $t->getName())->all();
    expect($namen)->not->toContain('foodalchemist.knowledge.BIND')
        ->and($namen)->toContain('foodalchemist.knowledge.UNBIND')
        ->and($namen)->toContain('foodalchemist.knowledge_canon.PUT')
        ->and($namen)->toContain('foodalchemist.knowledge_bindings.GET');
});

it('knowledge.POST und knowledge.PUT nehmen kein bind_layers mehr an — und schlucken es nicht still', function () {
    // ★ Das ist der Kern: still ignorieren hätte `success` geliefert und nichts getan.
    $post = $this->registry->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'Test-Dossier F3', 'category' => 'regelwerk', 'content_md' => '# X',
        'bind_layers' => [['target_key' => 'recipe', 'mode' => 'always']],
    ], $this->kontext);

    expect($post->success)->toBeFalse()
        ->and($post->error)->toContain('abgeschafft')
        ->and($post->error)->toContain('knowledge_canon.PUT');

    ['slug' => $slug] = seedVaultDoc();
    $put = $this->registry->get('foodalchemist.knowledge.PUT')->execute([
        'slug' => $slug, 'bind_layers' => [['target_key' => 'recipe']],
    ], $this->kontext);
    // Vault-Doc: LOCKED greift zuerst — die Aussage hier ist nur, dass es NICHT durchgeht.
    expect($put->success)->toBeFalse();
});

it('bindLayer() wirft und nennt den Weg, der heute wirkt', function () {
    ['id' => $docId] = seedVaultDoc();
    $dienst = app(\Platform\FoodAlchemist\Services\KnowledgeService::class);

    expect(fn () => $dienst->bindLayer($this->rootTeam, $docId, 'recipe', 'always'))
        ->toThrow(RuntimeException::class, 'abgeschafft');

    // Und es entsteht KEINE Zeile — der Riegel sitzt vor dem Schreiben, nicht danach.
    expect(DB::table('foodalchemist_knowledge_bindings')->where('knowledge_document_id', $docId)->count())->toBe(0);
});

it('UNBIND löst weiter — der Rückweg für die Alt-Zeilen muss offen bleiben', function () {
    ['id' => $docId, 'slug' => $slug] = seedVaultDoc();

    // Alt-Bindungen gibt es nur noch als Bestand, also per Insert gestellt (so wie sie auf
    // demo liegen: 9 Zeilen, alle wirkungslos).
    foreach ([['team_id' => (int) $this->rootTeam->id], ['team_id' => 999999]] as $z) {
        DB::table('foodalchemist_knowledge_bindings')->insert($z + [
            'uuid' => (string) UuidV7::generate(),
            'knowledge_document_id' => $docId, 'binding_type' => 'layer', 'target_key' => 'recipe',
            'mode' => 'always', 'weight' => 0, 'active' => 1, 'source' => 'ui',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $un1 = $this->registry->get('foodalchemist.knowledge.UNBIND')->execute(
        ['slug' => $slug, 'target_key' => 'recipe'], $this->kontext);
    expect($un1->success)->toBeTrue()->and($un1->data['removed'])->toBeTrue();

    // eigene weg, Fremd-Bindung bleibt (Tenancy-Grenze gilt auch beim Aufräumen)
    expect(DB::table('foodalchemist_knowledge_bindings')->where('knowledge_document_id', $docId)
        ->where('team_id', $this->rootTeam->id)->whereNull('deleted_at')->count())->toBe(0)
        ->and(DB::table('foodalchemist_knowledge_bindings')->where('knowledge_document_id', $docId)
            ->where('team_id', 999999)->whereNull('deleted_at')->count())->toBe(1);

    // zweites UNBIND = no-op
    $un2 = $this->registry->get('foodalchemist.knowledge.UNBIND')->execute(
        ['slug' => $slug, 'target_key' => 'recipe'], $this->kontext);
    expect($un2->success)->toBeTrue()->and($un2->data['removed'])->toBeFalse();
});
