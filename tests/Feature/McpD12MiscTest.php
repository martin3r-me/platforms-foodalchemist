<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\TrendRefreshJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvasEntry;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D12: knowledge.DELETE/ALIAS (3 neue Service-Methoden) + canvas.ENTRY_ADD/REMOVE
 * + sales_facts.MAP + trendradar.IMPORT + presentation_designs.DUPLICATE/GENERATE_CSS.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
});

it('Registry-Smoke: alle 8 D12-Tools registriert mit type=object', function () {
    $namen = [
        'knowledge.DELETE', 'knowledge.ALIAS', 'canvas.ENTRY_ADD', 'canvas.ENTRY_REMOVE',
        'sales_facts.MAP', 'trendradar.IMPORT', 'presentation_designs.DUPLICATE', 'presentation_designs.GENERATE_CSS',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('knowledge.ALIAS add/remove + knowledge.DELETE (confirm)', function () {
    app(KnowledgeService::class)->create($this->rootTeam, [
        'slug' => 'cross_cutting.testdoc', 'title' => 'Testdoc', 'category' => 'cross_cutting', 'content_md' => '# Test',
    ]);

    $add = ($this->run)('foodalchemist.knowledge.ALIAS', ['action' => 'add', 'slug' => 'cross_cutting.testdoc', 'alias' => 'Test Alias']);
    expect($add->success)->toBeTrue('add: ' . ($add->error ?? ''))->and($add->data['alias_slug'])->toBe('test_alias');
    $aliasId = (int) DB::table('foodalchemist_knowledge_aliases')->where('alias_slug', 'test_alias')->value('id');

    $rem = ($this->run)('foodalchemist.knowledge.ALIAS', ['action' => 'remove', 'alias_id' => $aliasId]);
    expect($rem->success)->toBeTrue('rem: ' . ($rem->error ?? ''));
    expect(DB::table('foodalchemist_knowledge_aliases')->where('id', $aliasId)->exists())->toBeFalse();

    expect(($this->run)('foodalchemist.knowledge.DELETE', ['slug' => 'cross_cutting.testdoc'])->errorCode)->toBe('CONFIRM_REQUIRED');
    $del = ($this->run)('foodalchemist.knowledge.DELETE', ['slug' => 'cross_cutting.testdoc', 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'cross_cutting.testdoc')->whereNull('deleted_at')->exists())->toBeFalse();
});

it('canvas.ENTRY_ADD / ENTRY_REMOVE (food_dna)', function () {
    $add = ($this->run)('foodalchemist.canvas.ENTRY_ADD', ['type' => 'food_dna', 'key' => 'aromatik', 'value' => 'mediterran, rauchig']);
    expect($add->success)->toBeTrue('add: ' . ($add->error ?? ''));
    $entryId = $add->data['entry_id'];
    expect(FoodAlchemistCanvasEntry::find($entryId)->value)->toBe('mediterran, rauchig');

    $rem = ($this->run)('foodalchemist.canvas.ENTRY_REMOVE', ['entry_id' => $entryId]);
    expect($rem->success)->toBeTrue('rem: ' . ($rem->error ?? ''));
    expect(FoodAlchemistCanvasEntry::find($entryId))->toBeNull();

    // Unbekannter Key → VALIDATION_ERROR
    expect(($this->run)('foodalchemist.canvas.ENTRY_ADD', ['type' => 'food_dna', 'key' => 'quatsch', 'value' => 'x'])->errorCode)->toBe('VALIDATION_ERROR');
});

it('sales_facts.MAP: unbekannter Fakt → NOT_FOUND', function () {
    expect(($this->run)('foodalchemist.sales_facts.MAP', ['fact_id' => 999999, 'recipe_id' => 1])->errorCode)->toBe('NOT_FOUND');
});

it('trendradar.IMPORT: confirm-gate + dispatch', function () {
    Bus::fake();
    expect(($this->run)('foodalchemist.trendradar.IMPORT', [])->errorCode)->toBe('CONFIRM_REQUIRED');
    $imp = ($this->run)('foodalchemist.trendradar.IMPORT', ['confirm' => true]);
    expect($imp->success)->toBeTrue('imp: ' . ($imp->error ?? ''));
    Bus::assertDispatched(TrendRefreshJob::class);
});

it('presentation_designs.DUPLICATE (Builtin) + GENERATE_CSS-Validierung', function () {
    $dup = ($this->run)('foodalchemist.presentation_designs.DUPLICATE', ['source' => 'editorial', 'name' => 'Sommer-Look']);
    expect($dup->success)->toBeTrue('dup: ' . ($dup->error ?? ''))->and($dup->data['name'])->toBe('Sommer-Look');

    expect(($this->run)('foodalchemist.presentation_designs.GENERATE_CSS', ['brief' => ''])->errorCode)->toBe('VALIDATION_ERROR');
});
