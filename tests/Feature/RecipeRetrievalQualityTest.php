<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\GenerationContextService;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.semantic_search.enabled' => false]);
});

it('erdet auch spät im gesprochenen Brief genannte Zutaten', function () {
    $ids = [];
    foreach (['Staudensellerie', 'Sahne', 'Olivenöl', 'Basilikum'] as $name) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['status' => 'approved']);
        $ids[] = $gp->id;
    }
    $out = app(GenerationContextService::class)->forGeneration($this->rootTeam,
        'Basisrezept für eine Tomatensuppe im Ansatz sollen Steckwürfel sein, einen klassischen Ansatz mit Gemüse, Staudensellerie, ein bisschen Sahne drin, mit Olivenöl eingemixt und auch mit etwas Basilikum');

    expect(array_column($out['gp_kandidaten']['treffer'], 'id'))->toContain(...$ids);
});

it('erdet bei 12 Zutaten alle, nicht nur die ersten 8 nach Position im Brief', function () {
    // Score-basiertes Grounding (#505-Nachtrag 2026-09): vorher array_slice() auf
    // MAX_TOKENS=8 NACH Position — bei 12 echten Zutaten-Tokens fielen die letzten 4
    // (rosmarin/olivenoel/kapern/oliven/basilikum) raus, obwohl kein Füllwort verdrängt.
    $namen = ['Zucchini', 'Aubergine', 'Paprika', 'Tomaten', 'Zwiebeln', 'Knoblauch',
        'Thymian', 'Rosmarin', 'Olivenöl', 'Kapern', 'Oliven', 'Basilikum'];
    $ids = [];
    foreach ($namen as $name) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['status' => 'approved']);
        $ids[] = $gp->id;
    }
    $out = app(GenerationContextService::class)->forGeneration($this->rootTeam,
        'Basisrezept für ein Ratatouille mit Zucchini, Aubergine, Paprika, Tomaten, Zwiebeln, Knoblauch, Thymian, Rosmarin, Olivenöl, Kapern, Oliven und Basilikum');

    expect(array_column($out['gp_kandidaten']['treffer'], 'id'))->toContain(...$ids);
});

it('verknüpft Dosentomaten mit konservierter Ware auch bei Frischepräferenz', function (string $query) {
    $fresh = $this->makeGp($this->rootTeam, 'Tomaten: frisch, ganz');
    $fresh->update(['status' => 'approved', 'condition' => 'frisch', 'main_ingredient_slug' => 'tomaten']);
    $canned = $this->makeGp($this->rootTeam, 'Tomaten: konserviert, stückig');
    $canned->update(['status' => 'approved', 'condition' => 'konserviert', 'main_ingredient_slug' => 'tomaten']);
    $matcher = app(IngredientMatchService::class);
    $match = $matcher->matchIngredient($this->rootTeam, $query, 'tomaten', 'gp_first', 'fresh', true);

    expect($match['gp_id'])->toBe($canned->id);
    $candidates = $matcher->candidatesFor($this->rootTeam, $query);
    expect(array_column($candidates, 'id'))->toContain($canned->id)->not->toContain($fresh->id);
})->with(['Stückige Tomaten, aus der Dose', 'Dosentomaten', 'Tomaten in Dosen']);

it('ersetzt fehlende Dosentomaten nicht durch frische Ware', function () {
    $fresh = $this->makeGp($this->rootTeam, 'Tomaten: frisch, ganz');
    $fresh->update(['status' => 'approved', 'condition' => 'frisch', 'main_ingredient_slug' => 'tomaten']);
    $matcher = app(IngredientMatchService::class);
    expect($matcher->matchIngredient($this->rootTeam, 'Dosentomaten')['target'])->toBe('none');
    expect($matcher->candidatesFor($this->rootTeam, 'Dosentomaten'))->toBeEmpty();
});

it('respektiert bei Domain-Recherche mehr als vier Quellen und priorisiert Relevanz', function () {
    foreach (['aaa-suppe', 'bbb-suppe', 'ccc-suppe', 'ddd-suppe', 'eee-suppe', 'zzz-tomaten-basilikum-suppe'] as $slug) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug,
            'category' => 'domain', 'content_md' => 'Wissen: '.$slug, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => 60, 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('foodalchemist_knowledge_routings')->updateOrInsert([
        'feature' => 'recipe.generator', 'category' => 'domain',
    ], ['mode' => 'discovery', 'max_docs' => 6, 'max_chars_per_doc' => 2400,
        'created_at' => now(), 'updated_at' => now()]);
    $service = app(KnowledgeContextService::class);
    $ctx = $service->contextFor(null, 'recipe.generator', 'Tomaten Basilikum Suppe');
    expect($ctx['used_by_category']['domain'])->toHaveCount(6);
    expect($ctx['used_by_category']['domain'][0])->toBe('zzz-tomaten-basilikum-suppe@v1');
    DB::table('foodalchemist_knowledge_routings')->where('feature', 'recipe.generator')
        ->where('category', 'domain')->update(['max_docs' => 1]);
    $ctx = $service->contextFor(null, 'recipe.generator', 'Tomaten Basilikum Suppe');
    expect($ctx['used_by_category']['domain'])->toBe(['zzz-tomaten-basilikum-suppe@v1']);
});

it('bezeichnet ein Rezept ohne Zutaten nicht als produktionsreif', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Leere Gemüsebrühe', ['ek_total_eur' => 1]);
    $result = app(RecipeService::class)->reifegrad($recipe);
    expect($result['reif'])->toBeFalse()->and($result['luecken'])->toContain('keine Zutaten');
});

it('laesst semantische Treffer den Dosen-Zustand nicht umgehen', function () {
    $fresh = $this->makeGp($this->rootTeam, 'Tomaten: frisch, ganz');
    $fresh->update(['status' => 'approved', 'condition' => 'frisch']);
    $canned = $this->makeGp($this->rootTeam, 'Tomaten: konserviert, stückig');
    $canned->update(['status' => 'approved', 'condition' => 'konserviert']);
    $semantic = Mockery::mock(\Platform\FoodAlchemist\Services\Ai\SemanticRetrievalService::class);
    $semantic->shouldReceive('enabled')->andReturn(true);
    $semantic->shouldReceive('candidates')->andReturn([
        ['entity_type' => \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_GP,
            'entity_id' => $fresh->id, 'score' => 0.99],
        ['entity_type' => \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_GP,
            'entity_id' => $canned->id, 'score' => 0.9],
    ]);
    $this->app->instance(\Platform\FoodAlchemist\Services\Ai\SemanticRetrievalService::class, $semantic);
    $candidates = app(IngredientMatchService::class)->candidatesFor($this->rootTeam, 'Dosentomaten');
    expect(array_column($candidates, 'id'))->toContain($canned->id)->not->toContain($fresh->id);
});
