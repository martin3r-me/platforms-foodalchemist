<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * fa-pass (Dominique 2026-10-05): der Lieferantenartikel-Editor hat EIN Speichern für den ganzen
 * Artikel (vorher eigene Knöpfe für Allergene/Zusatzstoffe/Nährwerte), und die EAN ist bearbeitbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Senf mittelscharf 1 kg', 'qty' => 1, 'unit_code' => 'kg',
    ]);
});

it('speichert mit EINEM Klick auch geänderte Allergene', function () {
    $c = Livewire::test(ItemModal::class)->call('oeffnen', $this->la->id);
    $c->set('allergene.mustard', 'enthalten')->call('speichern')->assertSet('fehler', null);

    $werte = app(SupplierItemService::class)->getAllergens($this->la->fresh());
    expect($werte['mustard'])->toBe('enthalten');
});

it('speichert eine gültige EAN und lehnt eine ungültige ab', function () {
    Livewire::test(ItemModal::class)->call('oeffnen', $this->la->id)
        ->set('verpackung.ean_packaging', '4006040123456')->call('speichern')->assertHasNoErrors();
    expect($this->la->fresh()->ean_packaging)->toBe('4006040123456');

    Livewire::test(ItemModal::class)->call('oeffnen', $this->la->id)
        ->set('verpackung.ean_ordering', '40 06')->call('speichern')->assertHasErrors(['verpackung.ean_ordering']);
    expect($this->la->fresh()->ean_ordering)->toBeNull();
});

it('hat keine eigenen Speichern-Knöpfe mehr für Allergene, Zusatzstoffe und Nährwerte', function () {
    $html = Livewire::test(ItemModal::class)->call('oeffnen', $this->la->id)->html();
    expect($html)->not->toContain('wire:click="allergeneSpeichern"')
        ->not->toContain('wire:click="deklarationenSpeichern"')
        ->not->toContain('wire:click="naehrwerteSpeichern"')
        ->toContain('data-la-speichern');
});
