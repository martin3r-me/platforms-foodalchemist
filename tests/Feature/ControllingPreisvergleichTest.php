<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Preisvergleich as Cockpit;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einkauf E3 (DoD): Die „in Bestellschiene übernehmen"-Aktion des Preisvergleichs legt den
 * günstigsten Lieferantenartikel als Position in den Draft seines Lieferanten (1 Gebinde).
 *
 * Spec 32: das Panel heißt seit 2026-08-02 `Controlling\Panels\Preisvergleich` (vorher
 * `Einkauf\Cockpit`) — die Aktion ist unverändert, sie steht nur an ihrem Befund.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);

    $this->supplier = FoodAlchemistSupplier::create([
        'team_id' => $this->childA->id, 'name' => 'Chefs Culinar', 'status' => 'aktiv',
    ]);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->childA->id, 'supplier_id' => $this->supplier->id,
        'designation' => 'Sahne konserviert 30%', 'unit_code' => 'kg', 'qty' => 5,
    ]);
});

it('übernimmt den günstigsten LA in die Bestellschiene des Lieferanten', function () {
    Livewire::test(Cockpit::class)
        ->call('uebernehmen', $this->la->id)
        ->assertSet('fehler', null);

    $draft = FoodAlchemistOrder::where('team_id', $this->childA->id)
        ->where('supplier_id', $this->supplier->id)
        ->where('status', 'draft')->first();

    expect($draft)->not->toBeNull()
        ->and(FoodAlchemistOrderLine::where('order_id', $draft->id)
            ->where('supplier_item_id', $this->la->id)->exists())->toBeTrue();
});

it('meldet einen Fehler bei unbekanntem Lieferantenartikel', function () {
    Livewire::test(Cockpit::class)
        ->call('uebernehmen', 999999)
        ->assertSet('hinweis', null);

    expect(FoodAlchemistOrder::where('team_id', $this->childA->id)->count())->toBe(0);
});
