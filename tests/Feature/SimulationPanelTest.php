<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Kalkulation\Simulation;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R2.2 — UI-Panel der Was-wäre-wenn-Simulation (Kalkulations-Werkstatt).
 * Spiegelt SimulationService/SimulationTest über das Livewire-Panel: read-only,
 * GP-Szenario, Validierung.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Butter 1 kg', 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->la->id, 'price' => 10.0, 'status' => '0', 'valid_to' => null]);
    $this->gp = $this->makeGp($this->rootTeam, 'Butter');
    $this->gp->update(['lead_la_supplier_item_id' => $this->la->id, 'commodity_group_code' => '01']);
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->la->id, 'gp_id' => $this->gp->id,
    ]);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'gericht-x', 'name' => 'Buttergericht', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 20.0,
    ]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'gp_id' => $this->gp->id, 'raw_text' => 'Butter', 'quantity' => '500', 'unit_vocab_id' => $g->id, 'position' => 1,
    ]);
});

it('rendert das Panel mit Default-Scope warengruppe', function () {
    Livewire::test(Simulation::class)
        ->assertOk()
        ->assertSet('scope', 'warengruppe')
        ->assertSet('result', null);
});

it('GP-Szenario +50% liefert Marge-Delta + Top-Gericht; verändert keinen Preis (read-only)', function () {
    $c = Livewire::test(Simulation::class)
        ->set('scope', 'gp')
        ->call('waehleGp', $this->gp->id, 'Butter')
        ->set('deltaPct', 50)
        ->call('simuliere');

    $res = $c->get('result');
    expect($res)->not->toBeNull()
        ->and($res['n_gps'])->toBe(1)
        ->and($res['n_gerichte'])->toBe(1)
        ->and($res['marge_delta_eur'])->toBeLessThan(0.0)
        ->and($res['top'][0]['recipe_id'])->toBe($this->gericht->id);
    $c->assertSet('ref', (string) $this->gp->id);

    // read-only: Preis in der DB unverändert
    expect((float) FoodAlchemistPrice::where('supplier_item_id', $this->la->id)->value('price'))->toBe(10.0);
});

it('leerer Bezug erzeugt einen Validierungsfehler statt zu rechnen', function () {
    Livewire::test(Simulation::class)
        ->set('scope', 'warengruppe')
        ->set('ref', '')
        ->call('simuliere')
        ->assertHasErrors('ref')
        ->assertSet('result', null);
});

it('Scope-Wechsel setzt den Bezug zurück', function () {
    Livewire::test(Simulation::class)
        ->set('scope', 'gp')
        ->call('waehleGp', $this->gp->id, 'Butter')
        ->assertSet('ref', (string) $this->gp->id)
        ->set('scope', 'artikel')
        ->assertSet('ref', '')
        ->assertSet('refLabel', '');
});
