<?php

use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M6-06: VK-Generator v1 — vkModus legt VK an (ist_verkaufsrezept=true),
 * Resolver findet Basisrezepte ZUERST (mode=sub_recipe_first), Klasse/AK aus
 * dem Vorschlag validiert (Lineage ki), ungültige Werte fallen still raus.
 * Voller KI-Pfad via kiRezeptOverride (FakeProvider-Grenze, wie M4-14);
 * DoD »3 echte Rezepte end-to-end« lief live gegen die Sandbox (Roadmap).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->gen = app(RecipeGeneratorService::class);

    FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $hg = FoodAlchemistDishMainGroup::create(['code' => 'HG', 'bezeichnung' => 'Hauptgang']);
    $this->klasse = FoodAlchemistDishClass::create(['dish_main_group_id' => $hg->id, 'code' => 'HG_FLEISCH', 'bezeichnung' => 'Fleisch', 'diaetform' => 'fleisch']);
    $this->alc = FoodAlchemistMarkupClass::create(['code' => 'ALC', 'bezeichnung' => 'A la Carte', 'rohaufschlag_pct' => 420, 'mwst_satz' => 19, 'formel_typ' => 'aufschlag']);

    // Bestand: ein freigegebenes Basisrezept als Komponenten-Kandidat
    $this->basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'jus', 'name' => 'Sauce: Rotwein-Jus', 'status' => 'approved',
        'yield_kg' => 1.0, 'ek_per_kg_eur' => 4.0, 'ek_total_eur' => 4.0,
    ]);
});

it('vkModus: VK angelegt, Basisrezept-Komponente resolved, Klasse/AK aus Vorschlag (Lineage ki)', function () {
    $res = $this->gen->generiere($this->rootTeam, 'Test', [], [
        'name' => 'HG: Filet | Rotwein-Jus',
        'zutaten' => [['text' => 'Rotwein-Jus', 'menge' => 80, 'einheit' => 'g', 'slug' => 'rotwein_jus']],
        'speisen_klasse_id' => $this->klasse->id,
        'aufschlagsklasse_code' => 'ALC',
    ], vkModus: true);

    $r = $res['recipe'];
    expect($r->ist_verkaufsrezept)->toBeTrue()
        ->and($r->speisen_klasse_id)->toBe($this->klasse->id)
        ->and($r->speisen_klasse_quelle)->toBe('ki')
        ->and($r->aufschlagsklasse_id)->toBe($this->alc->id)
        ->and((float) $r->mwst_satz)->toBe(19.0)
        ->and($res['statistik']['bestand_sub'])->toBe(1)              // Basisrezept ZUERST gefunden
        ->and($r->ingredients()->first()->referenced_recipe_id)->toBe($this->basis->id)
        ->and((float) $r->ek_total_eur)->toBeGreaterThan(0);          // Recompute lief

    // VK-Sicht ja, Basis-Sicht nein (Scope-Härte)
    expect(app(\Platform\FoodAlchemist\Services\SalesRecipeService::class)->detail($this->rootTeam, $r->id))->not->toBeNull()
        ->and(app(\Platform\FoodAlchemist\Services\RecipeService::class)->detail($this->rootTeam, $r->id))->toBeNull();
});

it('vkModus: ungültige Klasse/AK fallen still raus — VK trotzdem angelegt (Editor pflegt nach)', function () {
    $res = $this->gen->generiere($this->rootTeam, 'Test', [], [
        'name' => 'HG: Filet | Jus',
        'zutaten' => [['text' => 'Rotwein-Jus', 'menge' => 80, 'einheit' => 'g']],
        'speisen_klasse_id' => 999999,
        'aufschlagsklasse_code' => 'GIBTS_NICHT',
    ], vkModus: true);

    expect($res['recipe']->ist_verkaufsrezept)->toBeTrue()
        ->and($res['recipe']->speisen_klasse_id)->toBeNull()
        ->and($res['recipe']->aufschlagsklasse_id)->toBeNull();
});

it('vkModus: ohne AK-Code greift die Default-AK der Klasse', function () {
    $this->klasse->update(['default_markup_class_id' => $this->alc->id]);

    $res = $this->gen->generiere($this->rootTeam, 'Test', [], [
        'name' => 'HG: Filet | Jus',
        'zutaten' => [['text' => 'Rotwein-Jus', 'menge' => 80, 'einheit' => 'g']],
        'speisen_klasse_id' => $this->klasse->id,
    ], vkModus: true);

    expect($res['recipe']->aufschlagsklasse_id)->toBe($this->alc->id);
});
