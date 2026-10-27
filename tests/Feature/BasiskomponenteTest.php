<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\RecipeExtractService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Basiskomponenten im Rezepttext (Dominique 27.10. d): „Geflügelfond“, „Kalbsjus“, „Hollandaise“ sind meist ein Unterrezept. Erst den
 * Basisrezept-Bestand suchen, ohne Basisrezept einen Stub anlegen (Regelwerk Basisrezepte §4 F4.1), nie ein Zukauf-GP binden — außer mit
 * Zukauf-Signal im Text (Pulver, Paste, Glas, Marke, „Zukauf“) oder Convenience-Leitplanke.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->gp = fn (string $name, string $slug, ?string $zustand, string $wg, ?string $zusatz = null) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'bk-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug, 'zusatz' => $zusatz]);
    $this->fondGp = ($this->gp)('Geflügelfond: konserviert', 'gefluegelfond', 'konserviert', '13');
    $this->knorr = ($this->gp)('Geflügelfond: Pulver, trocken / (Knorr)', 'gefluegelfond', 'trocken', '13', 'Knorr');
    $this->m = fn (string $t, string $conv = 'standard') => app(IngredientMatchService::class)
        ->matchIngredient($this->rootTeam, $t, null, 'gp_first', 'fresh_first', false, 'conventional', convenience: $conv);
});

it('Geflügelfond: Basisrezept im Bestand gewinnt — auch im gp_first-Lauf und gegen das Zukauf-GP', function () {
    $sub = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'bk-fond', 'name' => 'Geflügelfond', 'status' => 'approved', 'is_sales_recipe' => false]);

    $r = ($this->m)('Geflügelfond');
    expect($r['target'])->toBe('sub_recipe')->and($r['recipe_id'])->toBe($sub->id)->and($r['basiskomponente'] ?? null)->toBe('rezept');
});

it('Geflügelfond ohne Basisrezept: kein Zukauf-GP, sondern Stub (Review mit Vorschlag)', function () {
    $r = ($this->m)('Geflügelfond');

    expect(IngredientMatchService::istAutomatischVerdrahtbar($r))->toBeFalse()
        ->and($r['basiskomponente'] ?? null)->toBe('stub')
        ->and($r['status'])->toBe(MatchBand::FuzzyLow);
});

it('Zukauf-Signal im Text („Pulver (Knorr)“) oder Convenience-Leitplanke → Zukauf-GP erlaubt', function () {
    $pulver = ($this->m)('Geflügelfond Pulver (Knorr)');
    $conv = ($this->m)('Geflügelfond', 'voll_convenience');

    expect(IngredientMatchService::istAutomatischVerdrahtbar($pulver))->toBeTrue()->and($pulver['gp_id'])->toBe($this->knorr->id)
        ->and($pulver)->not->toHaveKey('basiskomponente')
        ->and(IngredientMatchService::istAutomatischVerdrahtbar($conv))->toBeTrue()->and($conv['gp_id'])->toBe($this->fondGp->id);
});

it('Typ-Wort in Kaufware ist keine Basiskomponente: Würzsauce, Crème fraîche, Fruchtpüree (§3), Kartoffelpüree (§3)', function () {
    $soja = ($this->gp)('Sojasauce: konserviert', 'sojasauce', 'konserviert', '10');
    $heuristik = app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class);

    expect(($this->m)('Sojasauce')['gp_id'])->toBe($soja->id)
        ->and(IngredientMatchService::istAutomatischVerdrahtbar(($this->m)('Sojasauce')))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Crème fraîche'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Mangopüree'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Apfelpüree'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Austernsauce'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Sweet-Chili-Sauce'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Kartoffelpüree'))->toBeFalse()
        ->and($heuristik->istBasiskomponente('Erbsenpüree'))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Kalbsjus'))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Hollandaise'))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Pizzateig'))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Rotweinsoße'))->toBeTrue()
        ->and($heuristik->istBasiskomponente('Vanillecreme'))->toBeTrue();
});

it('Import: Geflügelfond ohne Basisrezept wird als Stub angelegt und verknüpft, mit Zukauf-Signal ans GP gebunden', function () {
    Queue::fake();
    $res = app(RecipeExtractService::class)->legeAn($this->rootTeam, ['typ' => 'basisrezept', 'name' => 'Geflügelrahmsauce Test', 'preparation' => 'Kochen.',
        'zutaten' => [['text' => 'Geflügelfond', 'quantity' => '500', 'unit' => 'g'], ['text' => 'Geflügelfond Pulver (Knorr)', 'quantity' => '10', 'unit' => 'g']]]);

    $zeilen = $res['recipe']->ingredients()->get()->keyBy('raw_text');
    $stub = FoodAlchemistRecipe::find($zeilen['Geflügelfond']->referenced_recipe_id);
    expect($stub)->not->toBeNull()->and($stub->status->value)->toBe('stub')->and($stub->name)->toBe('Geflügelfond')
        ->and($zeilen['Geflügelfond']->gp_id)->toBeNull()
        ->and($zeilen['Geflügelfond Pulver (Knorr)']->gp_id)->toBe($this->knorr->id)
        ->and($res['stubs'])->toHaveCount(1);
});
