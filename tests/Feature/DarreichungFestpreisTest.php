<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gericht-Modal → Kalkulation → Darreichungen, Preis „fest“ (Dominique 13.11.2026):
 * Festpreis speichert beim Verlassen von Preis- oder Begründungsfeld, sobald beide gefüllt sind;
 * fehlt eins, wird nicht gespeichert und kein Fehler gesetzt. Zahlen stehen deutsch im Feld.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-festpreis', 'name' => 'FIN: Festpreis-Test',
        'status' => 'draft', 'is_sales_recipe' => true,
    ]);
    $form = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'portion', 'label' => 'Portion']);
    $this->dar = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->vk->id, 'serving_form_id' => $form->id,
        'is_standard' => true, 'price_mode' => 'auto', 'sales_net' => 2.5, 'quantity_per_unit_g' => 120.5,
    ]);
});

it('speichert den Festpreis beim Verlassen des Begründungsfelds, sobald Preis und Begründung stehen', function () {
    $id = $this->dar->id;
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->call('darreichungPreisModusGeaendert', $id, 'fixed')
        ->set("darForm.{$id}.sales_net", '3,9')
        ->call('darreichungFestpreisFeld', $id)
        ->assertSet('fehler', null)
        ->assertSee('Begründung fehlt')
        ->set("darForm.{$id}.price_override_reason", 'Rahmenvertrag')
        ->call('darreichungFestpreisFeld', $id)
        ->assertSet('fehler', null)
        ->assertSet("darForm.{$id}.sales_net", '3,90')
        ->assertSet("darForm.{$id}.price_mode", 'fixed')
        ->assertDontSee('Begründung fehlt');

    $d = $this->dar->fresh();
    expect($d->price_mode)->toBe('fixed')
        ->and((float) $d->sales_net)->toBe(3.9)
        ->and($d->price_override_reason)->toBe('Rahmenvertrag');
});

it('speichert nur mit Preis nicht und setzt keinen Fehler', function () {
    $id = $this->dar->id;
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->call('darreichungPreisModusGeaendert', $id, 'fixed')
        ->set("darForm.{$id}.sales_net", '4,20')
        ->call('darreichungFestpreisFeld', $id)
        ->assertSet('fehler', null)
        ->assertSee('Begründung fehlt')
        ->assertSet("darForm.{$id}.sales_net", '4,20');

    $d = $this->dar->fresh();
    expect($d->price_mode)->toBe('auto')
        ->and((float) $d->sales_net)->toBe(2.5);
});

it('meldet „Preis fehlt“ ohne Preis und speichert nicht', function () {
    $id = $this->dar->id;
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->call('darreichungPreisModusGeaendert', $id, 'fixed')
        ->set("darForm.{$id}.sales_net", '')
        ->set("darForm.{$id}.price_override_reason", 'Rahmenvertrag')
        ->call('darreichungFestpreisFeld', $id)
        ->assertSet('fehler', null)
        ->assertSee('Preis fehlt');

    expect($this->dar->fresh()->price_mode)->toBe('auto');
});

it('lädt Dezimalfelder deutsch ins Formular', function () {
    $id = $this->dar->id;
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->assertSet("darForm.{$id}.sales_net", '2,50')
        ->assertSet("darForm.{$id}.quantity_per_unit_g", '120,5');
});

it('weist auf eine noch unbestimmte Servierform hin', function () {
    $unbestimmt = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'unbestimmt', 'label' => 'Unbestimmt (Review)']);
    $this->dar->update(['serving_form_id' => $unbestimmt->id]);

    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();
    expect($html)->toContain('data-dar-form-waehlen')->and($html)->toContain('Servierform wählen');
});
