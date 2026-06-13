<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10R-3: Voll-Editor-Modal (kontext-adaptiv Concept | Paket) — öffnen, Kopf
 * speichern, Positionen/Gerichte editieren, Tabs.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->pakete = app(PaketService::class);
    $this->concepts = app(ConceptService::class);

    $this->green = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'Green Power',
        'status' => 'approved', 'ist_verkaufsrezept' => true, 'vk_netto' => 2.00, 'ek_total_eur' => 0.60,
    ]);
    $this->paket = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);
    $this->pakete->update($this->rootTeam, $this->paket->id, ['preis_pro_person' => 4.50]);
    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
});

it('öffnet ein Concept, lädt den Kopf + meldet modal.open', function () {
    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->assertSet('type', 'concepts')
        ->assertSet('id', $this->concept->id)
        ->assertSet('form.name', 'Grill-Buffet')
        ->assertSet('tab', 'aufbau')
        ->assertDispatched('modal.open');
});

it('speichert die Kopf-Felder (Konsumentenname, Klasse, Zielpreis)', function () {
    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->set('form.konsumenten_name', 'Sommerbuffet')
        ->set('form.klasse', 'Buffet')
        ->set('form.zielpreis_pro_person', 36.00)
        ->call('speichern')
        ->assertDispatched('concepter-gespeichert');

    $c = FoodAlchemistConcept::find($this->concept->id);
    expect($c->konsumenten_name)->toBe('Sommerbuffet')
        ->and($c->klasse)->toBe('Buffet')
        ->and((float) $c->zielpreis_pro_person)->toBe(36.0);
});

it('Aufbau: Position anlegen + mit Paket füllen', function () {
    $comp = Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->set('neuerSlotRolle', 'Vorspeise')
        ->call('slotHinzu');

    $slot = $this->concept->slots()->first();
    expect($slot)->not->toBeNull();

    $comp->call('fuellePaket', $slot->id, $this->paket->id);
    expect($slot->refresh()->paket_id)->toBe($this->paket->id);
});

it('öffnet ein Paket und schnürt Gerichte (hinzufügen/entfernen)', function () {
    $comp = Livewire::test(Editor::class)
        ->call('oeffnen', 'pakete', $this->paket->id)
        ->assertSet('form.name', 'Salad Wall')
        ->call('gerichtHinzu', $this->green->id);

    expect($this->paket->gerichte()->count())->toBe(1);

    $comp->call('gerichtRaus', $this->green->id);
    expect($this->paket->gerichte()->count())->toBe(0);
});

it('Tab-Wechsel funktioniert', function () {
    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->call('setTab', 'kalkulation')
        ->assertSet('tab', 'kalkulation')
        ->call('setTab', 'unsinn')
        ->assertSet('tab', 'kalkulation');                            // ungültiger Tab ignoriert
});

it('M10R-4: inline neues Paket im Slot schnüren öffnet das Paket im selben Modal', function () {
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['rolle' => 'Vorspeise']);
    $vorher = FoodAlchemistPaket::count();

    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->call('neuesPaketImSlot', $slot->id)
        ->assertSet('type', 'pakete')                                 // Editor zeigt jetzt das neue Paket
        ->assertSet('id', fn ($v) => $v !== null);

    expect(FoodAlchemistPaket::count())->toBe($vorher + 1)
        ->and($slot->refresh()->paket_id)->not->toBeNull();
});

it('C-02: Pflicht/optional-Toggle je Slot speichert is_pflicht', function () {
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['rolle' => 'Vorspeise']);

    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->set("slotForm.{$slot->id}.is_pflicht", false)
        ->call('slotSpeichern', $slot->id);

    expect($slot->refresh()->is_pflicht)->toBeFalse();
});

it('M10R-4: Als Vorlage speichern aus dem Editor', function () {
    $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['rolle' => 'Vorspeise']);

    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->call('alsVorlage')
        ->assertDispatched('concepter-vorlage-gespeichert');

    expect(FoodAlchemistConcept::vorlagen()->count())->toBe(1);
});
