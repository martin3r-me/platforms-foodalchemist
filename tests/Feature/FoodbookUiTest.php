<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M11-03: Livewire-Smoke des Foodbook-Editors — anlegen, Kapitel, Concept einfügen,
 * Pax-Gesamtpreis im Cockpit. Voll-Page-Render gegen platform::layouts.app.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    // Concept „Grill-Buffet" (4,50 €/P) als einfügbarer Inhalt
    $paket = app(PaketService::class)->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise', 'price_mode' => 'manuell']);
    app(PaketService::class)->update($this->rootTeam, $paket->id, ['price_per_person' => 4.50]);
    $this->concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = app(ConceptService::class)->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    app(ConceptService::class)->fillSlot($this->rootTeam, $slot->id, ['package_id' => $paket->id]);
});

it('Foodbook-Editor: anlegen, Kapitel, Concept einfügen, €/Person im Cockpit', function () {
    Livewire::test(FoodbooksIndex::class)->assertOk()->call('neu');
    $fb = FoodAlchemistFoodbook::first();
    expect($fb)->not->toBeNull();

    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $fb->id)
        ->set('form.label', 'Angebot Adler')
        ->set('form.customer', 'Hotel Adler')
        ->set('form.personen', 100)
        ->call('speichern')
        ->set('neuesKapitelTitel', 'Menü')
        ->call('kapitelNeu');

    $kap = $fb->kapitel()->first();
    expect($kap)->not->toBeNull();

    // Concept einfügen (KEIN Gericht-Picker) → Cockpit zeigt €/Person: das Foodbook ist
    // seit dem Angebote-Umbau person-unabhängiges Portfolio (Pax × Gesamt lebt im ANGEBOT)
    $comp->call('conceptHinzu', $this->concept->id)
        ->assertSee('Grill-Buffet')
        ->assertSee('4,50');

    expect($kap->blocks()->where('type', 'concept_ref')->count())->toBe(1)
        ->and((int) $fb->refresh()->personen)->toBe(100);             // Pax bleibt gespeichert (Staffel/Angebot)
});

it('Foodbook-Editor: kapitelNeu(parentId) legt ein Unterkapitel unter dem Eltern-Kapitel an', function () {
    Livewire::test(FoodbooksIndex::class)->call('neu');
    $fb = FoodAlchemistFoodbook::first();
    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $fb->id)
        ->set('neuesKapitelTitel', 'Hauptteil')->call('kapitelNeu');       // Top-Kapitel (parentId = null)

    $top = $fb->kapitel()->whereNull('parent_id')->first();
    expect($top)->not->toBeNull();

    $comp->call('kapitelNeu', $top->id);                                   // Unterkapitel unter Top
    $sub = $fb->kapitel()->where('parent_id', $top->id)->first();
    expect($sub)->not->toBeNull()
        ->and($sub->parent_id)->toBe($top->id);                            // nicht flach → echtes Unterkapitel
});

it('Foodbook-Editor: Header-Preis-Block (person) erscheint mit €/Person im Cockpit', function () {
    Livewire::test(FoodbooksIndex::class)->call('neu');
    $fb = FoodAlchemistFoodbook::first();
    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $fb->id)
        ->set('form.personen', 50)->call('speichern')
        ->set('neuesKapitelTitel', 'Pakete')->call('kapitelNeu');

    $kap = $fb->kapitel()->first();
    $comp->call('presetHinzu', 'header_frei_preis', 'format.menue_paket', 'Menü-Paket', 'person', true);
    $block = $kap->blocks()->where('type', 'header_frei_preis')->first();
    expect($block)->not->toBeNull();

    // Preis setzen via Inline-Editor
    $comp->call('blockBearbeiten', $block->id)
        ->set('blockForm.price_value', 38)
        ->set('blockForm.price_basis', 'person')
        ->call('blockSpeichern')
        ->assertSee('38,00');                                         // €/Person — Pax-Gesamt lebt im Angebot

    expect((float) $block->refresh()->price_value)->toBe(38.0);
});
