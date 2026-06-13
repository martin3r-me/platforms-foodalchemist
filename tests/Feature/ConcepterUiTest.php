<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Bausteine\Index as BausteineIndex;
use Platform\FoodAlchemist\Livewire\Concepts\Index as ConceptsIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistBaustein;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\BausteinService;
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

it('Baustein-Browser: anlegen, Gerichte hinzufügen, speichern (Voll-Page-Render)', function () {
    Livewire::test(BausteineIndex::class)
        ->assertOk()
        ->call('neu')
        ->assertSet('selectedId', fn ($v) => $v !== null);

    expect(FoodAlchemistBaustein::count())->toBe(1);
    $id = FoodAlchemistBaustein::first()->id;

    Livewire::test(BausteineIndex::class)
        ->call('waehle', $id)
        ->call('gerichtHinzu', $this->green->id)
        ->call('gerichtHinzu', $this->sunny->id)
        ->set('form.name', 'Salad Wall')
        ->set('form.rolle', 'Vorspeise')
        ->call('speichern')
        ->assertSee('Salad Wall');

    $b = FoodAlchemistBaustein::find($id);
    expect($b->name)->toBe('Salad Wall')->and($b->rolle)->toBe('Vorspeise')
        ->and($b->gerichte()->count())->toBe(2);
});

it('Concept-Editor: Slot anlegen, mit Baustein füllen, Live-Preis im Cockpit', function () {
    $b = app(BausteinService::class)->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);
    app(BausteinService::class)->update($this->rootTeam, $b->id, ['preis_pro_person' => 4.50]);

    Livewire::test(ConceptsIndex::class)->call('neu');
    $c = FoodAlchemistConcept::echte()->first();

    $comp = Livewire::test(ConceptsIndex::class)
        ->call('waehle', $c->id)
        ->set('form.name', 'Grill-Buffet')
        ->call('speichern')
        ->set('neuerSlotRolle', 'Vorspeise')
        ->call('slotHinzu');

    $slot = $c->slots()->first();
    $comp->call('fuelleBaustein', $slot->id, $b->id)
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

it('M10p: Personenzahl → Gesamtpreis im Cockpit, Slot-Reorder über die UI', function () {
    $b = app(BausteinService::class)->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);
    app(BausteinService::class)->update($this->rootTeam, $b->id, ['preis_pro_person' => 4.50]);
    $c = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);

    $comp = Livewire::test(ConceptsIndex::class)
        ->call('waehle', $c->id)
        ->set('form.personen', 10)
        ->call('speichern')
        ->set('neuerSlotRolle', 'Vorspeise')->call('slotHinzu')
        ->set('neuerSlotRolle', 'Dessert')->call('slotHinzu');

    $slots = $c->slots()->orderBy('position')->pluck('id')->all();
    $comp->call('fuelleBaustein', $slots[0], $b->id)
        ->assertSee('45,00');                                         // 4,50 × 10 Personen

    expect((int) $c->refresh()->personen)->toBe(10);

    // Slot-Reorder: zweiten Slot nach oben
    $comp->call('slotHoch', $slots[1]);
    expect($c->slots()->orderBy('position')->pluck('id')->first())->toBe($slots[1]);
});

it('M10p: Baustein-Gericht Menge/Person setzen + ▲▼-Reorder', function () {
    $b = app(BausteinService::class)->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);

    $comp = Livewire::test(BausteineIndex::class)
        ->call('waehle', $b->id)
        ->call('gerichtHinzu', $this->green->id)
        ->call('gerichtHinzu', $this->sunny->id);

    $rows = $b->gerichte()->orderBy('position')->pluck('id')->all();
    $comp->set("mengeForm.{$rows[0]}", 120)->call('gerichtMengeSpeichern', $rows[0])
        ->call('gerichtHoch', $rows[1]);

    expect((float) $b->gerichte()->find($rows[0])->menge)->toBe(120.0)
        ->and($b->gerichte()->orderBy('position')->pluck('id')->first())->toBe($rows[1]);
});
