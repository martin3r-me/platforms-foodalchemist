<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Nachtrag 27.10. (Kurator, Dominique): GP-Editor im Bearbeiten-Fall — Zustand und „Gewicht oder Portion“ im Namens-Abschnitt, Kennung live aus den
 * Feldern (die gespeicherte daneben, weil updateGp gp_key nie umschreibt), und woher eine Portion kommt, die nicht im GP steht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('Bearbeiten: Zustand und „Gewicht oder Portion“ stehen im Namens-Abschnitt (eigene IDs, gleiche Bindung)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Brötchen: Mini, TK');

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->assertSeeHtml('id="gp-zustand-edit"')
        ->assertSeeHtml('id="gp-portion-edit"')
        ->assertSeeHtml('Portionsgrammatur pro Stück, nie Packungsgröße (§7.1)')
        ->set('builder.portion', '45 g')
        ->call('nameAusFeldern')                                            // im Bearbeiten-Fall gilt der Name; aus den Feldern neu gebildet
        ->assertSet('manuellerName', fn ($n) => str_contains($n, '45 g pro Stück'));
});

it('Kennung in der Vorschau kommt live aus den Feldern; die gespeicherte steht daneben, solange sie abweicht', function () {
    $gp = $this->makeGp($this->rootTeam, 'Aalfilet');
    $gp->forceFill(['gp_key' => 'aalfilet||'])->save();

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->set('builder.hauptzutat', 'Aalfilet')->set('builder.processing', 'geräuchert')->set('builder.form', 'Filet')
        ->assertSeeHtml('<span class="font-mono" data-vorschau-key>aalfilet|geraeuchert|filet</span>')
        ->assertSeeHtml('<span class="font-mono" data-gespeicherter-key>aalfilet||</span>');
});

it('Portion „250 g“ ohne gp.portion: kommt aus „Felder aus dem Namen übernehmen“ (… 250 g pro Stück im Namen) — das Feld zeigt sie jetzt und lässt sie leeren', function () {
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Räucherei Test']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id,
        'designation' => 'Räucheraalfilets 250g', 'article_number' => 'A1']);
    $la->forceFill(['qty' => 250, 'unit_code' => 'g'])->save();
    $gp = $this->makeGp($this->rootTeam, 'Aalfilet: geräuchert, 250 g pro Stück');
    $gp->forceFill(['portion' => null, 'lead_la_supplier_item_id' => $la->id])->save();

    $m = Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->assertSet('builder.portion', '')                                    // geladen wird gp.portion (NULL) — kein Altzustand
        ->assertDontSeeHtml('entspricht der Gebindemenge');

    $m->call('felderAusName')
        ->assertSet('builder.portion', '250 g')                               // die einzige Quelle im Bearbeiten-Fall
        ->assertSeeHtml('entspricht der Gebindemenge')
        ->set('builder.portion', '')
        ->assertDontSeeHtml('entspricht der Gebindemenge');
});
