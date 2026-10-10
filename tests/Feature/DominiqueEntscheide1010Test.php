<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Hausentscheidungen Dominique 10.10. (Lauf 91/92, über die Kuratorin). */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gp = function (string $name, array $mehr = []) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['status' => 'approved'] + $mehr);

        return $gp->fresh();
    };
});

it('§5 Default: das Gattungswort „Öl" ist Rapsöl — ein Aromaöl gewinnt nicht', function () {
    $raps = ($this->gp)('Rapsoel / Pflanzenoel: trocken, raffiniert');
    ($this->gp)('Lauchoel Groenn: trocken');

    $m = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Öl');

    expect($m['gp_id'])->toBe($raps->id)->and(IngredientMatchService::istAutomatischVerdrahtbar($m))->toBeTrue();
});

it('§2 Schnittform kennt geachtelt/geviertelt/halbiert', function () {
    $tokens = FoodAlchemistRule::where('schluessel', 'basisrezept.2.schnittform')->firstOrFail()->params['tokens'];

    expect($tokens)->toContain('geachtelt')->toContain('geviertelt')->toContain('halbiert');
});

it('Hausstandard Jus/Fond: ein Derivat-GP (is_derivat) ist immer erlaubt, derselbe Name ohne Kennzeichen warnt', function () {
    $regel = FoodAlchemistRule::where('schluessel', 'basisrezept.hausstandard.fond_knochen')->firstOrFail();
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind', ['status' => 'draft']);
    $this->makeIngredient($jus, 'Rind: frisch, Beinscheibe', ($this->gp)('Rind: frisch, Beinscheibe', ['commodity_group_code' => '04', 'is_derivat' => true]), '1000', 1);
    $this->makeIngredient($jus, 'Rinderbeinscheiben: frisch', ($this->gp)('Rinderbeinscheiben: frisch', ['commodity_group_code' => '04']), '1000', 2);

    $felder = collect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $jus->id))
        ->where('rule_id', $regel->id)->pluck('feld')->all();

    expect($felder)->toBe(['zutat:Rinderbeinscheiben: frisch']);
});

it('Re-Grounding korrigiert §2 wie der Generator: teilfertig → Rohform + Notiz, convenience → bleibt', function () {
    $geachtelt = ($this->gp)('Zwiebeln: frisch, geachtelt', ['condition' => 'frisch']);
    $ganz = ($this->gp)('Zwiebeln: frisch, ganz', ['condition' => 'frisch']);
    $g = $this->unitG($this->rootTeam)->id;
    $lauf = function (?string $tiefe) use ($g) {
        $r = $this->makeRecipe($this->rootTeam, 'Brühe: Gemüse ' . ($tiefe ?? 'leer'), ['status' => 'draft', 'production_depth' => $tiefe]);
        app(RecipeService::class)->syncIngredients($this->rootTeam, $r->id, [
            ['raw_text' => 'Zwiebeln: frisch, geachtelt', 'quantity' => 500, 'unit_vocab_id' => $g],
        ]);

        return FoodAlchemistRecipeIngredient::where('recipe_id', $r->id)->first();
    };

    $teil = $lauf('teilfertig');
    expect($teil->gp_id)->toBe($ganz->id)->and($teil->note)->toBe('geachtelt');
    expect($lauf('convenience')->gp_id)->toBe($geachtelt->id);
});

it('Hausstandard: steht der Cut als Hauptzutat im Namen, ist er Zweck — „Fond: Tafelspitz" warnt nicht, „Jus: Rind" schon', function () {
    $regel = FoodAlchemistRule::where('schluessel', 'basisrezept.hausstandard.fond_knochen')->firstOrFail();
    $befunde = fn ($r) => collect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $r->id))
        ->where('rule_id', $regel->id)->pluck('feld')->all();
    $tafelspitz = ($this->gp)('Rindertafelspitz: frisch, pariert', ['commodity_group_code' => '04']);   // demo-Name
    $suppenhuhn = ($this->gp)('Huhn: Suppenhuhn', ['commodity_group_code' => '04']);

    $fond = $this->makeRecipe($this->rootTeam, 'Fond: Tafelspitz', ['status' => 'draft']);
    $this->makeIngredient($fond, 'Rindertafelspitz: frisch, pariert', $tafelspitz, '2000', 1);
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Rind', ['status' => 'draft']);
    $this->makeIngredient($jus, 'Rindertafelspitz: frisch, pariert', $tafelspitz, '2000', 1);
    $bruehe = $this->makeRecipe($this->rootTeam, 'Brühe: Suppenhuhn', ['status' => 'draft']);
    $this->makeIngredient($bruehe, 'Huhn: Suppenhuhn', $suppenhuhn, '1500', 1);

    expect($befunde($fond))->toBe([])
        ->and($befunde($jus))->toBe(['zutat:Rindertafelspitz: frisch, pariert'])
        ->and($befunde($bruehe))->toBe([]);
});
