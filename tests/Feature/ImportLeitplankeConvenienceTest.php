<?php

use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Leitplanke Eigenleistung beim Import (Dominique 05.11.: „kommt auf die Leitplanke an“). Ohne Wahl und bei from_scratch bleibt §2 (Rohform)
 * — die Rohform-Tests (Kartoffel gegart …) sind der Default und unverändert. teil_convenience nimmt eine vorhandene Fertigware bei Zubereitung,
 * voll_convenience zusätzlich bei Schnittformen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->nr = 0;
    $this->gp = fn (string $name, string $wg, ?string $zustand, string $slug) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'lp-' . (++$this->nr) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug]);
    $this->auto = function (string $text, string $convenience = 'standard') {
        $t = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $text, convenience: $convenience);

        return IngredientMatchService::istAutomatischVerdrahtbar($t) ? (int) $t['gp_id'] : null;
    };
});

it('Zubereitung: ohne Wahl und from_scratch Rohform, ab teil_convenience die vorhandene Fertigware', function () {
    $roh = ($this->gp)('Rote Bete: frisch', '01', 'frisch', 'rote_bete');
    $gegart = ($this->gp)('Rote Bete: gegart, konserviert', '01', 'konserviert', 'rote_bete');

    // ohne Leitplanke: nie die Fertigware (die Rohform kommt in diesem Mini-Bestand nur als Vorschlag, wie bisher)
    expect(($this->auto)('Rote Bete, gekocht'))->not->toBe($gegart->id)
        ->and(($this->auto)('Rote Bete, gekocht', 'from_scratch'))->not->toBe($gegart->id)
        ->and(($this->auto)('Rote Bete, gekocht', 'teil_convenience'))->toBe($gegart->id)
        ->and(($this->auto)('Rote Bete, gekocht', 'voll_convenience'))->toBe($gegart->id)
        ->and(($this->auto)('Rote Bete', 'teil_convenience'))->toBe($roh->id);   // nichts genannt → Rohform
});

it('Schnittform: nur voll_convenience nimmt die Schnittware, teil_convenience bleibt bei der Rohform', function () {
    $ganz = ($this->gp)('Zwiebel: ganz, frisch', '01', 'frisch', 'zwiebel');
    $wuerfel = ($this->gp)('Zwiebel: Würfel 5 mm, frisch', '01', 'frisch', 'zwiebel');

    expect(($this->auto)('Zwiebel, gewürfelt'))->toBe($ganz->id)
        ->and(($this->auto)('Zwiebel, gewürfelt', 'teil_convenience'))->toBe($ganz->id)
        ->and(($this->auto)('Zwiebel, gewürfelt', 'voll_convenience'))->toBe($wuerfel->id);
});

it('recipes.EXTRACT bietet die Leitplanke an', function () {
    $schema = app(ToolRegistry::class)->get('foodalchemist.recipes.EXTRACT')->getSchema();

    expect($schema['properties']['convenience']['enum'] ?? [])->toBe(['from_scratch', 'teil_convenience', 'voll_convenience']);
});
