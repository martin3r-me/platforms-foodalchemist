<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Warum die Schritt-Bilder immer anders aussahen.
 *
 * Die Prompts verlangten „same neutral stainless-steel catering kitchen" — nur hatte „same"
 * keinen Bezugspunkt. Jedes Bild ist ein eigener Call ohne Gedaechtnis, also erfand das Modell
 * jedes Mal eine andere Kueche. Und das Produktfoto lief ueber einen voellig anderen Prompt
 * (deutsch, ohne Stilregeln) — Hero und Schritte passten nicht einmal zueinander.
 *
 * Der Anker macht aus „dieselbe Kueche" benannte Konstanten, die in JEDEM Bild desselben
 * Rezepts woertlich gleich stehen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dienst = app(RecipeImageService::class);
});

it('★ derselbe Anker steht in JEDEM Schritt desselben Rezepts', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Kartoffelpueree');
    foreach ([1, 2, 3] as $i) {
        FoodAlchemistRecipeStep::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id,
            'position' => $i, 'phase' => 'vorbereitung', 'text' => "Schritt {$i}",
        ]);
    }
    $anker = $this->dienst->stilAnker($recipe);
    $recipe->refresh()->load('ingredients');

    $prompts = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)->orderBy('position')->get()
        ->map(fn ($s) => $this->dienst->schrittPrompt($recipe, $s));

    expect($anker)->not->toBe('');
    foreach ($prompts as $p) {
        expect($p)->toContain($anker);
    }
});

it('★ der Anker ist stabil — ein Nachlauf sieht aus wie der erste', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Stabil');

    expect($this->dienst->stilAnker($recipe))->toBe($this->dienst->stilAnker($recipe->fresh()));
});

it('zwei Rezepte bekommen NICHT zwangslaeufig dasselbe Bild — sonst saehe der ganze Katalog gleich aus', function () {
    // Der Anker variiert ueber die Rezept-ID. Bei drei Flaechen x zwei Gefaessen x zwei
    // Lichtrichtungen ist eine Kollision moeglich, aber ueber viele Rezepte nicht die Regel.
    $anker = collect(range(1, 12))
        ->map(fn ($i) => $this->dienst->stilAnker($this->makeRecipe($this->rootTeam, 'R'.$i)))
        ->unique();

    expect($anker->count())->toBeGreaterThan(1);
});

it('die vage Formel „same … kitchen" ist aus den Prompts verschwunden', function () {
    // Sie war der eigentliche Fehler: eine Anweisung, die Gleichheit VERLANGT, ohne zu sagen,
    // womit sie gleich sein soll.
    $recipe = $this->makeRecipe($this->rootTeam, 'Ohne Floskel');
    $step = FoodAlchemistRecipeStep::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id,
        'position' => 1, 'phase' => 'vorbereitung', 'text' => 'Schneiden',
    ]);
    $recipe->refresh()->load('ingredients');

    expect($this->dienst->schrittPrompt($recipe, $step))
        ->not->toContain('same neutral stainless-steel');
});
