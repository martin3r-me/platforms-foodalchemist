<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #469 v3: MCP-Schreiben ins Wissens-Modul (knowledge.POST/PUT). Quarantäne
 * (inaktiv + created_via=mcp), Kategorie-Vokabular-Validierung, Bindungen mit
 * source=mcp, Vault-Guard (source_path gesetzt ⇒ gesperrt).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('registriert knowledge.POST und knowledge.PUT', function () {
    expect($this->registry->get('foodalchemist.knowledge.POST'))->not->toBeNull()
        ->and($this->registry->get('foodalchemist.knowledge.PUT'))->not->toBeNull();
});

it('legt ein Doc inaktiv + created_via=mcp an und hält es aus der SEARCH-Sicht raus', function () {
    $res = $this->registry->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'Trend: Fermentierte Chili-Pasten',
        'category' => 'trend',
        'content_md' => "# Fermentierte Chili-Pasten\nGochujang-Adjazenz, Umami-Boost.",
    ], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['document']['active'])->toBeFalse()
        ->and($res->data['document']['created_via'])->toBe('mcp');

    $slug = $res->data['document']['slug'];
    $doc = DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->first();
    expect($doc->active)->toBeFalsy()->and((int) $doc->version)->toBe(1)->and($doc->source_path)->toBeNull();

    // inaktiv ⇒ nicht in der (aktiv-gefilterten) knowledge.SEARCH
    $such = $this->registry->get('foodalchemist.knowledge.SEARCH')->execute(['q' => 'Chili Pasten'], $this->kontext);
    expect(collect($such->data['documents'])->pluck('slug'))->not->toContain($slug);
});

it('weist eine unbekannte Kategorie mit Vokabular-Liste ab', function () {
    $res = $this->registry->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'Irgendwas', 'category' => 'quatsch-kategorie',
    ], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('Verfügbar:');
});

it('setzt Aliase und eine Einsatzort-Bindung mit source=mcp', function () {
    $res = $this->registry->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'Know-how: Sous-vide Zeiten',
        'category' => 'cross_cutting',
        'content_md' => '# Sous-vide',
        'aliases' => ['sousvide', 'niedertemperatur'],
        'bind_layers' => [['target_key' => 'recipe', 'mode' => 'grounding']],
    ], $this->kontext);
    expect($res->success)->toBeTrue();
    $docId = DB::table('foodalchemist_knowledge_documents')->where('slug', $res->data['document']['slug'])->value('id');

    expect(DB::table('foodalchemist_knowledge_aliases')->where('knowledge_document_id', $docId)->count())->toBe(2);
    $bind = DB::table('foodalchemist_knowledge_bindings')->where('knowledge_document_id', $docId)->first();
    expect($bind)->not->toBeNull()
        ->and($bind->target_key)->toBe('recipe')
        ->and($bind->mode)->toBe('grounding')
        ->and($bind->source)->toBe('mcp');
});

it('lehnt eine unbekannte Einsatzort-Bindung ab', function () {
    $res = $this->registry->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'X', 'category' => 'trend',
        'bind_layers' => [['target_key' => 'gibtsnicht']],
    ], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->error)->toContain('Einsatzort');
});

it('aktualisiert ein MCP-Doc: content ⇒ version+1', function () {
    $post = $this->registry->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'Trend: Zero-Waste-Sirup', 'category' => 'trend', 'content_md' => '# v1',
    ], $this->kontext);
    $slug = $post->data['document']['slug'];

    $put = $this->registry->get('foodalchemist.knowledge.PUT')->execute([
        'slug' => $slug, 'content_md' => "# v2\nMehr Inhalt.",
    ], $this->kontext);

    expect($put->success)->toBeTrue()->and($put->data['document']['version'])->toBe(2);
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('content_md'))->toContain('v2');
});

it('sperrt ein Vault-verwaltetes Doc für den MCP-Pfad (LOCKED)', function () {
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'regelwerk.grundprodukte',
        'title' => 'Regelwerk Grundprodukte', 'category' => 'regelwerk',
        'content_md' => '# Regelwerk', 'version' => 3, 'content_hash' => hash('sha256', 'x'),
        'char_count' => 10, 'active' => 1, 'source_path' => '07_WISSEN/…/Regelwerk_Grundprodukte.md',
        'created_via' => 'import', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $put = $this->registry->get('foodalchemist.knowledge.PUT')->execute([
        'slug' => 'regelwerk.grundprodukte', 'content_md' => 'HACK',
    ], $this->kontext);

    expect($put->success)->toBeFalse()
        ->and($put->errorCode)->toBe('LOCKED')
        ->and(DB::table('foodalchemist_knowledge_documents')->where('slug', 'regelwerk.grundprodukte')->value('content_md'))->toBe('# Regelwerk');
});
