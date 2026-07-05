<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Browser;
use Platform\FoodAlchemist\Livewire\Concepter\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10R-2: vereinheitlichter Concepter-Browser (Concepts | Pakete in einem Screen)
 * + kontext-adaptives Detail-Panel. Voll-Page-Render + Tab-Wechsel + Filter + Auswahl.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->pakete = app(PaketService::class);
    $this->concepts = app(ConceptService::class);

    $this->green = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'Salat: Green Power',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 2.00, 'ek_total_eur' => 0.60,
        'sales_quantity_per_unit_g' => 250, 'nutri_kcal_per_100g' => 200, 'nutri_confidence' => 'high',
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'allergens_confidence' => 'high',
    ]);

    // Paket „Salad Wall" (Klasse Buffet, manueller Preis) + Concept „Grill-Buffet".
    $this->paket = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise', 'class' => 'Buffet']);
    $this->pakete->update($this->rootTeam, $this->paket->id, ['price_per_person' => 4.50]);
    $this->pakete->syncGerichte($this->rootTeam, $this->paket->id, [['sales_recipe_id' => $this->green->id]]);

    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet', 'class' => 'Buffet']);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['package_id' => $this->paket->id]);
});

it('Browser rendert (Voll-Page) und zeigt Concepts im Default-Tab', function () {
    Livewire::test(Browser::class)
        ->assertOk()
        ->assertSet('tab', 'concepts')
        ->assertSee('Grill-Buffet');
});

it('Tab-Wechsel zu Pakete zeigt Pakete und leert die Auswahl', function () {
    Livewire::test(Browser::class)
        ->call('waehle', $this->concept->id)
        ->assertSet('selectedId', $this->concept->id)
        ->call('wechselTab', 'pakete')
        ->assertSet('tab', 'pakete')
        ->assertSet('selectedId', null)
        ->assertSee('Salad Wall')
        ->assertDispatched('concepter-selected');
});

it('Klasse-Filter grenzt die Liste ein', function () {
    $this->concepts->create($this->rootTeam, ['name' => 'Fingerfood-Linie', 'class' => 'Flying']);

    Livewire::test(Browser::class)
        ->call('waehleKlasse', 'Buffet')
        ->assertSet('class', 'Buffet')
        ->assertSee('Grill-Buffet')
        ->assertDontSee('Fingerfood-Linie');
});

it('Auswahl dispatcht concepter-selected mit Typ + ID', function () {
    Livewire::test(Browser::class)
        ->call('waehle', $this->concept->id)
        ->assertDispatched('concepter-selected', type: 'concepts', id: $this->concept->id);
});

it('„Neu" legt im Pakete-Tab ein Paket an und wählt es aus', function () {
    $vorher = FoodAlchemistPaket::count();

    Livewire::test(Browser::class)
        ->set('tab', 'pakete')
        ->call('neu')
        ->assertSet('selectedId', fn ($v) => $v !== null);

    expect(FoodAlchemistPaket::count())->toBe($vorher + 1);
});

it('DetailPanel zeigt Concept-KPIs (€/Person) + Aufbau', function () {
    Livewire::test(DetailPanel::class)
        ->call('zeige', 'concepts', $this->concept->id)
        ->assertSee('€/Person')
        ->assertSee('4,50')
        ->assertSee('Salad Wall');                                   // Aufbau-Zeile (Paket)
});

it('DetailPanel zeigt Paket-Nährwerte + Allergen-Rollup', function () {
    Livewire::test(DetailPanel::class)
        ->call('zeige', 'pakete', $this->paket->id)
        ->assertSee('Nährwerte')
        ->assertSee('vegan');                                        // Green Power ist vegan (einziges Gericht)
});

it('DetailPanel dupliziert ein Concept (C-13)', function () {
    Livewire::test(DetailPanel::class)
        ->call('zeige', 'concepts', $this->concept->id)
        ->call('dupliziere')
        ->assertDispatched('concepter-gespeichert');

    expect(FoodAlchemistConcept::where('name', 'Grill-Buffet (Kopie)')->exists())->toBeTrue();
});

it('DetailPanel zeigt die Menü-Karte (Konsumenten-Sicht, C-10)', function () {
    Livewire::test(DetailPanel::class)
        ->call('zeige', 'concepts', $this->concept->id)
        ->assertSee('Menü-Karte');
});

it('DetailPanel forkt eine Vorlage und öffnet den Fork (M10R-4)', function () {
    $v = $this->concepts->create($this->rootTeam, ['name' => '3-Gang', 'is_template' => true]);
    $this->concepts->addSlot($this->rootTeam, $v->id, ['role' => 'Vorspeise']);

    Livewire::test(DetailPanel::class)
        ->call('zeige', 'concepts', $v->id)
        ->call('ausVorlage')
        ->assertDispatched('concepter-editor.oeffnen');

    expect(FoodAlchemistConcept::echte()->where('name', '3-Gang – Kopie')->exists())->toBeTrue();
});

it('DetailPanel löscht und meldet concepter-geloescht', function () {
    Livewire::test(DetailPanel::class)
        ->call('zeige', 'pakete', $this->paket->id)
        ->call('loeschen')
        ->assertSet('selectedId', null)
        ->assertDispatched('concepter-geloescht');

    expect(FoodAlchemistPaket::find($this->paket->id))->toBeNull();   // soft-deleted
});
