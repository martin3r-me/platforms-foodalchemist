<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\RecipesListTool;
use Platform\FoodAlchemist\Tools\VerkaufsrezepteListTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 91 PR 3 (C10, C11, D18): recipes.LIST und verkaufsrezepte.LIST filtern wie Rezept- und Gerichte-Browser und liefern deren Spalten.
 * Vorher: recipes.LIST kannte nur status/rolle, verkaufsrezepte.LIST gar keinen Filter — das LLM musste den ganzen Katalog blättern.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $t = $this->rootTeam->id;
    $this->sauceHg = FoodAlchemistRecipeMainGroup::create(['team_id' => $t, 'code' => 'SAU', 'label' => 'Saucen']);
    $this->fondKat = FoodAlchemistRecipeCategory::create(['team_id' => $t, 'main_group_id' => $this->sauceHg->id, 'code' => 'FOND', 'label' => 'Fonds']);
    $this->jusKat = FoodAlchemistRecipeCategory::create(['team_id' => $t, 'main_group_id' => $this->sauceHg->id, 'code' => 'JUS', 'label' => 'Jus']);
    $this->fond = $this->makeRecipe($this->rootTeam, 'Rinderfond braun', ['category_id' => $this->fondKat->id, 'taste_direction' => 'herzhaft',
        'production_depth' => 'from_scratch', 'n_ingredients_total' => 7, 'n_ingredients_unmapped' => 1, 'allergens_confidence' => 'medium']);
    $this->jus = $this->makeRecipe($this->rootTeam, 'Kalbsjus', ['category_id' => $this->jusKat->id, 'taste_direction' => 'herzhaft',
        'production_depth' => 'convenience', 'status' => 'draft']);
    $this->creme = $this->makeRecipe($this->rootTeam, 'Vanillecreme', ['taste_direction' => 'suess', 'production_depth' => 'teilfertig', 'is_template' => true]);
    $this->ohne = $this->makeRecipe($this->rootTeam, 'Rinderfond hell', ['category_id' => null]);
    $this->rezepte = fn (array $a) => collect(app(RecipesListTool::class)->execute($a, $this->ctx)->data['recipes'])->pluck('name')->sort()->values()->all();
});

it('C11 · recipes.LIST filtert wie der Rezept-Browser: q, hauptgruppe, kategorie, ohne_kategorie, status, geschmack, fertigung, nur_templates', function () {
    expect(($this->rezepte)(['q' => 'rinderfond']))->toBe(['Rinderfond braun', 'Rinderfond hell'])
        ->and(($this->rezepte)(['q' => 'fond braun']))->toBe(['Rinderfond braun'])
        ->and(($this->rezepte)(['hauptgruppe' => $this->sauceHg->id]))->toBe(['Kalbsjus', 'Rinderfond braun'])
        ->and(($this->rezepte)(['kategorie' => $this->jusKat->id]))->toBe(['Kalbsjus'])
        ->and(($this->rezepte)(['ohne_kategorie' => true]))->toBe(['Rinderfond hell'])
        ->and(($this->rezepte)(['status' => 'draft']))->toBe(['Kalbsjus'])
        ->and(($this->rezepte)(['geschmack' => 'suess']))->toBe(['Vanillecreme'])
        ->and(($this->rezepte)(['fertigung' => 'convenience']))->toBe(['Kalbsjus'])
        ->and(($this->rezepte)(['nur_templates' => true]))->toBe(['Vanillecreme'])
        ->and(($this->rezepte)(['hauptgruppe' => $this->sauceHg->id, 'geschmack' => 'herzhaft', 'status' => 'approved']))->toBe(['Rinderfond braun']);
    expect(app(RecipesListTool::class)->execute(['fertigung' => 'selbstgemacht'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and(app(RecipesListTool::class)->execute(['status' => 'freigegeben'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('D18 · recipes.LIST liefert Kategorie, Hauptgruppe, Geschmack, Fertigung, Zutatenzahl und Allergen-Konfidenz; unbekannte Parameter als warnungen', function () {
    $r = app(RecipesListTool::class)->execute(['q' => 'rinderfond braun', 'suche' => 'x'], $this->ctx);

    expect($r->data['recipes'][0])->toMatchArray(['kategorie' => ['id' => $this->fondKat->id, 'label' => 'Fonds'], 'hauptgruppe_id' => $this->sauceHg->id,
        'geschmack' => 'herzhaft', 'fertigung' => 'from_scratch', 'zutaten_anzahl' => 7, 'zutaten_offen' => 1, 'allergen_konfidenz' => 'medium'])
        ->and($r->data['warnungen'][0])->toContain("'suche'");
});

it('C10 + D18 · verkaufsrezepte.LIST filtert nach q, hauptgruppe, klasse, status, geschmack und liefert Status, Geschmack, Komponenten, Deklaration', function () {
    $tel = $this->makeMainGroup($this->rootTeam, 'TEL');
    $des = $this->makeMainGroup($this->rootTeam, 'DES');
    $vegan = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'code' => 'VEG', 'label' => 'vegan', 'diet_form' => 'vegan']);
    $gericht = fn (string $name, array $a) => $this->makeRecipe($this->rootTeam, $name, ['is_sales_recipe' => true] + $a);
    $gericht('Linsen-Bowl', ['dish_main_group_id' => $tel->id, 'dish_class_id' => $vegan->id, 'taste_direction' => 'herzhaft', 'n_ingredients_total' => 5, 'allergens_confidence' => 'low']);
    $gericht('Rinderroulade', ['dish_main_group_id' => $tel->id, 'taste_direction' => 'herzhaft', 'status' => 'review']);
    $gericht('Mousse au Chocolat', ['dish_main_group_id' => $des->id, 'taste_direction' => 'suess', 'marketing_text' => 'luftig, Bitterschokolade']);
    $namen = fn (array $a) => collect(app(VerkaufsrezepteListTool::class)->execute($a, $this->ctx)->data['verkaufsrezepte'])->pluck('name')->sort()->values()->all();

    expect($namen(['q' => 'bitterschokolade']))->toBe(['Mousse au Chocolat'])
        ->and($namen(['hauptgruppe' => $tel->id]))->toBe(['Linsen-Bowl', 'Rinderroulade'])
        ->and($namen(['klasse' => $vegan->id]))->toBe(['Linsen-Bowl'])
        ->and($namen(['status' => 'review']))->toBe(['Rinderroulade'])
        ->and($namen(['geschmack' => 'suess']))->toBe(['Mousse au Chocolat'])
        ->and($namen(['hauptgruppe' => $tel->id, 'status' => 'approved']))->toBe(['Linsen-Bowl'])
        ->and($namen([]))->not->toContain('Kalbsjus');   // Basisrezepte bleiben draußen
    $bowl = app(VerkaufsrezepteListTool::class)->execute(['q' => 'linsen'], $this->ctx)->data['verkaufsrezepte'][0];
    expect($bowl)->toMatchArray(['status' => 'approved', 'dish_class_id' => $vegan->id, 'geschmack' => 'herzhaft', 'komponenten_anzahl' => 5, 'deklaration' => 'low'])
        ->and(app(VerkaufsrezepteListTool::class)->execute(['geschmack' => 'sauer'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and(app(VerkaufsrezepteListTool::class)->execute(['kategorie' => 3], $this->ctx)->data['warnungen'][0])->toContain("'kategorie'");
});
