<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Kategorie-Verwaltung (knowledge_categories.GET/POST): team-scoped Anlage,
 * Slug-aus-Label + expliziter Override, Dedup, Sofort-Nutzbarkeit in knowledge.POST,
 * include_inactive. Schließt die Lücke, dass das Kategorie-Vokabular sonst nur unter
 * Einstellungen pflegbar war.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('registriert knowledge_categories.GET und .POST', function () {
    expect($this->registry->get('foodalchemist.knowledge_categories.GET'))->not->toBeNull()
        ->and($this->registry->get('foodalchemist.knowledge_categories.POST'))->not->toBeNull();
});

it('legt eine neue Kategorie aktiv + team-scoped an (Slug aus Label)', function () {
    $res = $this->registry->get('foodalchemist.knowledge_categories.POST')->execute([
        'label' => 'Format', 'description' => 'Darreichungs-/Konzeptformate',
    ], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['category']['slug'])->toBe('format')
        ->and($res->data['category']['active'])->toBeTrue()
        ->and($res->data['category']['scope'])->toBe('team');

    $row = DB::table('foodalchemist_knowledge_categories')->where('slug', 'format')
        ->where('team_id', $this->rootTeam->id)->whereNull('deleted_at')->first();
    expect($row)->not->toBeNull()->and((bool) $row->active)->toBeTrue();
});

it('respektiert einen expliziten deutschen Slug-Override', function () {
    $res = $this->registry->get('foodalchemist.knowledge_categories.POST')->execute([
        'label' => 'Geschäftsmodell', 'slug' => 'geschaeftsmodell',
    ], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['category']['slug'])->toBe('geschaeftsmodell');
});

it('macht die neue Kategorie sofort in knowledge.POST nutzbar', function () {
    $this->registry->get('foodalchemist.knowledge_categories.POST')->execute([
        'label' => 'Ernaehrung',
    ], $this->kontext);

    // assertKategorie muss die frische Kategorie akzeptieren → kein VALIDATION_ERROR
    $doc = $this->registry->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'Low-Carb Grundlagen',
        'category' => 'ernaehrung',
        'content_md' => '# Low-Carb',
    ], $this->kontext);

    expect($doc->success)->toBeTrue()
        ->and($doc->data['document']['category'] ?? 'ernaehrung')->toBe('ernaehrung');
});

it('weist Dubletten und leeres Label ab', function () {
    $this->registry->get('foodalchemist.knowledge_categories.POST')->execute(['label' => 'Format'], $this->kontext);

    $dup = $this->registry->get('foodalchemist.knowledge_categories.POST')->execute(['label' => 'Format'], $this->kontext);
    expect($dup->success)->toBeFalse()->and($dup->errorCode)->toBe('VALIDATION_ERROR');

    $leer = $this->registry->get('foodalchemist.knowledge_categories.POST')->execute(['label' => '  '], $this->kontext);
    expect($leer->success)->toBeFalse()->and($leer->errorCode)->toBe('VALIDATION_ERROR');
});

it('weist einen zu langen Slug ab (passt sonst nicht in documents.category)', function () {
    $res = $this->registry->get('foodalchemist.knowledge_categories.POST')->execute([
        'label' => 'X', 'slug' => 'viel_zu_langer_kategorie_slug_ueber_vierundzwanzig_zeichen',
    ], $this->kontext);
    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('zu lang');
});

it('listet Kategorien und blendet inaktive per Default aus', function () {
    $this->registry->get('foodalchemist.knowledge_categories.POST')->execute(['label' => 'Format'], $this->kontext);

    $aktiv = $this->registry->get('foodalchemist.knowledge_categories.GET')->execute([], $this->kontext);
    expect($aktiv->success)->toBeTrue()
        ->and(collect($aktiv->data['categories'])->pluck('slug'))->toContain('format');

    // deaktivieren → verschwindet aus der Default-Liste, taucht mit include_inactive wieder auf
    DB::table('foodalchemist_knowledge_categories')->where('slug', 'format')
        ->where('team_id', $this->rootTeam->id)->update(['active' => false]);

    $default = $this->registry->get('foodalchemist.knowledge_categories.GET')->execute([], $this->kontext);
    expect(collect($default->data['categories'])->pluck('slug'))->not->toContain('format');

    $inaktiv = $this->registry->get('foodalchemist.knowledge_categories.GET')->execute(['include_inactive' => true], $this->kontext);
    expect(collect($inaktiv->data['categories'])->pluck('slug'))->toContain('format');
});
