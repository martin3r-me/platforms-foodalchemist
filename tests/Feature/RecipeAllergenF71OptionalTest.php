<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * F7.1 verfeinert (User-Entscheid 2026-08-22): der Allergen-/Zusatzstoff-„unbekannt"-
 * Guard löst NUR bei nicht-optionalen ungemappten Zutaten aus. Optionale Zutaten sind
 * aus der ALL-MAXIMAL-Aggregation (aggregationsZutaten) ohnehin ausgeschlossen — eine
 * ungemappte optionale Garnitur darf das bekannte Profil der Pflicht-Zutaten nicht auf
 * „unbekannt" verwerfen. Spiegel: RecipeRecomputeService::hatUngemappteRelevante().
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeRecomputeService::class);
    $this->g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ])->id;

    // Gemappter Pflicht-GP mit Allergen-Override gluten=enthalten (GL-01 Prio 1).
    $this->gpGluten = $this->makeGp($this->rootTeam, 'Weizenmehl 405');
    $this->gpGluten->update(['allergen_gluten' => 'enthalten', 'n_las_total' => 1]);

    $this->mkRecipe = fn (string $n) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => str_replace(' ', '_', mb_strtolower($n)), 'name' => $n, 'status' => 'draft',
    ]);
    $this->mkZutat = function (FoodAlchemistRecipe $r, array $a) {
        static $p = 0;

        return FoodAlchemistRecipeIngredient::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'position' => ++$p,
            'raw_text' => $a['raw_text'] ?? 'Zutat', 'match_method' => 'manual', 'unit_vocab_id' => $this->g, 'quantity' => 100, ...$a,
        ]);
    };
});

it('optionale ungemappte Zutat verwirft NICHT das bekannte Allergenprofil (F7.1 verfeinert)', function () {
    $r = ($this->mkRecipe)('F71 optional');
    ($this->mkZutat)($r, ['gp_id' => $this->gpGluten->id]);                                  // Pflicht, gemappt (gluten)
    ($this->mkZutat)($r, ['gp_id' => null, 'is_optional' => true, 'raw_text' => 'Deko-Garnitur']); // optional, ungemappt

    $this->svc->recomputePipeline($r->id);
    $r->refresh();

    expect($r->allergen_gluten)->toBe('enthalten')            // NICHT 'unbekannt' → Profil erhalten
        ->and($r->spec_is_gluten_free)->toBe(false)
        ->and($r->n_ingredients_unmapped)->toBe(1);           // Gesamt-Zähler bleibt korrekt (Anzeige)
});

it('nicht-optionale ungemappte Zutat verwirft das Profil weiterhin (F7.1 konservativ)', function () {
    $r = ($this->mkRecipe)('F71 pflicht');
    ($this->mkZutat)($r, ['gp_id' => $this->gpGluten->id]);                                   // Pflicht, gemappt
    ($this->mkZutat)($r, ['gp_id' => null, 'is_optional' => false, 'raw_text' => 'Unbekannte Pflichtzutat']); // Pflicht, ungemappt

    $this->svc->recomputePipeline($r->id);
    $r->refresh();

    expect($r->allergen_gluten)->toBe('unbekannt')            // Guard feuert → falsche Sicherheit vermieden
        ->and($r->spec_is_gluten_free)->toBeNull();
});
