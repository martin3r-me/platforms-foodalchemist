<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeSearchService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgePreviewService;
use Platform\FoodAlchemist\Tests\Support\SeedsKanon;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsKanon::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config()->set('foodalchemist.semantic_search.enabled', false);
    $this->doc = function (string $slug, string $title, string $category = 'cross_cutting', string $content = 'Vollständiges Fachwissen.'): int {
        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $title,
            'team_id' => $this->rootTeam->id, 'category' => $category, 'content_md' => $content,
            'version' => 1, 'content_hash' => hash('sha256', $content), 'char_count' => mb_strlen($content),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->routing = function (string $key, string $category = 'cross_cutting', int $limit = 10): void {
        DB::table('foodalchemist_knowledge_routings')->updateOrInsert(['feature' => $key, 'category' => $category], [
            'mode' => 'discovery', 'max_docs' => $limit, 'max_chars_per_doc' => 4000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('fusioniert beide Ranglisten auch wenn die Lexik bereits alle Endplätze belegt', function () {
    foreach (range(1, 12) as $n) {
        ($this->doc)("linsen-{$n}", 'Linsen');
    }
    $target = ($this->doc)('z-linsen-suppe-huelsenfruechte-garverfahren', 'Linsen in Brühe schonend fertig garen');
    config()->set('foodalchemist.semantic_search.enabled', true);
    $embedding = Mockery::mock(KnowledgeEmbeddingService::class);
    $embedding->shouldReceive('searchEligibleDocIds')->once()->withArgs(fn ($query, $ids, $limit, $team) => $query === 'Linsen' && in_array($target, $ids) && $limit === 100)->andReturn([$target]);
    $this->app->instance(KnowledgeEmbeddingService::class, $embedding);
    $hits = app(KnowledgeContextService::class)->searchDocuments($this->rootTeam, 'Linsen', 'cross_cutting', 1);
    expect($hits[0]['id'])->toBe($target)
        ->and($hits[0]['via'])->toBe('hybrid')
        ->and($hits[0]['lexical_rank'])->toBeGreaterThan(1)
        ->and($hits[0]['semantic_rank'])->toBe(1);
});

it('ändert mit der Endauswahl weder den Kandidatendeckel noch die vorderen Ränge', function () {
    foreach (range(1, 8) as $n) ($this->doc)("linsen-{$n}", 'Linsen');
    $base = DB::table('foodalchemist_knowledge_documents')->where('category', 'cross_cutting');
    config()->set('foodalchemist.knowledge_search.candidate_limit', 5);
    $service = app(KnowledgeSearchService::class);
    $one = $service->search($base, 'Linsen', 1, false, $this->rootTeam);
    $three = $service->search($base, 'Linsen', 3, false, $this->rootTeam);
    expect($one)->toHaveCount(1)->and($three)->toHaveCount(3)
        ->and($one[0])->toBe($three[0])->and($one[0]['candidate_limit'])->toBe(5);
});

it('liefert für gleiche Anfrage und Filter dieselbe Rangfolge in Generator MCP und Browser', function () {
    ($this->doc)('a-allgemein', 'Linsen lagern');
    ($this->doc)('z-linsensuppe', 'Linsen Suppe');
    ($this->doc)('m-suppe', 'Suppe und Gemüse');
    ($this->routing)('test.ranking');
    $service = app(KnowledgeContextService::class);
    $expected = array_column($service->searchDocuments($this->rootTeam, 'Linsen Suppe', 'cross_cutting'), 'slug');
    $context = $service->contextFor($this->rootTeam, 'test.ranking', 'Linsen Suppe');
    $tool = app(ToolRegistry::class)->get('foodalchemist.knowledge.SEARCH')->execute(
        ['q' => 'Linsen Suppe', 'category' => 'cross_cutting'], new ToolContext($this->user, $this->rootTeam));
    expect(array_map(fn ($file) => explode('@', $file)[0], $context['files_used']))->toBe($expected)
        ->and(array_column($tool->data['documents'], 'slug'))->toBe($expected);
    Livewire::test(Browser::class)->set('semantic', false)->set('filterCategory', 'cross_cutting')
        ->set('filterStatus', 'active')->set('search', 'Linsen Suppe')
        ->assertViewHas('docs', fn ($docs) => $docs->pluck('slug')->all() === $expected);
});

it('behält Domain-Relevanz statt vor Top-K alphabetisch zu sortieren', function () {
    ($this->doc)('a-linsen-lager-vorrat', 'Linsen Vorrat', 'domain');
    ($this->doc)('z-linsen-suppe', 'Linsen Suppe', 'domain');
    ($this->routing)('test.domain', 'domain', 1);
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'test.domain', 'Linsen Suppe');
    expect($context['files_used'])->toBe(['z-linsen-suppe@v1'])
        ->and($context['herkunft']['z-linsen-suppe']['lexical_rank'])->toBe(1);
});

it('nimmt Titel und normalisierte mehrteilige Aliase in dieselbe Textsuche auf', function () {
    $id = ($this->doc)('neutrale-nummer-42', 'Kartoffeln nach Kochtyp');
    DB::table('foodalchemist_knowledge_aliases')->insert(['knowledge_document_id' => $id, 'alias_slug' => 'Erdäpfel festkochend', 'created_at' => now(), 'updated_at' => now()]);
    $service = app(KnowledgeContextService::class);
    expect($service->searchDocuments($this->rootTeam, 'Kochtyp')[0]['slug'])->toBe('neutrale-nummer-42')
        ->and($service->searchDocuments($this->rootTeam, 'Erdaepfel')[0]['via'])->toBe('alias');
});

it('filtert fremde Kategorien und inaktive Quellen vor der semantischen Kandidatenauswahl', function () {
    $active = ($this->doc)('linsen-aktiv', 'Linsen');
    $inactive = ($this->doc)('linsen-inaktiv', 'Linsen');
    $outside = ($this->doc)('linsen-domain', 'Linsen', 'domain');
    DB::table('foodalchemist_knowledge_documents')->where('id', $inactive)->update(['active' => 0]);
    $embedding = Mockery::mock(KnowledgeEmbeddingService::class);
    $embedding->shouldReceive('searchEligibleDocIds')->once()->withArgs(fn ($query, $ids) => in_array($active, $ids) && ! in_array($inactive, $ids) && ! in_array($outside, $ids))->andReturn([$active]);
    $this->app->instance(KnowledgeEmbeddingService::class, $embedding);
    config()->set('foodalchemist.semantic_search.enabled', true);
    expect(app(KnowledgeContextService::class)->searchDocuments($this->rootTeam, 'Linsen', 'cross_cutting'))->toHaveCount(1);
});

it('lässt optionales Wissen ohne passenden Treffer leer statt Rauschen zu wählen', function () {
    ($this->doc)('kartoffeln', 'Kartoffeln');
    ($this->routing)('test.kein-treffer');
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'test.kein-treffer', 'Vanille Schokolade');
    expect($context['files_used'])->toBe([])->and($context['block'])->toBe('');
});

it('gibt die Vorschau identisch über UI und MCP aus ohne Modellcall oder Rezeptanlage', function () {
    ($this->doc)('linsen-suppe', 'Linsen Suppe');
    ($this->doc)('regel-pflicht', 'Pflichtregel', 'regelwerk');
    $this->kanonZeile($this->rootTeam->id, 'recipe.description', 'regel-pflicht');
    ($this->routing)('recipe.description');
    $expected = app(KnowledgePreviewService::class)->preview($this->rootTeam, 'recipe.description', 'Linsen Suppe');
    $calls = DB::table('foodalchemist_ai_call_log')->count();
    $recipes = DB::table('foodalchemist_recipes')->count();
    $tool = app(ToolRegistry::class)->get('foodalchemist.knowledge.PREVIEW');
    expect($tool)->not->toBeNull();
    $result = $tool->execute(['prompt_key' => 'recipe.description', 'q' => 'Linsen Suppe'], new ToolContext($this->user, $this->rootTeam));
    expect($result->data)->toBe($expected);
    Livewire::test(Browser::class)->set('previewPromptKey', 'recipe.description')->set('previewQuery', 'Linsen Suppe')
        ->call('previewKnowledge')->assertSet('previewError', null)->assertSet('knowledgePreview', $expected)
        ->assertSee('regel-pflicht@v1')->assertSee('linsen-suppe@v1');
    expect(DB::table('foodalchemist_ai_call_log')->count())->toBe($calls)
        ->and(DB::table('foodalchemist_recipes')->count())->toBe($recipes);
});

it('stimmt mit dem tatsächlichen Gateway-Audit der verwendeten Quellen und Zeichen überein', function () {
    ($this->doc)('linsen-suppe', 'Linsen Suppe');
    ($this->doc)('regel-pflicht', 'Pflichtregel', 'regelwerk');
    $this->kanonZeile($this->rootTeam->id, 'recipe.description', 'regel-pflicht');
    ($this->routing)('recipe.description');
    $preview = app(KnowledgePreviewService::class)->preview($this->rootTeam, 'recipe.description', 'Linsen Suppe');
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.description', 'Linsen Suppe', null, [], ['_kanon_prompt_key' => 'recipe.description']);
    app(AiGatewayService::class)->propose('recipe.description', ['description' => 'Linsen Suppe'], KnowledgeContextService::proposeOptionen($context));
    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.description')->latest('id')->first();
    $parts = json_decode($log->prompt_parts, true);
    expect($preview['total_chars'])->toBe($parts['retrieval'] + $parts['kanon'])
        ->and($preview['dropped_chars'])->toBe($parts['dropped'])
        ->and(json_decode($log->knowledge_used, true))->toEqualCanonicalizing([...$preview['retrieval'], ...$preview['kanon']]);
});
