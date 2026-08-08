<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP knowledge.SET_ACTIVE: Docs aktiv/inaktiv schalten (Kuration). Team-scoped,
 * Vault-Docs erlaubt (kein Content-Lock, da active reine Kuration ist), idempotent,
 * NOT_FOUND, Master-/Global-Guard.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('registriert knowledge.SET_ACTIVE', function () {
    expect($this->registry->get('foodalchemist.knowledge.SET_ACTIVE'))->not->toBeNull();
});

it('aktiviert und deaktiviert ein eigenes Doc (idempotent)', function () {
    $post = $this->registry->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'Mein Entwurf', 'category' => 'cross_cutting', 'content_md' => '# X',
    ], $this->kontext);
    $slug = $post->data['document']['slug'];

    $an = $this->registry->get('foodalchemist.knowledge.SET_ACTIVE')->execute(['slug' => $slug, 'active' => true], $this->kontext);
    expect($an->success)->toBeTrue()
        ->and($an->data['document']['active'])->toBeTrue()
        ->and($an->data['document']['changed'])->toBeTrue();
    expect((bool) DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('active'))->toBeTrue();

    $wieder = $this->registry->get('foodalchemist.knowledge.SET_ACTIVE')->execute(['slug' => $slug, 'active' => true], $this->kontext);
    expect($wieder->data['document']['changed'])->toBeFalse();

    $aus = $this->registry->get('foodalchemist.knowledge.SET_ACTIVE')->execute(['slug' => $slug, 'active' => false], $this->kontext);
    expect($aus->data['document']['active'])->toBeFalse()->and($aus->data['document']['changed'])->toBeTrue();
});

it('erlaubt (De)Aktivieren auch bei Vault-verwalteten Docs des eigenen Teams', function () {
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(),
        'team_id' => $this->rootTeam->id,
        'slug' => 'trend.alt-2024',
        'title' => 'Alter Trend',
        'category' => 'trend',
        'content_md' => '# alt',
        'version' => 1,
        'content_hash' => hash('sha256', 'alt'),
        'imported_hash' => hash('sha256', 'alt'),
        'char_count' => 5,
        'active' => true,
        'source_path' => '07_WISSEN/trends/alt-2024.md',
        'created_via' => 'import',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $res = $this->registry->get('foodalchemist.knowledge.SET_ACTIVE')->execute(['slug' => 'trend.alt-2024', 'active' => false], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['document']['active'])->toBeFalse()
        ->and($res->data['document']['vault_managed'])->toBeTrue();
    expect((bool) DB::table('foodalchemist_knowledge_documents')->where('slug', 'trend.alt-2024')->value('active'))->toBeFalse();
});

it('liefert NOT_FOUND für einen unbekannten Slug', function () {
    $res = $this->registry->get('foodalchemist.knowledge.SET_ACTIVE')->execute(['slug' => 'gibt-es-nicht', 'active' => false], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('sperrt geerbtes/globales Master-Wissen (team_id NULL)', function () {
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(),
        'team_id' => null,
        'slug' => 'domain.global-seed',
        'title' => 'Global',
        'category' => 'trend',
        'content_md' => '# g',
        'version' => 1,
        'content_hash' => hash('sha256', 'g'),
        'imported_hash' => null,
        'char_count' => 3,
        'active' => true,
        'source_path' => null,
        'created_via' => 'import',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $res = $this->registry->get('foodalchemist.knowledge.SET_ACTIVE')->execute(['slug' => 'domain.global-seed', 'active' => false], $this->kontext);
    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('Master-Wissen');
    expect((bool) DB::table('foodalchemist_knowledge_documents')->where('slug', 'domain.global-seed')->value('active'))->toBeTrue();
});
