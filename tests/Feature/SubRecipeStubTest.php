<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M4-10: Sub-Rezept-Stubs (F4.1) — idempotente Anlage, Generator-Markierung,
 * Guard-Löschung, Eltern-Hebung, ↑-Navigation.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeService::class);
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
});

it('Stub-Anlage ist idempotent (Token-Set-Dedupe) und generator-markiert', function () {
    $erster = $this->svc->createSubRecipeStub($this->rootTeam, 'Heller Kalbsfond');
    expect($erster['neu'])->toBeTrue()
        ->and($erster['recipe']->status->value)->toBe('stub')
        ->and($erster['recipe']->last_modified_by)->toBe('generator_stub');

    // identischer Name (andere Schreibung) ⇒ Dedupe, kein Duplikat
    $zweiter = $this->svc->createSubRecipeStub($this->rootTeam, 'heller KALBSFOND');
    expect($zweiter['neu'])->toBeFalse()
        ->and($zweiter['recipe']->id)->toBe($erster['recipe']->id)
        ->and(FoodAlchemistRecipe::where('name', 'like', '%Kalbsfond%')->count())->toBe(1);
});

it('Eltern-Stub wird bei Stub-Anlage auf draft gehoben', function () {
    $eltern = $this->svc->createSubRecipeStub($this->rootTeam, 'Consommé doppelt')['recipe'];
    expect($eltern->status->value)->toBe('stub');

    $this->svc->createSubRecipeStub($this->rootTeam, 'Klärfleisch-Ansatz', parentId: $eltern->id);
    expect($eltern->fresh()->status->value)->toBe('draft');
});

it('deleteGeneratorStub: Guards blocken Nicht-Stub, Nicht-Generator, Zutaten und Referenzen', function () {
    $stub = $this->svc->createSubRecipeStub($this->rootTeam, 'Phantom-Fond')['recipe'];

    // Referenz blockt
    $parent = $this->svc->create($this->rootTeam, ['name' => 'Suppe: Klar']);
    $ref = FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $parent->id, 'position' => 1,
        'raw_text' => 'Fond', 'menge' => 100, 'einheit_vocab_id' => $this->g->id,
        'referenced_recipe_id' => $stub->id, 'match_method' => 'recipe_ref',
    ]);
    expect(fn () => $this->svc->deleteGeneratorStub($this->rootTeam, $stub->id))
        ->toThrow(RuntimeException::class, 'referenziert');
    $ref->forceDelete();

    // Zutaten blocken
    $zutat = FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $stub->id, 'position' => 1,
        'raw_text' => 'Wasser', 'menge' => 1000, 'einheit_vocab_id' => $this->g->id, 'match_method' => 'manual',
    ]);
    expect(fn () => $this->svc->deleteGeneratorStub($this->rootTeam, $stub->id))
        ->toThrow(RuntimeException::class, 'Zutaten');
    $zutat->forceDelete();

    // Nicht-Generator blockt
    $stub->update(['last_modified_by' => 'editor']);
    expect(fn () => $this->svc->deleteGeneratorStub($this->rootTeam, $stub->id))
        ->toThrow(RuntimeException::class, 'generator');
    $stub->update(['last_modified_by' => 'generator_stub']);

    // Nicht-Stub blockt
    $stub->update(['status' => 'draft']);
    expect(fn () => $this->svc->deleteGeneratorStub($this->rootTeam, $stub->id))
        ->toThrow(RuntimeException::class, 'Kein Stub');
    $stub->update(['status' => 'stub']);

    // sauberer Stub geht
    $this->svc->deleteGeneratorStub($this->rootTeam, $stub->id);
    expect(FoodAlchemistRecipe::find($stub->id))->toBeNull();
});

it('getParents liefert die ↑-Navigation (DoD: Rekursion sichtbar)', function () {
    $sub = $this->svc->create($this->rootTeam, ['name' => 'Fond: Basis']);
    $a = $this->svc->create($this->rootTeam, ['name' => 'Suppe: A']);
    $b = $this->svc->create($this->rootTeam, ['name' => 'Suppe: B']);
    foreach ([$a, $b] as $parent) {
        FoodAlchemistRecipeIngredient::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $parent->id, 'position' => 1,
            'raw_text' => 'Fond', 'menge' => 100, 'einheit_vocab_id' => $this->g->id,
            'referenced_recipe_id' => $sub->id, 'match_method' => 'recipe_ref',
        ]);
    }

    expect($this->svc->getParents($this->rootTeam, $sub->id)->pluck('name')->all())
        ->toBe(['Suppe: A', 'Suppe: B']);
});
