<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53), Restliste — dieselbe Behandlung wie gps/detail-panel's
 * laVorschlaege ({@see \tests\Feature\KiFeedbackGpTest}): "KI-Vorschlag"
 * heisst der Knopf, ruft aber MatchService v1 (EAN/Art.-Nr + Fuzzy) —
 * deterministisch, kein LLM. Trotzdem sofortige Klick-Rückmeldung, variant
 * ghostXs statt ai (Tönung signalisiert sonst fälschlich einen echten
 * KI-Call).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Tomatenmark 3-fach 800g', 'qty' => 0.8, 'unit_code' => 'kg',
    ]);
});

it('LA-Modal: „KI-Vorschlag" (kiGpVorschlag) hat data-ki-action + wire:target', function () {
    $html = Livewire::test(ItemModal::class)->call('oeffnen', $this->la->id)->html();

    expect($html)->toContain('data-ki-action="kiGpVorschlag"')
        ->toContain('wire:target="kiGpVorschlag"');
});
