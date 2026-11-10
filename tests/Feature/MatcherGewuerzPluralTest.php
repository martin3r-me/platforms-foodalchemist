<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Probelauf Excel-Rezepte 10.11. (9.205 Zeilen in fa_io): „Nelken“ (22×) und „Pimentkörner“ (21×) blieben offen, obwohl „Nelke“ und „Piment“
 * trafen. GP-Namen wie auf foodalchemist.io.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->nr = 0;
    $this->gp = fn (string $name, ?string $zustand, ?string $slug) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'gewuerz1011-' . (++$this->nr) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => '10', 'condition' => $zustand, 'main_ingredient_slug' => $slug]);
    $this->match = fn (string $text) => app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $text);
});

it('„Nelken“ trifft „Gewürznelke: ganz“ wie „Nelke“ — Plural am Wortende des Kompositums', function () {
    $ganz = ($this->gp)('Gewürznelke: ganz, trocken', 'trocken', 'gewuerznelke');
    ($this->gp)('Gewürznelke: gemahlen, trocken', 'trocken', 'gewuerznelke');

    expect(($this->match)('Nelke')['gp_id'])->toBe($ganz->id)
        ->and(($this->match)('Nelken')['gp_id'])->toBe($ganz->id);
});

it('„Pimentkörner“ trifft „Piment: ganz“ — „-körner“ ist die ganze Gewürzform', function () {
    $ganz = ($this->gp)('Piment: ganz, trocken', 'trocken', 'piment');
    ($this->gp)('Piment: gemahlen, trocken', 'trocken', 'piment');

    expect(($this->match)('Pimentkörner')['gp_id'])->toBe($ganz->id);
});

it('„Korianderkörner“ bleibt beim GP „Korianderkorn“, nie das frische Kraut „Koriander: ganz“', function () {
    $korn = ($this->gp)('Korianderkorn: ganz, trocken', 'trocken', 'korianderkorn');
    ($this->gp)('Koriander: ganz, frisch', 'frisch', 'koriander');

    expect(($this->match)('Korianderkörner')['gp_id'])->toBe($korn->id);
});
