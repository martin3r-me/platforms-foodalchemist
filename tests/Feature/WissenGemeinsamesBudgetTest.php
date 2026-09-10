<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeBudget;
use Platform\FoodAlchemist\Services\Ai\KnowledgeBudgetExceeded;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgePreviewService;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config()->set('foodalchemist.semantic_search.enabled', false);
    config()->set('foodalchemist.ai.knowledge_budget', ['recipe.description' => 1500]);
    $this->doc = fn ($slug, $body, $category = 'cross_cutting') => app(KnowledgeService::class)->create($this->rootTeam, [
        'slug' => $slug, 'title' => 'Kartoffel '.$slug, 'category' => $category, 'content_md' => $body,
    ]);
    $this->canon = fn ($slug, $mode = 'pflicht', $ord = 10) => app(KnowledgeCanonService::class)->set($this->rootTeam, [
        'scope' => 'prompt_key', 'scope_key' => 'recipe.description', 'slug' => $slug, 'mode' => $mode, 'ord' => $ord,
    ]);
    $this->route = function ($mode = 'discovery') {
        DB::table('foodalchemist_knowledge_routings')->updateOrInsert(['feature' => 'recipe.description', 'category' => 'cross_cutting'], [
            'mode' => $mode, 'max_docs' => 5, 'max_chars_per_doc' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('reserviert den Pflichtkanon und nimmt optionale Tabellen vollständig oder gar nicht auf', function () {
    ($this->doc)('regel', str_repeat('R', 700), 'regelwerk'); ($this->canon)('regel');
    $table = "| Spalte | Wert |\n| --- | --- |\n".str_repeat("| Kartoffel | 123 |\n", 60);
    ($this->doc)('a-kartoffel-tabelle', $table);
    $small = 'Kartoffelwissen vollständig bis ENDE.';
    ($this->doc)('z-kartoffel-klein', $small);
    ($this->route)();
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.description', 'Kartoffel');
    expect($context['files_used'])->toBe(['z-kartoffel-klein@v1'])
        ->and($context['files_dropped'])->toContain('a-kartoffel-tabelle@v1')
        ->and($context['block'])->toContain($small)->not->toContain('gekürzt', '| Spalte |');
    $preview = app(KnowledgePreviewService::class)->preview($this->rootTeam, 'recipe.description', 'Kartoffel');
    expect($preview['total_chars'])->toBeLessThanOrEqual(1500)->and($preview['kanon'])->toBe(['regel@v1']);
});

it('meldet die kombinierte Pflichtmenge auch wenn jeder Kanal für sich ins Budget passt', function () {
    ($this->doc)('regel', str_repeat('R', 700), 'regelwerk'); ($this->canon)('regel');
    // Beide Inhalte einzeln kleiner als 1500, zusammen mit Quellenhüllen zu groß.
    ($this->doc)('pflicht', str_repeat('P', 900), 'produktion_kapazitat');
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'recipe.description', 'category' => 'produktion_kapazitat', 'mode' => 'always',
        'max_docs' => 1, 'max_chars_per_doc' => 20, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $service = app(KnowledgeContextService::class);
    expect(fn () => $service->contextFor($this->rootTeam, 'recipe.description', ''))
        ->toThrow(KnowledgeBudgetExceeded::class);
    $check = $service->pflichtBudgetFuer($this->rootTeam, 'recipe.description');
    expect($check['ok'])->toBeFalse()->and($check['required_chars'])->toBeGreaterThan(1600)->and($check['budget'])->toBe(1500);
});

it('lässt einen übergebenen Rohblock nicht am gemeinsamen Gateway-Budget vorbeigehen', function () {
    ($this->doc)('regel', str_repeat('R', 700), 'regelwerk'); ($this->canon)('regel');
    $before = DB::table('foodalchemist_ai_call_log')->count();
    expect(fn () => app(AiGatewayService::class)->propose('recipe.description', [], ['knowledge' => str_repeat('X', 900)]))
        ->toThrow(KnowledgeBudgetExceeded::class);
    expect(DB::table('foodalchemist_ai_call_log')->count())->toBe($before);
});

it('reserviert spätere Pflichtkanon-Zeilen vor früheren optionalen Zeilen', function () {
    ($this->doc)('optional', str_repeat('O', 800), 'regelwerk'); ($this->canon)('optional', 'wenn_platz', 1);
    ($this->doc)('pflicht', str_repeat('P', 900), 'regelwerk'); ($this->canon)('pflicht', 'pflicht', 20);
    $preview = app(KnowledgePreviewService::class)->preview($this->rootTeam, 'recipe.description', 'Kartoffel');
    expect($preview['kanon'])->toBe(['pflicht@v1'])->and($preview['dropped'])->toContain('optional@v1')
        ->and($preview['total_chars'])->toBeLessThanOrEqual(1500);
});

it('stimmt im gemeinsamen Budget mit der tatsächlichen Gateway-Messung überein', function () {
    ($this->doc)('regel', str_repeat('R', 700), 'regelwerk'); ($this->canon)('regel');
    ($this->doc)('kartoffel', str_repeat('F', 400)); ($this->route)();
    $preview = app(KnowledgePreviewService::class)->preview($this->rootTeam, 'recipe.description', 'Kartoffel');
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.description', 'Kartoffel');
    app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Kartoffel'], KnowledgeContextService::proposeOptionen($context));
    $parts = json_decode(DB::table('foodalchemist_ai_call_log')->latest('id')->value('prompt_parts'), true);
    expect($parts['kanon'] + $parts['retrieval'])->toBe($preview['total_chars'])->toBeLessThanOrEqual(1500)
        ->and($parts['dropped'])->toBe($preview['dropped_chars']);
});

it('hat eine gemeinsame Budgetquelle auch für den alten Generator-Alias', function () {
    expect(KnowledgeBudget::forKey('ai_generate_recipe'))->toBe(KnowledgeBudget::forKey('recipe.generator'))
        ->and(app(AiGatewayService::class)->boundBudgetFuer('recipe.description')['total'])
        ->toBe(app(KnowledgeContextService::class)->budgetFuer('recipe.description'))
        ->and(config('foodalchemist.ai.bound_knowledge_budget'))->toBeNull();
});
