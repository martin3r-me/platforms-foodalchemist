<?php

use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #6 N+1 (Bug-Runde 2026-08): der Verkauf-Browser eager-loadete unter den deprecated Aliassen
 * `speisenKlasse`/`speisenHauptgruppe`, die Blade liest aber `dishClass`/`dishMainGroup`. Da Eloquent
 * geladene Relationen unter dem EXAKTEN with()-Key ablegt, war `relationLoaded('dishClass')` false →
 * 1 Lazy-Query pro Zeile (bei perPage bis 500). Fix: with() auf die kanonischen Namen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('#6: paginateBrowser lädt dishClass + dishMainGroup eager (unter den kanonischen Keys)', function () {
    $g = $this->makeRecipe($this->rootTeam, 'VK Gericht', ['status' => 'draft', 'is_sales_recipe' => true]);

    $page = app(SalesRecipeService::class)->paginateBrowser([], $this->rootTeam);
    $r = collect($page->items())->firstWhere('id', $g->id);

    // Genau das, was die Browser-Blade anfasst, ist vorgeladen → kein Zeilen-Lazy-Load.
    expect($r)->not->toBeNull()
        ->and($r->relationLoaded('dishClass'))->toBeTrue()
        ->and($r->relationLoaded('dishMainGroup'))->toBeTrue();
});
