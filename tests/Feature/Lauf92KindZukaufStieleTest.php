<?php

use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConformanceFinding;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\ConformanceService;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\ConformanceHealStub;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lauf 92 (demo, 10.10., Session 141):
 *  - 3834 „Beilage: Herbsttrompeten": Zeile „Gemüsebrühe" führt die Kaskade als Kind „Brühe: Gemüse" (Dependency). Die
 *    Heilung legte sie per Re-Grounding auf ein Bio-Brühpulver-GP — das gebaute Kind blieb verwaist.
 *  - 3832: §1.5 (KI) meldete den Klammerzusatz „(Zukauf)" hart — Hausentscheidung „Typ: Ware (Zukauf)".
 *  - „Petersilienstiele" → „Petersilienwurzeln: frisch" (Matcher, Derivat-Form „Stiele" fehlte im GP).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->beilage = $this->makeRecipe($this->rootTeam, 'Beilage: Herbsttrompeten', ['status' => 'draft']);
    $this->bruehe = $this->makeIngredient($this->beilage, 'Gemüsebrühe', null, '200', 1);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $parent = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $this->beilage->id, 'sort' => 1]);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $parent->id,
        'kind' => 'rezept', 'status' => 'running', 'depth' => 1, 'sort' => 2, 'label' => 'Brühe: Gemüse']);
    FoodAlchemistCascadeRecipeDependency::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $parent->id, 'ingredient_id' => $this->bruehe->id, 'child_step_id' => $kind->id]);
    $this->pulver = $this->makeGp($this->rootTeam, 'Gemüsebrühe: trocken, Pulver, hefefrei, BIO');

    // Prüf-Kanon wie ConformanceHealTest (die Heilung braucht ein aktives Regelwerk-Dossier im Pflichtkanon).
    \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'slug' => 'regelwerk-basisrezepte-6-mengen-einheiten-yield', 'title' => 'Regelwerk Basisrezepte §6', 'category' => 'regelwerk',
        'content_md' => 'REGELWERK §6.1 Produktnamen im Singular.', 'version' => 1, 'content_hash' => str_repeat('b', 64),
        'char_count' => 40, 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService::class)->set($this->rootTeam, [
        'scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'slug' => 'regelwerk-basisrezepte-6-mengen-einheiten-yield', 'mode' => 'pflicht',
    ]);
});

it('Re-Grounding lässt eine von der Kaskade geführte Zeile offen (kein GP über das geplante Kind)', function () {
    app(RecipeService::class)->syncIngredients($this->rootTeam, $this->beilage->id, [
        ['id' => $this->bruehe->id, 'raw_text' => 'Gemüsebrühe', 'quantity' => 200, 'unit_vocab_id' => $this->unitG($this->rootTeam)->id],
    ]);

    $zeile = FoodAlchemistRecipeIngredient::find($this->bruehe->id);
    expect($zeile)->not->toBeNull()->and($zeile->gp_id)->toBeNull();
});

it('Heilung: lässt die KI die geführte Zeile weg, kommt sie unverändert zurück (gleiche id, kein GP)', function () {
    $befund = ['paragraph' => '§6.1', 'schweregrad' => 'hart', 'feld' => 'name', 'begruendung' => 'Plural statt Singular', 'konfidenz' => 0.9];
    ConformanceHealStub::bind([[$befund], []], ['zutaten' => [['text' => 'Butter', 'quantity' => 30, 'einheit_slug' => 'g']]]);

    app(ConformanceService::class)->pruefeUndHeile($this->rootTeam, 'basisrezept', $this->beilage->id);

    $zeile = FoodAlchemistRecipeIngredient::find($this->bruehe->id);
    expect($zeile)->not->toBeNull()->and($zeile->deleted_at)->toBeNull()->and($zeile->gp_id)->toBeNull();
});

it('§1.5-KI-Befund über „(Zukauf)" wird verworfen, andere §1.5-Befunde nicht', function () {
    $a = app(RecipeConformanceAdapter::class);
    $zukauf = ['paragraph' => '§1.5', 'schweregrad' => 'hart', 'feld' => 'zutat:Jus: Rind (Zukauf)',
        'begruendung' => 'Der Klammerzusatz „(Zukauf)" ist kein zulässiger Variante-/Diskriminator-Wert.', 'quelle' => 'ki'];
    $anders = ['paragraph' => '§1.5', 'schweregrad' => 'weich', 'feld' => 'name', 'begruendung' => 'Klammerzusatz „(Haus)" ohne Mehrwert.', 'quelle' => 'ki'];

    expect($a->istBekannteAusnahme($zukauf))->toBeTrue()->and($a->istBekannteAusnahme($anders))->toBeFalse();

    // Ein Prüflauf ohne Heilung: der Zukauf-Befund darf gar nicht erst ankommen.
    ConformanceHealStub::bind([[$zukauf, $anders]]);
    $erg = app(ConformanceService::class)->pruefe($this->rootTeam, 'basisrezept', $this->beilage->id);
    expect(array_column($erg['befunde'], 'feld'))->toBe(['name']);
});

it('Matcher: nennt die Zeile eine Derivat-Form (Stiele), gewinnt kein GP ohne sie', function () {
    // Lexikalisch passt „Petersilie" voll — ohne die Form-Pflicht gewann das ganze Kraut für „Petersilie Stiele".
    $kraut = $this->makeGp($this->rootTeam, 'Petersilie: frisch');
    $m = fn () => app(IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Petersilie: frisch, Stiele');

    expect(($m()['gp_id'] ?? null) === $kraut->id && IngredientMatchService::istAutomatischVerdrahtbar($m()))->toBeFalse();

    $stiele = $this->makeGp($this->rootTeam, 'Petersilie: frisch, Stiele');
    expect($m()['gp_id'] ?? null)->toBe($stiele->id);
});
