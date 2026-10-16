<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 §4.0 · Sichtbar ist nicht eigen. Das Kind-Team sieht die Zeilen des Root-Teams (Kette aufwärts),
 * darf sie aber nicht ändern. Vorher luden Modale und Services mit visibleToTeam und schrieben direkt —
 * die UI zeigte geerbte Rezepte nur lesend, der Server wies aber nichts ab.
 * Jeder Fall prüft die MELDUNG des Riegels (nicht nur „nichts passiert“), damit kein anderer Riegel
 * (z. B. die Bearbeitungssperre) den Test zufällig grün macht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->basis = $this->makeRecipe($this->rootTeam, 'Ragout: Rind', ['is_sales_recipe' => false, 'description' => 'Original']);
    $this->gericht = $this->makeRecipe($this->rootTeam, 'Rinderragout mit Spätzle', ['is_sales_recipe' => true, 'description' => 'Original']);
    $this->gp = $this->makeGp($this->rootTeam, 'Erbsen: TK');
    $this->kind = $this->makeUser($this->childA, 'Kind A');
});

// ── Livewire: Rezept-Modal ──────────────────────────────────────────────

it('Rezept-Modal: Kind-Team setzt keine Lineage auf ein geerbtes Rezept', function (string $methode) {
    $this->actingAs($this->kind);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->basis->id)
        ->set('form.description', 'Überschrieben')
        ->call($methode)
        ->assertSet('fehler', 'Geerbt: das Rezept kann nur das Besitzer-Team ändern.');

    $r = $this->basis->fresh();
    expect($r->description)->toBe('Original')
        ->and($r->description_source)->toBeNull()
        ->and($r->preparation_source)->toBeNull();
})->with(['manual_beschreibung', 'clear_beschreibung', 'manual_zubereitung', 'clear_zubereitung', 'clear_kategorie']);

it('Rezept-Modal: Regeneration und Dichteklasse eines geerbten Rezepts bleiben unangetastet', function () {
    $this->actingAs($this->kind);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->basis->id)
        ->call('tabLaden', 'regeneration')
        ->set('regenForm.temp_c', '160')
        ->set('dichteklasse', 'fest')
        ->call('regenerationSpeichern')
        ->assertSet('fehler', 'Geerbt: das Rezept kann nur das Besitzer-Team ändern.');

    expect(DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $this->basis->id)->count())->toBe(0)
        ->and($this->basis->fresh()->dichteklasse)->toBeNull();
});

it('Rezept-Modal: Besitzer-Team speichert die Dichteklasse weiter (jetzt über das Model)', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root'));

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->basis->id)
        ->call('tabLaden', 'regeneration')
        ->set('dichteklasse', 'fest')
        ->call('regenerationSpeichern')
        ->assertSet('fehler', null);

    expect($this->basis->fresh()->dichteklasse)->toBe('fest')
        ->and($this->basis->fresh()->dichteklasse_source)->toBe('manual');
});

it('Rezept-Modal: Überarbeitung wird auf einem geerbten Rezept nicht übernommen', function () {
    $this->actingAs($this->kind);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->basis->id)
        ->set('ueberarbeitung', ['werte' => ['description' => 'KI-Text'], 'confidence' => 0.9, 'match_vorschau' => []])
        ->call('ueberarbeitungUebernehmen')
        ->assertSet('fehler', 'Geerbt: das Rezept kann nur das Besitzer-Team ändern.');

    expect($this->basis->fresh()->description)->toBe('Original');
});

// ── Livewire: VK-Modal ──────────────────────────────────────────────────

it('VK-Modal: Überarbeitung wird auf einem geerbten Gericht nicht übernommen', function () {
    $this->actingAs($this->kind);

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->gericht->id)
        ->set('ueberarbeitung', ['werte' => ['description' => 'KI-Text'], 'confidence' => 0.9, 'match_vorschau' => []])
        ->call('ueberarbeitungUebernehmen')
        ->assertSet('fehler', 'Geerbt: das Gericht kann nur das Besitzer-Team ändern.');

    expect($this->gericht->fresh()->description)->toBe('Original');
});

// ── Livewire: GP-Modal ──────────────────────────────────────────────────

it('GP-Modal: Kind-Team setzt keine Zustands-/Tag-Lineage auf ein geerbtes GP', function (string $methode) {
    $this->actingAs($this->kind);
    $vorher = $this->gp->fresh()->only(['condition', 'condition_source', 'tag_source']);

    Livewire::test(GpModal::class)
        ->call('oeffnen', $this->gp->id)
        ->call($methode)
        ->assertSet('fehler', 'Geerbtes Katalog-GP — Pflege nur durchs Besitzer-Team (D1).');

    expect($this->gp->fresh()->only(['condition', 'condition_source', 'tag_source']))->toBe($vorher);
})->with(['clear_zustand', 'clear_tags', 'manual_tags']);

// ── Services (zweite Linie, auch für künftige Aufrufer) ─────────────────

it('Service: Regeneration, Behälter und Kunden-Namen auf geerbtem Rezept werfen GeerbtNurLesen', function (Closure $aufruf) {
    expect(fn () => $aufruf(app(SalesRecipeService::class), $this->childA, $this->basis->id, $this->gericht->id))
        ->toThrow(GeerbtNurLesenException::class);
})->with([
    'upsertRegeneration' => [fn ($s, $t, $b) => $s->upsertRegeneration($t, $b, ['temp_c' => 160])],
    'deleteRegeneration' => [fn ($s, $t, $b) => $s->deleteRegeneration($t, $b, 1)],
    'reorderRegenerations' => [fn ($s, $t, $b) => $s->reorderRegenerations($t, $b, [1])],
    'upsertContainer' => [fn ($s, $t, $b) => $s->upsertContainer($t, $b, 'abfuellen', ['container_vocab_id' => 1])],
    'deleteContainer' => [fn ($s, $t, $b) => $s->deleteContainer($t, $b, 'abfuellen')],
    'addCustomerName' => [fn ($s, $t, $b, $g) => $s->addCustomerName($t, $g, 'Kunde X', 'Marketing-Name')],
    'deleteCustomerName' => [fn ($s, $t, $b, $g) => $s->deleteCustomerName($t, $g, 1)],
]);

it('Service: Darreichung an geerbtem Gericht anlegen bzw. Standard erzeugen wirft GeerbtNurLesen', function () {
    $svc = app(DarreichungService::class);

    expect(fn () => $svc->anlegen($this->childA, $this->gericht->id, 1))->toThrow(GeerbtNurLesenException::class)
        ->and(fn () => $svc->ensureStandard($this->childA, $this->gericht->id))->toThrow(GeerbtNurLesenException::class);
});

it('Service: Rezeptbild eines geerbten Rezepts lässt sich nicht entfernen', function () {
    expect(fn () => app(RecipeService::class)->clearDishImage($this->childA, $this->gericht->id))
        ->toThrow(GeerbtNurLesenException::class);
});

it('Service: Tausch-Sperre einer Zutat im geerbten Rezept wirft GeerbtNurLesen', function () {
    $zutat = $this->makeIngredient($this->basis, 'Rinderschulter');

    expect(fn () => app(ComponentEquivalentService::class)->setSwapLocked($this->childA, $zutat->id, true))
        ->toThrow(GeerbtNurLesenException::class);
    expect((bool) $zutat->fresh()->swap_locked)->toBeFalse();
});

it('Service: Status eines geerbten Konzepts lässt sich nicht setzen', function () {
    $concept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Grill-Buffet', 'status' => 'draft']);

    expect(fn () => app(ConceptService::class)->setStatus($this->childA, $concept->id, 'active'))
        ->toThrow(GeerbtNurLesenException::class);
    expect($concept->fresh()->status)->toBe('draft');
});

it('Service: Besitzer-Team setzt den Konzept-Status weiter', function () {
    $concept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Grill-Buffet', 'status' => 'draft']);

    app(ConceptService::class)->setStatus($this->rootTeam, $concept->id, 'active');

    expect($concept->fresh()->status)->toBe('active');
});
