<?php

use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MVP-044 / MVP-050 (Audit 23, P0): Referenzierte Fremdschlüssel wurden roh aus einem
 * client-kontrollierten Formular übernommen. Die UI-Selects waren gescopt — damit war die
 * Auswahlliste die einzige „Prüfung", und die liegt im Browser. Wer `form.category_id`,
 * `dish_class_id` oder `markup_class_id` im Livewire-Payload austauschte, hängte fremde
 * Stammdaten an ein eigenes Rezept.
 *
 * ── Die Regel, an der hier alles hängt ──────────────────────────────────────────────────
 * Geprüft wird SICHTBARKEIT, nicht Eigentum. Das ist der Kern der Master-Vererbung: ein
 * Kind-Team MUSS die geerbte Kategorie, Klasse und Aufschlagsklasse am eigenen Rezept
 * verwenden dürfen. Wer beim Härten versehentlich auf `owns()` umstellt, macht den
 * Master-Katalog unbenutzbar — deshalb prüfen die Tests unten beide Richtungen. Wird der
 * Eltern-Fall rot, ist der Fix falsch, nicht der Test.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->userA = $this->makeUser($this->childA, 'Kind A User');
});

// ── Der Helfer selbst ───────────────────────────────────────────────────────

it('TeamScope::referenz akzeptiert Sichtbares und weist Fremdes ab', function () {
    $eigen = $this->makeRecipeCategory($this->childA, 'A-KAT');
    $vomMaster = $this->makeRecipeCategory($this->rootTeam, 'ROOT-KAT');
    $vomGeschwister = $this->makeRecipeCategory($this->childB, 'B-KAT');

    // Eigen und geerbt sind erlaubt — das ist der Zweck der Vererbung.
    expect(TeamScope::referenz(FoodAlchemistRecipeCategory::class, $eigen->id, $this->childA, 'Kategorie'))->toBe($eigen->id)
        ->and(TeamScope::referenz(FoodAlchemistRecipeCategory::class, $vomMaster->id, $this->childA, 'Kategorie'))->toBe($vomMaster->id)
        // Leeren bleibt möglich.
        ->and(TeamScope::referenz(FoodAlchemistRecipeCategory::class, null, $this->childA, 'Kategorie'))->toBeNull()
        ->and(TeamScope::referenz(FoodAlchemistRecipeCategory::class, '', $this->childA, 'Kategorie'))->toBeNull();

    // Geschwister und Phantom-IDs nicht.
    expect(fn () => TeamScope::referenz(FoodAlchemistRecipeCategory::class, $vomGeschwister->id, $this->childA, 'Kategorie'))
        ->toThrow(RuntimeException::class);
    expect(fn () => TeamScope::referenz(FoodAlchemistRecipeCategory::class, 999999, $this->childA, 'Kategorie'))
        ->toThrow(RuntimeException::class);
});

// ── Basisrezept: category_id (MVP-044) ──────────────────────────────────────

it('Rezept-Update weist eine fremde Kategorie ab (MVP-044)', function () {
    $svc = app(RecipeService::class);
    $rezept = $this->makeRecipe($this->childA, 'Eigene Sauce');
    $fremd = $this->makeRecipeCategory($this->childB, 'B-KAT');
    $vorher = $rezept->category_id;

    expect(fn () => $svc->update($this->childA, $rezept->id, ['category_id' => $fremd->id]))
        ->toThrow(RuntimeException::class);

    expect($rezept->fresh()->category_id)->toBe($vorher);
});

it('Rezept-Update akzeptiert die geerbte Kategorie des Masters (Vererbung bleibt nutzbar)', function () {
    $svc = app(RecipeService::class);
    $rezept = $this->makeRecipe($this->childA, 'Eigene Sauce');
    $vomMaster = $this->makeRecipeCategory($this->rootTeam, 'ROOT-KAT');

    $svc->update($this->childA, $rezept->id, ['category_id' => $vomMaster->id]);

    expect($rezept->fresh()->category_id)->toBe($vomMaster->id);
});

it('Rezept-Anlage weist eine fremde Kategorie ab (MVP-044, Create-Pfad)', function () {
    $svc = app(RecipeService::class);
    $fremd = $this->makeRecipeCategory($this->childB, 'B-KAT');

    expect(fn () => $svc->create($this->childA, ['name' => 'Neue Sauce', 'category_id' => $fremd->id]))
        ->toThrow(RuntimeException::class);
});

// ── Gericht: dish_class_id / markup_class_id / dish_main_group_id (MVP-050) ──

it('VK-Update weist fremde Klasse und fremde Aufschlagsklasse ab (MVP-050)', function () {
    $svc = app(SalesRecipeService::class);
    $gericht = $this->makeRecipe($this->childA, 'Eigenes Gericht', ['is_sales_recipe' => true]);

    $fremdeKlasse = FoodAlchemistDishClass::create([
        'team_id' => $this->childB->id, 'code' => 'B-VEG', 'label' => 'Vegan B',
        'diet_form' => 'vegan', 'dish_main_group_id' => null,
    ]);
    $fremdeAk = FoodAlchemistMarkupClass::create([
        'team_id' => $this->childB->id, 'code' => 'B-AK', 'label' => 'AK B', 'factor' => 3.0,
    ]);

    expect(fn () => $svc->updateVk($this->childA, $gericht->id, ['dish_class_id' => $fremdeKlasse->id]))
        ->toThrow(RuntimeException::class);
    expect(fn () => $svc->updateVk($this->childA, $gericht->id, ['markup_class_id' => $fremdeAk->id]))
        ->toThrow(RuntimeException::class);

    $frisch = $gericht->fresh();
    expect($frisch->dish_class_id)->toBeNull()
        ->and($frisch->markup_class_id)->toBeNull();
});

it('VK-Update akzeptiert globale und geerbte Vokabeln (Vererbung bleibt nutzbar)', function () {
    $svc = app(SalesRecipeService::class);
    $gericht = $this->makeRecipe($this->childA, 'Eigenes Gericht', ['is_sales_recipe' => true]);

    // Globaler Seed (team_id NULL) — gehört niemandem, ist für alle sichtbar.
    $global = FoodAlchemistDishClass::create([
        'team_id' => null, 'code' => 'GLOB-VEG', 'label' => 'Vegan global',
        'diet_form' => 'vegan', 'dish_main_group_id' => null,
    ]);
    $vomMaster = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id, 'code' => 'ROOT-AK', 'label' => 'AK Master', 'factor' => 3.0,
    ]);

    $svc->updateVk($this->childA, $gericht->id, [
        'dish_class_id' => $global->id,
        'markup_class_id' => $vomMaster->id,
    ]);

    $frisch = $gericht->fresh();
    expect($frisch->dish_class_id)->toBe($global->id)
        ->and($frisch->markup_class_id)->toBe($vomMaster->id);
});

it('VK-Update weist eine fremde Speisen-Hauptgruppe ab (Modell A: eigene Achse, MVP-049/050)', function () {
    $svc = app(SalesRecipeService::class);
    $gericht = $this->makeRecipe($this->childA, 'Eigenes Gericht', ['is_sales_recipe' => true]);
    $fremdeHg = $this->makeMainGroup($this->childB, 'B-HG');

    expect(fn () => $svc->updateVk($this->childA, $gericht->id, ['dish_main_group_id' => $fremdeHg->id]))
        ->toThrow(RuntimeException::class);
});

it('VK-Update kann die Hauptgruppe überhaupt setzen (MVP-049: war nicht persistierbar)', function () {
    $svc = app(SalesRecipeService::class);
    $gericht = $this->makeRecipe($this->childA, 'Eigenes Gericht', ['is_sales_recipe' => true]);
    $eigeneHg = $this->makeMainGroup($this->childA, 'A-HG');

    $svc->updateVk($this->childA, $gericht->id, ['dish_main_group_id' => $eigeneHg->id]);

    expect($gericht->fresh()->dish_main_group_id)->toBe($eigeneHg->id);
});
