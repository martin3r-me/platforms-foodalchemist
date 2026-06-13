<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Pakete\Index as PaketeIndex;
use Platform\FoodAlchemist\Livewire\Concepts\Index as ConceptsIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10-02/03/05: Livewire-Smoke der Concepter-UI — anlegen, befüllen, Live-Preis,
 * Vorlage-Fork; Voll-Page-Render gegen platform::layouts.app.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $mk = fn (string $k, string $n, float $vk) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $k, 'name' => $n,
        'status' => 'approved', 'ist_verkaufsrezept' => true, 'vk_netto' => $vk, 'ek_total_eur' => $vk * 0.3,
    ]);
    $this->green = $mk('g', 'Salat: Green Power', 2.00);
    $this->sunny = $mk('s', 'Salat: Sunny Kick', 3.00);
});

it('Paket-Browser: anlegen, Gerichte hinzufügen, speichern (Voll-Page-Render)', function () {
    Livewire::test(PaketeIndex::class)
        ->assertOk()
        ->call('neu')
        ->assertSet('selectedId', fn ($v) => $v !== null);

    expect(FoodAlchemistPaket::count())->toBe(1);
    $id = FoodAlchemistPaket::first()->id;

    Livewire::test(PaketeIndex::class)
        ->call('waehle', $id)
        ->call('gerichtHinzu', $this->green->id)
        ->call('gerichtHinzu', $this->sunny->id)
        ->set('form.name', 'Salad Wall')
        ->set('form.rolle', 'Vorspeise')
        ->call('speichern')
        ->assertSee('Salad Wall');

    $b = FoodAlchemistPaket::find($id);
    expect($b->name)->toBe('Salad Wall')->and($b->rolle)->toBe('Vorspeise')
        ->and($b->gerichte()->count())->toBe(2);
});

it('Concept-Editor: Slot anlegen, mit Paket füllen, Live-Preis im Cockpit', function () {
    $b = app(PaketService::class)->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);
    app(PaketService::class)->update($this->rootTeam, $b->id, ['preis_pro_person' => 4.50]);

    Livewire::test(ConceptsIndex::class)->call('neu');
    $c = FoodAlchemistConcept::echte()->first();

    $comp = Livewire::test(ConceptsIndex::class)
        ->call('waehle', $c->id)
        ->set('form.name', 'Grill-Buffet')
        ->call('speichern')
        ->set('neuerSlotRolle', 'Vorspeise')
        ->call('slotHinzu');

    $slot = $c->slots()->first();
    $comp->call('fuellePaket', $slot->id, $b->id)
        ->assertSee('Salad Wall')
        ->assertSee('4,50');

    expect((float) $c->refresh()->preis_pro_person_cache)->toBe(4.50);
});

it('Vorlage-Fork über die UI erzeugt ein eigenständiges Concept (M10-05)', function () {
    $vorlage = app(ConceptService::class)->create($this->rootTeam, ['name' => '3-Gang', 'is_vorlage' => true]);
    app(ConceptService::class)->addSlot($this->rootTeam, $vorlage->id, ['rolle' => 'Vorspeise']);

    Livewire::test(ConceptsIndex::class)
        ->set('showVorlagen', true)
        ->call('ausVorlage', $vorlage->id)
        ->assertSet('showVorlagen', false)
        ->assertSet('selectedId', fn ($v) => $v !== null && $v !== $vorlage->id);

    expect(FoodAlchemistConcept::echte()->where('name', '3-Gang – Kopie')->exists())->toBeTrue()
        ->and(FoodAlchemistConcept::echte()->first()->slots()->count())->toBe(1);
});

it('M10c: Concept-Editor zeigt €/Person (kein Pax), Slot-Reorder über die UI', function () {
    $b = app(PaketService::class)->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);
    app(PaketService::class)->update($this->rootTeam, $b->id, ['preis_pro_person' => 4.50]);
    $c = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);

    $comp = Livewire::test(ConceptsIndex::class)
        ->call('waehle', $c->id)
        ->set('neuerSlotRolle', 'Vorspeise')->call('slotHinzu')
        ->set('neuerSlotRolle', 'Dessert')->call('slotHinzu');

    $slots = $c->slots()->orderBy('position')->pluck('id')->all();
    $comp->call('fuellePaket', $slots[0], $b->id)
        ->assertSee('4,50')                                           // €/Person, kein Gesamtpreis
        ->assertSee('erst im Foodbook');

    // Slot-Reorder: zweiten Slot nach oben
    $comp->call('slotHoch', $slots[1]);
    expect($c->slots()->orderBy('position')->pluck('id')->first())->toBe($slots[1]);
});

it('M10c-B: Kategorie anlegen + Concept filtern (UI)', function () {
    Livewire::test(ConceptsIndex::class)->set('neueKategorie', 'Sommer')->call('kategorieNeu');
    $kat = FoodAlchemistConceptCategory::where('team_id', $this->rootTeam->id)->first();
    expect($kat?->name)->toBe('Sommer');

    $drin = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    app(ConceptService::class)->update($this->rootTeam, $drin->id, ['category_id' => $kat->id]);
    app(ConceptService::class)->create($this->rootTeam, ['name' => 'Anderes Concept']);

    Livewire::test(ConceptsIndex::class)
        ->set('categoryFilter', (string) $kat->id)
        ->assertSee('Grill-Buffet')
        ->assertDontSee('Anderes Concept');
});

it('M13: Zielpreis-Modus — Vorschlag berechnen + übernehmen (UI)', function () {
    $v4 = app(PaketService::class)->create($this->rootTeam, ['name' => 'Vorspeise A', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $v6 = app(PaketService::class)->create($this->rootTeam, ['name' => 'Vorspeise B', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    app(PaketService::class)->update($this->rootTeam, $v4->id, ['preis_pro_person' => 4.00]);
    app(PaketService::class)->update($this->rootTeam, $v6->id, ['preis_pro_person' => 6.00]);
    $c = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = app(ConceptService::class)->addSlot($this->rootTeam, $c->id, ['rolle' => 'Vorspeise']);
    app(ConceptService::class)->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $v4->id]);

    Livewire::test(ConceptsIndex::class)
        ->call('waehle', $c->id)
        ->call('zielpreisToggle')
        ->set('zielPreis', '6')
        ->call('zielpreisBerechnen')
        ->assertSet('zielVorschlag.preis', 6.00)
        ->assertSet('zielVorschlag.aenderungen', 1)
        ->call('zielpreisUebernehmen');

    expect((float) $c->refresh()->preis_pro_person_cache)->toBe(6.00)
        ->and($slot->refresh()->paket_id)->toBe($v6->id);
});

it('M10p: Paket-Gericht Menge/Person setzen + ▲▼-Reorder', function () {
    $b = app(PaketService::class)->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);

    $comp = Livewire::test(PaketeIndex::class)
        ->call('waehle', $b->id)
        ->call('gerichtHinzu', $this->green->id)
        ->call('gerichtHinzu', $this->sunny->id);

    $rows = $b->gerichte()->orderBy('position')->pluck('id')->all();
    $comp->set("mengeForm.{$rows[0]}", 120)->call('gerichtMengeSpeichern', $rows[0])
        ->call('gerichtHoch', $rows[1]);

    expect((float) $b->gerichte()->find($rows[0])->menge)->toBe(120.0)
        ->and($b->gerichte()->orderBy('position')->pluck('id')->first())->toBe($rows[1]);
});
