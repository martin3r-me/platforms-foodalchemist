<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M6-07 / V-05 (Audit-Hebel 4): Decompounding-Fallback — Kompositum↔Split
 * (»Kürbispüree« ↔ »Püree: Kürbis«). STRENG additiv: läuft nur, wenn der
 * v1-Lauf unter MIN_MATCH_SCORE bleibt (GL-04-Goldens unberührt — die volle
 * 91er-Suite läuft unverändert mit). Benchmark live: 72 echte Legacy-
 * Komposita 8,3 % → 97,2 % Sub-Treffer (Roadmap).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->matcher = app(IngredientMatchService::class);

    $this->pueree = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'pueree_kuerbis', 'name' => 'Püree: Kürbis', 'status' => 'approved',
    ]);
    FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'braten_rind', 'name' => 'Braten: Rind', 'status' => 'approved',
    ]);
});

it('Kompositum findet das gesplittete Basisrezept (Kürbispüree → Püree: Kürbis)', function () {
    $m = $this->matcher->matchIngredient($this->rootTeam, 'Kürbispüree', null, 'sub_recipe_first');

    expect($m['target'])->toBe('sub_recipe')
        ->and($m['recipe_id'])->toBe($this->pueree->id);
});

it('Fugen-s wird toleriert (Rindsbraten → Braten: Rind)', function () {
    $m = $this->matcher->matchIngredient($this->rootTeam, 'Rindsbraten', null, 'sub_recipe_first');

    expect($m['target'])->toBe('sub_recipe');
});

it('Fallback ist additiv: Flag aus reproduziert v1 (unmatched)', function () {
    config(['foodalchemist.matching.decompound' => false]);

    $m = $this->matcher->matchIngredient($this->rootTeam, 'Kürbispüree', null, 'sub_recipe_first');

    expect($m['target'])->toBe('none');
    config(['foodalchemist.matching.decompound' => true]);
});

it('kein Pool-Vokabular-Treffer ⇒ kein zweiter Lauf, ehrlich unmatched', function () {
    $m = $this->matcher->matchIngredient($this->rootTeam, 'Drachenfruchtgelee', null, 'sub_recipe_first');

    expect($m['target'])->toBe('none');                               // weder drachenfrucht noch gelee im Vokabular
});
