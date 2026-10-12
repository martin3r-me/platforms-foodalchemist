<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Weg-B-Befund 5 (Agenten-Durchlauf Gericht 3920, 2026-10-12): `recipe_ingredients.PUT` ohne `id` legte alle
 * Zeilen neu an und soft-löschte die alten. Darreichungs-Deltas, Regenerations-Overrides und Teller-Rollen hingen
 * an den alten Ids und verwaisten still — „Portion weg", „Rollen weg". Eine id-lose Zeile trifft jetzt die
 * Bestandszeile mit demselben Ziel (Sub-Rezept oder GP), ersatzweise dem gleichen Namen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake']);
    FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'],
        ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->put = fn (int $recipeId, array $zutaten) => app(ToolRegistry::class)
        ->get('foodalchemist.recipe_ingredients.PUT')->execute(['recipe_id' => $recipeId, 'zutaten' => $zutaten], $this->ctx);
});

it('PUT ohne id: Zeilen mit gleichem Ziel bleiben dieselben Zeilen — Rolle, Darreichungs-Delta und Id überleben', function () {
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Senfrostbraten', ['status' => 'draft', 'is_sales_recipe' => true]);
    $sub = $this->makeRecipe($this->rootTeam, 'Jus: Altbier', ['status' => 'approved', 'yield_kg' => 1.0]);
    $gp = $this->makeGp($this->rootTeam, 'Gartenkresse: frisch');
    $jus = $this->makeIngredient($gericht, 'Jus: Altbier', null, '60', 1);
    $jus->update(['referenced_recipe_id' => $sub->id, 'role' => 'sauce', 'role_source' => 'manuell']);
    $kresse = $this->makeIngredient($gericht, 'Gartenkresse', $gp, '1', 2);
    $kresse->update(['role' => 'garnitur', 'role_source' => 'ki']);

    $form = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    $dar = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id,
        'serving_form_id' => $form->id, 'is_standard' => false, 'quantity_per_unit_g' => 300, 'unit_count' => 1]);
    $delta = FoodAlchemistRecipeDarreichungDelta::create(['team_id' => $this->rootTeam->id, 'presentation_id' => $dar->id,
        'recipe_ingredient_id' => $kresse->id, 'omitted' => true]);

    // Voll-Sync wie ein Agent: dieselben Komponenten, neue Mengen, KEINE ids
    $res = ($this->put)($gericht->id, [
        ['name' => 'Jus: Altbier', 'referenced_recipe_id' => $sub->id, 'quantity' => 80, 'unit' => 'g'],
        ['name' => 'Gartenkresse', 'gp_id' => $gp->id, 'quantity' => 2, 'unit' => 'g'],
    ]);
    expect($res->success)->toBeTrue($res->error ?? '');

    $zeilen = $gericht->ingredients()->orderBy('position')->get();
    expect($zeilen->pluck('id')->all())->toBe([$jus->id, $kresse->id])                   // keine neuen Zeilen
        ->and((float) $zeilen[0]->quantity)->toBe(80.0)
        ->and($zeilen[0]->role)->toBe('sauce')->and($zeilen[0]->role_source)->toBe('manuell')
        ->and($zeilen[1]->role)->toBe('garnitur')
        ->and($delta->fresh()->recipe_ingredient_id)->toBe($kresse->id)                  // Delta zeigt auf lebende Zeile
        ->and($gericht->ingredients()->onlyTrashed()->count())->toBe(0);
});

it('PUT ohne id: unverknüpfte Zeile trifft per Name; ein neues Ziel wird neu angelegt, weggelassene Zeilen fallen', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Test', ['status' => 'draft']);
    $lorbeer = $this->makeIngredient($r, 'Lorbeer', null, '2', 1);
    $salz = $this->makeIngredient($r, 'Salz', null, '5', 2);
    $gp = $this->makeGp($this->rootTeam, 'Zwiebeln: frisch');

    $res = ($this->put)($r->id, [
        ['name' => 'lorbeer', 'quantity' => 3, 'unit' => 'g'],                      // Name-Treffer, Groß/Klein egal
        ['name' => 'Zwiebeln', 'gp_id' => $gp->id, 'quantity' => 100, 'unit' => 'g'],   // neues Ziel
    ]);
    expect($res->success)->toBeTrue($res->error ?? '');

    $zeilen = $r->ingredients()->orderBy('position')->get();
    expect($zeilen->count())->toBe(2)
        ->and($zeilen[0]->id)->toBe($lorbeer->id)->and((float) $zeilen[0]->quantity)->toBe(3.0)
        ->and($zeilen[1]->gp_id)->toBe($gp->id)->and($zeilen[1]->id)->not->toBe($salz->id)
        ->and($r->ingredients()->onlyTrashed()->pluck('id')->all())->toBe([$salz->id]);  // weggelassen = raus, wie bisher
});

it('PUT ohne id: zwei Zeilen mit demselben GP treffen zwei verschiedene Bestandszeilen, nie dieselbe doppelt', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Salat: Test', ['status' => 'draft']);
    $gp = $this->makeGp($this->rootTeam, 'Tomate: frisch');
    $a = $this->makeIngredient($r, 'Tomate', $gp, '100', 1);
    $b = $this->makeIngredient($r, 'Tomate', $gp, '50', 2);

    $res = ($this->put)($r->id, [
        ['name' => 'Tomate', 'gp_id' => $gp->id, 'quantity' => 110, 'unit' => 'g'],
        ['name' => 'Tomate', 'gp_id' => $gp->id, 'quantity' => 55, 'unit' => 'g'],
    ]);
    expect($res->success)->toBeTrue($res->error ?? '')
        ->and($r->ingredients()->orderBy('position')->pluck('id')->all())->toBe([$a->id, $b->id])
        ->and($r->ingredients()->onlyTrashed()->count())->toBe(0);
});

it('Editor-Pfad (ohne preserveMissing) bleibt wie bisher: id-lose Zeile = neue Zeile', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Editor', ['status' => 'draft']);
    $gp = $this->makeGp($this->rootTeam, 'Karotte: frisch');
    $alt = $this->makeIngredient($r, 'Karotte', $gp, '100', 1);
    $g = FoodAlchemistVocabEinheit::where('team_id', $this->rootTeam->id)->where('slug', 'g')->firstOrFail();

    app(RecipeService::class)->syncIngredients($this->rootTeam, $r->id, [
        ['id' => null, 'gp_id' => $gp->id, 'raw_text' => 'Karotte', 'display_name' => 'Karotte', 'quantity' => '120', 'unit_vocab_id' => $g->id],
    ]);
    $neu = $r->ingredients()->first();
    expect($neu->id)->not->toBe($alt->id)
        ->and($r->ingredients()->onlyTrashed()->pluck('id')->all())->toBe([$alt->id]);
});
