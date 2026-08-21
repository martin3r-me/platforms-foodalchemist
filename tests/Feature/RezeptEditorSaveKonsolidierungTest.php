<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1b (Bug-Runde 2026-08): Konsolidierung des Rezept-Editor-Speicherns.
 *
 * Zwei Befunde aus dem Audit:
 *  - #1a: die „Gericht"-Checkbox (`is_sales_recipe`) war im Editor INERT — `create()` schrieb das
 *    Feld, `update()` NICHT. Toggle am Bestandsrezept wurde still verworfen (Toast trotzdem grün).
 *  - #1b: der Save war über zwei parallele Requests (`speichern` + `zutaten-speichern`) verdrahtet,
 *    und der Stammdaten-Save schloss das Modal SOFORT — ein Zutaten-Fehler blieb unsichtbar. Der
 *    Umbau sequenziert: Stammdaten zuerst, dann (adressiert) die Zutaten; geschlossen wird ERST auf
 *    die Rückmeldung `zutaten-persistiert` des Editors.
 *
 * Die Alpine-Sequenzierung selbst ist ohne Browsertreiber nicht testbar — hier stehen die
 * SERVER-Verträge, die halten müssen: was `update()` schreibt, was der Editor zurückmeldet und
 * wann das Modal schließt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $einheitId = $this->unitG($this->rootTeam)->id;
    $this->zeile = fn (string $text, string $menge) => [
        'raw_text' => $text, 'quantity' => $menge, 'unit_vocab_id' => $einheitId, 'position' => 1,
    ];
});

it('#1a: update() schaltet is_sales_recipe in beide Richtungen (vorher inert)', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Toggle-Rezept', ['status' => 'draft', 'is_sales_recipe' => false]);
    expect((bool) $r->is_sales_recipe)->toBeFalse();

    app(RecipeService::class)->update($this->rootTeam, $r->id, ['name' => $r->name, 'is_sales_recipe' => true]);
    expect((bool) $r->fresh()->is_sales_recipe)->toBeTrue();

    app(RecipeService::class)->update($this->rootTeam, $r->id, ['name' => $r->name, 'is_sales_recipe' => false]);
    expect((bool) $r->fresh()->is_sales_recipe)->toBeFalse();
});

it('#1a: update() ohne is_sales_recipe-Key lässt den Bestand unangetastet', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Bestandsflag', ['status' => 'draft', 'is_sales_recipe' => true]);

    // Ein Save, der das Feld gar nicht mitsendet, darf es nicht auf false zurücksetzen.
    app(RecipeService::class)->update($this->rootTeam, $r->id, ['name' => 'Umbenannt']);

    expect($r->fresh()->name)->toBe('Umbenannt')
        ->and((bool) $r->fresh()->is_sales_recipe)->toBeTrue();
});

it('#1b: IngredientEditor meldet zutaten-persistiert adressiert nach erfolgreichem Save', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Melder', ['status' => 'draft']);

    Livewire::test(IngredientEditor::class, ['recipeId' => $r->id, 'eingebettet' => true])
        ->call('speichern', [($this->zeile)('Zutat X', '100')], $r->id)
        ->assertSet('fehler', null)
        ->assertDispatched('zutaten-persistiert', recipeId: $r->id);
});

it('#1b: speichern() eines Bestandsrezepts schließt NICHT selbst — Name persistiert', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Bestand', ['status' => 'draft']);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $r->id)
        ->set('form.name', 'Bestand neu')
        ->call('speichern')
        ->assertSet('fehler', null)
        ->assertDispatched('recipe-selected', id: $r->id)
        ->assertNotDispatched('modal.close');   // Close erst nach Zutaten-Save (Race-Fix)

    expect($r->fresh()->name)->toBe('Bestand neu');
});

it('#1b: RecipeModal schließt auf ADRESSIERTES zutaten-persistiert', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Offen', ['status' => 'draft']);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $r->id)
        ->assertSet('recipeId', $r->id)
        ->call('beiZutatenPersistiert', $r->id)
        ->assertDispatched('modal.close', name: 'recipe-modal');
});

it('#1b: RecipeModal ignoriert ein zutaten-persistiert für ein FREMDES Rezept', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Offen', ['status' => 'draft']);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $r->id)
        ->call('beiZutatenPersistiert', $r->id + 999)
        ->assertNotDispatched('modal.close');
});
