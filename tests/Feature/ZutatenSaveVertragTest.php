<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MVP-046 (Audit 23, P0 · Datenverlust): „Speichern" im Basisrezept-Editor konnte die Zutaten
 * eines gar nicht sichtbaren Rezepts überschreiben.
 *
 * Mechanik: der Speichern-Knopf feuerte `$dispatch('zutaten-speichern')` — ein Alpine-Event ohne
 * jede Adressierung, das bis `window` hochblubbert. Auf der Rezeptseite sind gleichzeitig zwei
 * bis drei Zutaten-Editor-Instanzen montiert (Standalone-Modal, eingebettet im Rezept-Editor,
 * eingebettet im Gerichte-Editor). Jede lauschte per `window.addEventListener` und rief ihr
 * eigenes `$wire.speichern(payload())` — ein Klick, bis zu drei Writes auf drei Rezepte, jeder
 * mit dem Client-Stand SEINER Instanz. `syncIngredients()` ersetzt den kompletten Zutatensatz,
 * der Verlust am unsichtbaren Rezept war also total.
 *
 * Der Team-Guard schützt hier ausdrücklich NICHT: alle betroffenen Rezepte gehören demselben
 * Team. Deshalb sitzt die Grenze im Editor selbst — und dort wird sie hier geprüft.
 *
 * Geprüft wird die SERVER-Grenze, nicht das Browser-Event: ein echtes Rennen zweier
 * Alpine-Instanzen ist ohne Browsertreiber nicht reproduzierbar. Die Server-Grenze ist die, die
 * auch einen manipulierten Livewire-Call abweist — und damit die, die halten muss.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    // Zwei Rezepte, DASSELBE Team — genau die Konstellation, in der D1 nicht greift.
    $this->rezeptA = $this->makeRecipe($this->rootTeam, 'Rezept A');
    $this->rezeptB = $this->makeRecipe($this->rootTeam, 'Rezept B');
    $this->makeIngredient($this->rezeptA, 'Zutat von A', null, '100', 1);
    $this->makeIngredient($this->rezeptB, 'Zutat von B', null, '200', 1);

    $this->zutatenVon = fn (int $recipeId) => FoodAlchemistRecipeIngredient::where('recipe_id', $recipeId)
        ->whereNull('deleted_at')->orderBy('position')->pluck('raw_text')->all();

    // syncIngredients erwartet je Zeile eine Einheit — der Client-Payload trägt sie mit.
    $einheitId = $this->unitG($this->rootTeam)->id;
    $this->zeile = fn (string $text, string $menge) => [
        'raw_text' => $text, 'quantity' => $menge, 'unit_vocab_id' => $einheitId, 'position' => 1,
    ];
});

it('weist einen Save ab, der an ein anderes Rezept adressiert ist (MVP-046)', function () {
    $editorB = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezeptB->id, 'eingebettet' => true]);

    // Der Unfall aus dem Audit: Instanz B bekommt den Klick, der Payload gehört zu A.
    $editorB->call('speichern', [($this->zeile)('Fremder Stand aus A', '999')], $this->rezeptA->id);

    expect(($this->zutatenVon)($this->rezeptA->id))->toBe(['Zutat von A'])
        ->and(($this->zutatenVon)($this->rezeptB->id))->toBe(['Zutat von B']);
});

it('speichert weiterhin, wenn der Save an das eigene Rezept adressiert ist', function () {
    Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezeptB->id, 'eingebettet' => true])
        ->call('speichern', [($this->zeile)('Neue Zutat für B', '150')], $this->rezeptB->id);

    expect(($this->zutatenVon)($this->rezeptB->id))->toBe(['Neue Zutat für B'])
        ->and(($this->zutatenVon)($this->rezeptA->id))->toBe(['Zutat von A']);
});

it('räumt den Standalone-Zustand beim Schließen ab, statt ihn liegen zu lassen (MVP-046)', function () {
    $standalone = Livewire::test(IngredientEditor::class);

    $standalone->call('oeffnen', $this->rezeptA->id)
        ->assertSet('recipeId', $this->rezeptA->id);

    // Vorher blieb `recipeId` nach dem Schließen unbegrenzt stehen — der stale Zeiger war die
    // Voraussetzung dafür, dass ein späterer fremder Klick überhaupt auf A schreiben konnte.
    $standalone->dispatch('modal.closed', name: 'zutaten-editor')
        ->assertSet('recipeId', null);

    // Und ein Save ohne Ziel darf nichts leerräumen.
    $standalone->call('speichern', []);

    expect(($this->zutatenVon)($this->rezeptA->id))->toBe(['Zutat von A']);
});

it('ein leerer Payload an ein fremdes Ziel räumt nichts leer', function () {
    // Der eigentliche Datenverlust war nicht ein falscher Wert, sondern eine geleerte Liste.
    Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezeptB->id, 'eingebettet' => true])
        ->call('speichern', [], $this->rezeptA->id);

    expect(($this->zutatenVon)($this->rezeptA->id))->toBe(['Zutat von A'])
        ->and(($this->zutatenVon)($this->rezeptB->id))->toBe(['Zutat von B']);
});

it('das Speicher-Event ist adressiert — kein ungescopter Window-Broadcast (MVP-046)', function () {
    // Blade-Vertrag: fängt die JS-Regression ohne Browsertreiber. Der Effekt ist sonst nur im
    // Browser sichtbar, und genau dort schaut niemand hin, bis Zutaten fehlen.
    $modulRoot = dirname((new ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2);
    $editor = file_get_contents($modulRoot . '/resources/views/livewire/recipes/ingredient-editor.blade.php');
    $sender = file_get_contents($modulRoot . '/resources/views/livewire/recipes/recipe-modal.blade.php');

    // Der Empfänger darf lauschen — er muss nur prüfen, ob das Event IHN meint.
    expect($editor)->toContain("'recipeId' in detail")
        ->and($editor)->toContain('this.$wire.recipeId')
        // Anlage-Modus (Sender hat noch kein Rezept) ist ein regulärer Zustand, keine Warnung.
        ->and($editor)->toContain('detail.recipeId === null')
        // Kein blindes Weiterreichen des Payloads mehr: das Ziel geht mit an den Server.
        ->and($editor)->toContain('this.$wire.speichern(this.payload(), Number(detail.recipeId))')
        // Und jeder Sender benennt sein Ziel.
        ->and($sender)->toContain("\$dispatch('zutaten-speichern', { recipeId:")
        ->and($editor)->toContain("\$dispatch('zutaten-speichern', { recipeId:");

    // Kein Sender darf das Event ohne Ziel feuern — genau das war der Broadcast.
    foreach (['ingredient-editor', 'recipe-modal'] as $datei) {
        $inhalt = file_get_contents($modulRoot . "/resources/views/livewire/recipes/{$datei}.blade.php");
        expect($inhalt)->not->toContain("\$dispatch('zutaten-speichern')");
    }
});
