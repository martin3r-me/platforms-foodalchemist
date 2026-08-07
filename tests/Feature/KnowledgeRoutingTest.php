<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\KnowledgeRoutingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * S1b (2026-08-07): Wissens-Routing zur Laufzeit pflegbar — Service + MCP-Tools GET/PUT.
 * Damit eine Kategorie ohne Code-Deploy geroutet/gedeckelt/entfernt werden kann.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('Service: set (upsert auf feature+category) / list / remove + Modus-Validierung', function () {
    $svc = app(KnowledgeRoutingService::class);

    $svc->set('ai_test_feature', 'niveau', 'discovery', 1, 3000);
    $rows = $svc->list('ai_test_feature');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['mode'])->toBe('discovery')
        ->and($rows[0]['max_docs'])->toBe(1)
        ->and($rows[0]['max_chars_per_doc'])->toBe(3000);

    // upsert: gleiche (feature,category) → Modus/Caps ändern, KEINE zweite Zeile
    $svc->set('ai_test_feature', 'niveau', 'always', null, null);
    $rows = $svc->list('ai_test_feature');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['mode'])->toBe('always')
        ->and($rows[0]['max_docs'])->toBeNull();

    expect(fn () => $svc->set('ai_test_feature', 'niveau', 'quatsch'))->toThrow(InvalidArgumentException::class);

    expect($svc->remove('ai_test_feature', 'niveau'))->toBe(1)
        ->and($svc->list('ai_test_feature'))->toBe([]);
});

it('Tools: knowledge_routings.GET/PUT sind registriert und editieren zur Laufzeit', function () {
    $put = $this->registry->get('foodalchemist.knowledge_routings.PUT');
    $get = $this->registry->get('foodalchemist.knowledge_routings.GET');
    expect($put)->not->toBeNull()
        ->and($get)->not->toBeNull()
        ->and($put->getSchema()['type'])->toBe('object');

    $res = $put->execute(['feature' => 'ai_test_feature', 'category' => 'kueche', 'mode' => 'discovery', 'max_docs' => 3], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['routing']['mode'])->toBe('discovery');

    $liste = $get->execute(['feature' => 'ai_test_feature'], $this->kontext);
    expect($liste->success)->toBeTrue()
        ->and(collect($liste->data['routings'])->pluck('category')->all())->toContain('kueche');

    // ungültiger Modus → sauberer Tool-Fehler, kein Crash
    $bad = $put->execute(['feature' => 'ai_test_feature', 'category' => 'kueche', 'mode' => 'quatsch'], $this->kontext);
    expect($bad->success)->toBeFalse();

    // delete → search-only
    $del = $put->execute(['feature' => 'ai_test_feature', 'category' => 'kueche', 'delete' => true], $this->kontext);
    expect($del->success)->toBeTrue()
        ->and($del->data['deleted'])->toBe(1)
        ->and($get->execute(['feature' => 'ai_test_feature'], $this->kontext)->data['routings'])->toBe([]);
});
