<?php

use Platform\FoodAlchemist\Services\KostenCacheService;
use Platform\FoodAlchemist\Services\Reife\RecipeReifeAdapter;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Neuabgleich der Pilot-Rezepte (05.11.): Rezepte mit offener Zeile (Kümmelöl, Mangomark, Beef Jerky) standen auf grün und „EK vollständig“ —
 * die EK-Zähler kennen nur zugeordnete Zeilen. Eine offene Pflicht-Zutat ist eine Lücke; eine offene optionale nicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Aufstrich: Spundekäs', ['status' => 'draft']);
    $this->butter = $this->makeGp($this->rootTeam, 'Butter: frisch');
    $this->makeIngredient($this->rezept, 'Butter', $this->butter, '75', 1);
    $this->rezept->update(['ek_total_eur' => 1.0, 'ek_n_ingredients_total' => 1, 'ek_n_ingredients_priced' => 1, 'n_ingredients_unmapped' => 0]);
    $this->luecken = fn () => array_column(app(RecipeReifeAdapter::class)->messe($this->rootTeam, $this->rezept->id)['luecken'] ?? [], 'code');
});

it('ohne offene Zeile: keine Lücke, EK vollständig (Gegenprobe)', function () {
    expect(($this->luecken)())->not->toContain('zutaten_offen')
        ->and(app(KostenCacheService::class)->fuer($this->rezept->fresh(), $this->rootTeam)['vollstaendig'])->toBeTrue();
});

it('offene Pflicht-Zutat: Lücke zutaten_offen mit Namen, EK nicht vollständig', function () {
    $this->makeIngredient($this->rezept, 'Kümmelöl', null, '15', 2);
    $this->rezept->update(['n_ingredients_unmapped' => 1]);

    $kosten = app(KostenCacheService::class)->fuer($this->rezept->fresh(), $this->rootTeam);
    $luecke = collect(app(RecipeReifeAdapter::class)->messe($this->rootTeam, $this->rezept->id)['luecken'])->firstWhere('code', 'zutaten_offen');

    expect($luecke)->not->toBeNull()
        ->and($luecke['was'])->toContain('Kümmelöl')
        ->and($kosten['vollstaendig'])->toBeFalse()
        ->and($kosten['grund'])->toBe('zutaten_offen')
        ->and($kosten['n_total'])->toBe(2);
});

it('offene OPTIONALE Zutat ist keine Lücke', function () {
    $this->makeIngredient($this->rezept, 'Kresse zum Garnieren', null, '2', 2)->update(['is_optional' => true]);
    $this->rezept->update(['n_ingredients_unmapped' => 1]);

    expect(($this->luecken)())->not->toContain('zutaten_offen')
        ->and(app(KostenCacheService::class)->fuer($this->rezept->fresh(), $this->rootTeam)['vollstaendig'])->toBeTrue();
});
