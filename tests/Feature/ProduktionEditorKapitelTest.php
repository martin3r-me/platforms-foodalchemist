<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Produktion\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 20 P2 — Produktions-Editor v2: 4-Typen-Picker (inkl. Foodbook-Kapitel) und
 * Ziel-Edit. Kapitel wird über PlanungsblattService::kapitelZiele() in EINGEFRORENE
 * Einzel-Ziele expandiert (V2 „kein Live-Bezug"), spiegelt production_orders.ADD_TARGET.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $mkVk = function (string $key, string $name) {
        return FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
            'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 3.0, 'sales_unit_count' => 10,
        ]);
    };
    $this->kuchen = $mkVk('kuchen', 'DES: Kuchen');
    $this->torte = $mkVk('torte', 'DES: Torte');
    $this->suppe = $mkVk('suppe', 'VOR: Suppe');
    $this->basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Kalbsfond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0,
    ]);

    $fb = app(FoodbookService::class);
    $this->book = $fb->create($this->rootTeam, ['label' => 'Sommerfest', 'personen' => 25]);
    $this->kap = $fb->addKapitel($this->rootTeam, $this->book->id, ['title' => 'Dessert']);
    // Wahl-Gruppe Kuchen|Torte + ein festes Ziel Suppe (kein Live-Bezug).
    $a = $fb->addBlock($this->rootTeam, $this->kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->kuchen->id]);
    $b = $fb->addBlock($this->rootTeam, $this->kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->torte->id]);
    $fb->addBlock($this->rootTeam, $this->kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->suppe->id]);
    $this->gid = $fb->nextVariantGroupId($this->rootTeam, $this->kap->id);
    $fb->setVariantGroup($this->rootTeam, [$a->id, $b->id], $this->gid);
});

it('P2: Foodbook-Wahl belegt Pax aus foodbook.personen vor und lädt den Kapitel-Baum', function () {
    Livewire::test(Editor::class)
        ->call('oeffnenNeu')
        ->set('zielTyp', 'kapitel')
        ->set('auswahlFoodbookId', $this->book->id)
        ->assertSet('auswahlPersonen', 25)
        ->assertViewHas('kapitelBaum', fn ($baum) => collect($baum)->contains(fn ($k) => $k['title'] === 'Dessert'));
});

it('P2: Kapitel-Ziel expandiert in eingefrorene Einzel-Ziele (Default = erste Variante)', function () {
    $c = Livewire::test(Editor::class)
        ->call('oeffnenNeu')
        ->set('zielTyp', 'kapitel')
        ->set('auswahlFoodbookId', $this->book->id)
        ->set('auswahlChapterId', $this->kap->id)
        ->set('auswahlPersonen', 25)
        ->call('zielHinzufuegen');

    $targets = $c->get('targets');
    // Default-Variante Kuchen + festes Ziel Suppe → 2 Einzel-Ziele, Torte fällt raus.
    expect($targets)->toHaveCount(2);
    $recipeIds = collect($targets)->pluck('recipe_id')->all();
    expect($recipeIds)->toContain($this->kuchen->id)
        ->and($recipeIds)->toContain($this->suppe->id)
        ->and($recipeIds)->not->toContain($this->torte->id);
    // Eingefroren: source_ref-Suffix „:c<idx>", KEIN chapter_id im Ziel, Label mit Kapitel-Präfix.
    expect($targets[0]['source_ref'])->toContain(':c0')
        ->and($targets[0])->not->toHaveKey('chapter_id')
        ->and($targets[0]['label'])->toContain('Dessert ›')
        ->and($targets[0]['portions'])->toBe(25.0); // 1 Portion/Person Default
});

it('P2: gewählte Variante ersetzt den Default (Torte statt Kuchen)', function () {
    $c = Livewire::test(Editor::class)
        ->call('oeffnenNeu')
        ->set('zielTyp', 'kapitel')
        ->set('auswahlFoodbookId', $this->book->id)
        ->set('auswahlChapterId', $this->kap->id)
        ->set('auswahlPersonen', 25)
        ->set('variantChoices', [$this->gid => $this->torte->id])
        ->call('zielHinzufuegen');

    $recipeIds = collect($c->get('targets'))->pluck('recipe_id')->all();
    expect($recipeIds)->toContain($this->torte->id)
        ->and($recipeIds)->not->toContain($this->kuchen->id);
});

it('P2: nach dem Hinzufügen ist die Kapitel-Wahl zurückgesetzt (mehrere Kapitel möglich)', function () {
    Livewire::test(Editor::class)
        ->call('oeffnenNeu')
        ->set('zielTyp', 'kapitel')
        ->set('auswahlFoodbookId', $this->book->id)
        ->set('auswahlChapterId', $this->kap->id)
        ->set('auswahlPersonen', 25)
        ->call('zielHinzufuegen')
        ->assertSet('auswahlChapterId', null)
        ->assertSet('variantChoices', []);
});

it('P2: Speichern persistiert die expandierten Kapitel-Ziele am Auftrag', function () {
    $c = Livewire::test(Editor::class)
        ->call('oeffnenNeu')
        ->set('name', 'Sommerfest Vormittag')
        ->set('zielTyp', 'kapitel')
        ->set('auswahlFoodbookId', $this->book->id)
        ->set('auswahlChapterId', $this->kap->id)
        ->set('auswahlPersonen', 25)
        ->call('zielHinzufuegen')
        ->call('speichern')
        ->assertSet('fehler', null);

    $orderId = \Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder::query()
        ->where('team_id', $this->rootTeam->id)->value('id');
    expect($orderId)->not->toBeNull();
    $targets = \Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder::find($orderId)->targets;
    expect($targets)->toHaveCount(2);
});

it('P2: Edit lädt ein Einzel-Ziel zurück in den Picker und nimmt es aus der Liste', function () {
    $c = Livewire::test(Editor::class)
        ->call('oeffnenNeu')
        ->set('zielTyp', 'basisrezept')
        ->set('basisEinheit', 'kg')
        ->set('auswahlRecipeId', $this->basis->id)
        ->set('auswahlMenge', 5)
        ->call('zielHinzufuegen');

    $ref = $c->get('targets')[0]['source_ref'];
    $c->call('zielBearbeiten', $ref)
        ->assertSet('zielTyp', 'basisrezept')
        ->assertSet('basisEinheit', 'kg')
        ->assertSet('auswahlRecipeId', $this->basis->id)
        ->assertSet('auswahlMenge', 5.0);
    expect($c->get('targets'))->toHaveCount(0);
});

it('P2: eingefrorene Kapitel-Teil-Ziele sind nicht editierbar (nur entfernbar)', function () {
    $c = Livewire::test(Editor::class)
        ->call('oeffnenNeu')
        ->set('zielTyp', 'kapitel')
        ->set('auswahlFoodbookId', $this->book->id)
        ->set('auswahlChapterId', $this->kap->id)
        ->set('auswahlPersonen', 25)
        ->call('zielHinzufuegen');

    $ref = $c->get('targets')[0]['source_ref']; // enthält „:c0"
    $c->call('zielBearbeiten', $ref);
    // Edit ist ein No-op für eingefrorene Teil-Ziele: Liste bleibt vollständig.
    expect($c->get('targets'))->toHaveCount(2);
});
