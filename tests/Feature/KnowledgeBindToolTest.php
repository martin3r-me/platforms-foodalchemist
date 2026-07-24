<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #469: knowledge.BIND/UNBIND — bindet BESTEHENDE Docs (auch globalen Seed /
 * Vault-Kanon) an Einsatzorte, ohne den Inhalt anzufassen. Kern-Beweis: was
 * knowledge.PUT mit LOCKED abweist, lässt sich hier binden; die Bindung ist
 * tenancy-scoped (team_id des Callers), UNBIND löst nur team-eigene Bindungen.
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

it('registriert knowledge.BIND und knowledge.UNBIND', function () {
    expect($this->registry->get('foodalchemist.knowledge.BIND'))->not->toBeNull()
        ->and($this->registry->get('foodalchemist.knowledge.UNBIND'))->not->toBeNull();
});

it('bindet ein Vault/Seed-Doc, das knowledge.PUT mit LOCKED abweist', function () {
    ['id' => $docId, 'slug' => $slug] = seedVaultDoc();

    // Gegenprobe: PUT bleibt gesperrt …
    $put = $this->registry->get('foodalchemist.knowledge.PUT')->execute([
        'slug' => $slug, 'content_md' => 'HACK',
    ], $this->kontext);
    expect($put->success)->toBeFalse()->and($put->errorCode)->toBe('LOCKED');

    // … BIND aber funktioniert (Binden ≠ Inhalt editieren).
    $res = $this->registry->get('foodalchemist.knowledge.BIND')->execute([
        'slug' => $slug, 'target_key' => 'recipe', 'mode' => 'always',
    ], $this->kontext);

    expect($res->success)->toBeTrue()->and($res->data['bound_to'])->toBe('recipe');

    $bind = DB::table('foodalchemist_knowledge_bindings')->where('knowledge_document_id', $docId)->whereNull('deleted_at')->first();
    expect($bind)->not->toBeNull()
        ->and($bind->target_key)->toBe('recipe')
        ->and($bind->mode)->toBe('always')
        ->and($bind->source)->toBe('mcp')
        ->and((int) $bind->team_id)->toBe((int) $this->rootTeam->id)   // tenancy-scoped auf den Caller
        ->and((string) $bind->binding_type)->toBe('layer');
});

it('ist idempotent — zweimal binden erzeugt nur eine aktive Bindung', function () {
    ['id' => $docId, 'slug' => $slug] = seedVaultDoc();
    $args = ['slug' => $slug, 'target_key' => 'recipe'];
    $this->registry->get('foodalchemist.knowledge.BIND')->execute($args, $this->kontext);
    $this->registry->get('foodalchemist.knowledge.BIND')->execute($args, $this->kontext);

    expect(DB::table('foodalchemist_knowledge_bindings')->where('knowledge_document_id', $docId)
        ->where('target_key', 'recipe')->whereNull('deleted_at')->count())->toBe(1);
});

it('weist einen unbekannten Einsatzort ab', function () {
    ['slug' => $slug] = seedVaultDoc();
    $res = $this->registry->get('foodalchemist.knowledge.BIND')->execute([
        'slug' => $slug, 'target_key' => 'gibtsnicht',
    ], $this->kontext);
    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('Einsatzort');
});

it('liefert NOT_FOUND für ein unsichtbares/unbekanntes Doc', function () {
    $res = $this->registry->get('foodalchemist.knowledge.BIND')->execute([
        'slug' => 'gibt-es-nicht', 'target_key' => 'recipe',
    ], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('UNBIND löst nur team-eigene Bindungen und ist idempotent', function () {
    ['id' => $docId, 'slug' => $slug] = seedVaultDoc();

    // Fremd-Bindung (anderes Team) am selben Doc/Ziel — darf NICHT gelöst werden.
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => 999999,
        'knowledge_document_id' => $docId, 'binding_type' => 'layer', 'target_key' => 'recipe',
        'mode' => 'discovery', 'weight' => 0, 'active' => 1, 'source' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // eigene Bindung setzen + lösen
    $this->registry->get('foodalchemist.knowledge.BIND')->execute(
        ['slug' => $slug, 'target_key' => 'recipe'], $this->kontext);

    $un1 = $this->registry->get('foodalchemist.knowledge.UNBIND')->execute(
        ['slug' => $slug, 'target_key' => 'recipe'], $this->kontext);
    expect($un1->success)->toBeTrue()->and($un1->data['removed'])->toBeTrue();

    // eigene weg, Fremd-Bindung bleibt
    expect(DB::table('foodalchemist_knowledge_bindings')->where('knowledge_document_id', $docId)
        ->where('team_id', $this->rootTeam->id)->whereNull('deleted_at')->count())->toBe(0);
    expect(DB::table('foodalchemist_knowledge_bindings')->where('knowledge_document_id', $docId)
        ->where('team_id', 999999)->whereNull('deleted_at')->count())->toBe(1);

    // zweites UNBIND = no-op
    $un2 = $this->registry->get('foodalchemist.knowledge.UNBIND')->execute(
        ['slug' => $slug, 'target_key' => 'recipe'], $this->kontext);
    expect($un2->success)->toBeTrue()->and($un2->data['removed'])->toBeFalse();
});
