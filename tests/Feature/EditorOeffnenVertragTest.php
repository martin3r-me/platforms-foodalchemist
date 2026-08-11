<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\Browser as RecipeBrowser;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\Browser as VkBrowser;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Öffnen-Vertrag der beiden Rezept-Editoren.
 *
 * ── Hintergrund MVP-045 (Audit 23): NICHT REPRODUZIERBAR ────────────────────────────────
 * Das Audit meldete „Namensklick lädt den Editor, öffnet ihn aber nicht sichtbar" mit der
 * Evidenz `data-rezept-speichern` habe `offsetParent = false`. In der Sandbox nachgemessen
 * (2026-07-30, Viewport 1280×800, Rezept 461 „Brauner Fond: Kalb"): der Dialog öffnet
 * sichtbar. `Alpine.$data(dialog).open === true`, Wrapper 1280×800, und
 * `document.elementFromPoint()` auf der Mitte des Speichern-Knopfes trifft den Knopf selbst
 * — er liegt also vorn und ist bedienbar. Gegengeprüft mit zurückgebautem Code: identisches
 * Verhalten, der Effekt hängt an keiner Änderung.
 *
 * Zwei Messfallen erklären den Befund:
 *  1. `offsetParent` ist für `position: fixed`-Elemente laut Spezifikation IMMER `null` —
 *     unabhängig von der Sichtbarkeit. Der Modal-Wrapper ist fixed. Die Sonde kann für
 *     dieses Element nur „unsichtbar" liefern. Belastbar sind `checkVisibility()` und ein
 *     Hit-Test per `elementFromPoint`.
 *  2. Wer direkt nach dem Klick misst, misst mitten im Livewire-Roundtrip: Serverzustand
 *     und Markup sind dann noch der Stand von vorher.
 *
 * Deshalb prüft dieser Test den Vertrag, der wirklich trägt — die Kette Namensklick →
 * Öffnen-Event → geladener Editor → Rückweg beim Schließen. Die Sichtbarkeit selbst ist
 * eine Browsereigenschaft und gehört in eine E2E-Schicht (MVP-002), nicht in einen
 * Livewire-Test, der sie nur vortäuschen könnte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('Namensklick fordert genau diesen Editor an (Basisrezept und Gericht)', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'BBQ Texas');
    $gericht = $this->makeRecipe($this->rootTeam, 'Käseplatte', ['is_sales_recipe' => true]);

    // Namensklick = Editor; die Zeilenauswahl fürs Detailpanel passiert zusätzlich.
    Livewire::test(RecipeBrowser::class)
        ->call('bearbeite', $rezept->id)
        ->assertSet('recipeId', $rezept->id)
        ->assertDispatched('recipe-modal.oeffnen', id: $rezept->id);

    Livewire::test(VkBrowser::class)
        ->call('bearbeite', $gericht->id)
        ->assertDispatched('vk-modal.oeffnen', id: $gericht->id);
});

it('Basisrezept-Editor lädt auf das Öffnen-Event und meldet den Dialog an', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'BBQ Texas');

    Livewire::test(RecipeModal::class)
        ->assertSet('istOffen', false)
        ->dispatch('recipe-modal.oeffnen', id: $rezept->id)
        ->assertSet('istOffen', true)
        ->assertSet('recipeId', $rezept->id)
        ->assertSee('BBQ Texas')
        // Der Baustein hört auf dieses Event — ohne es bliebe der Dialog zu.
        ->assertDispatched('modal.open', name: 'recipe-modal');
});

it('Gerichte-Editor lädt auf das Öffnen-Event und meldet den Dialog an', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Käseplatte', ['is_sales_recipe' => true]);

    Livewire::test(VkModal::class)
        ->dispatch('vk-modal.oeffnen', id: $gericht->id)
        ->assertSet('recipeId', $gericht->id)
        ->assertDispatched('modal.open', name: 'vk-modal');
});

it('hartes Schließen räumt den Serverzustand ab (State-Leak-Vertrag)', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'BBQ Texas');

    Livewire::test(RecipeModal::class)
        ->dispatch('recipe-modal.oeffnen', id: $rezept->id)
        ->assertSet('istOffen', true)
        ->assertSet('recipeId', $rezept->id)
        ->assertSet('form.name', 'BBQ Texas')
        // `modal.closed` erreicht Livewire — sechs Komponenten bauen per #[On] darauf auf.
        ->dispatch('modal.closed', name: 'recipe-modal')
        ->assertSet('istOffen', false)
        ->assertSet('recipeId', null)
        ->assertSet('form.name', '');
});
