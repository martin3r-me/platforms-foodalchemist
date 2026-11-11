<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kandidaten-Nachwahl (#789) und Zustand, 11.11.2026: die Nachwahl tauscht eine ungenannte Variante, nicht den Zustand (Golden
 * „Drei-Zustand-Pool“) — außer der Kandidat trägt ein Wort der Zeile, das der Treffer nicht hat (Probelauf Excel: „Chili gemahlen“).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->nr = 0;
    $this->gp = fn (string $name, ?string $zustand, string $slug) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'nwz-' . (++$this->nr) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => '10', 'condition' => $zustand, 'main_ingredient_slug' => $slug]);
});

it('„Chili gemahlen“ → Chilipulver: gemahlen, nie die frische Chili', function () {
    ($this->gp)('Chili: frisch, grün', 'frisch', 'chili');
    ($this->gp)('Chili: frisch, rot', 'frisch', 'chili');
    $pulver = ($this->gp)('Chilipulver: gemahlen, trocken', 'trocken', 'chilipulver');

    $t = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Chili gemahlen', null, 'gp_first', 'fresh_first', false, 'conventional');
    expect($t['gp_id'])->toBe($pulver->id);
});

it('„Tomaten“ unter „konserviert bevorzugt“: die Nachwahl macht aus „konserviert, geschält“ kein TK', function () {
    $kons = ($this->gp)('Tomaten: konserviert, geschaelt', 'konserviert', 'tomaten');
    ($this->gp)('Tomaten: TK', 'TK', 'tomaten');
    ($this->gp)('Tomaten: frisch', 'frisch', 'tomaten');

    $t = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Tomaten', 'tomaten', 'gp_first', 'preserved_first', false, 'neutral');
    expect($t['gp_id'])->toBe($kons->id);
});
