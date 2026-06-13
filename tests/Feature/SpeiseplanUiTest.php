<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Speiseplan\Index as SpeiseplanIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M14-02: Speiseplan-Raster — anlegen, Zelle belegen (Gericht), Eintrag erscheint.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g1', 'name' => 'Tagessuppe Kürbis', 'status' => 'approved',
        'ist_verkaufsrezept' => true, 'vk_netto' => 3.50, 'ek_total_eur' => 1.00,
    ]);
});

it('Speiseplan-Raster: anlegen, Zelle (Mo Mittag) mit Gericht belegen', function () {
    Livewire::test(SpeiseplanIndex::class)->assertOk()->call('neu');
    $sp = FoodAlchemistSpeiseplan::first();
    expect($sp)->not->toBeNull();

    Livewire::test(SpeiseplanIndex::class)
        ->call('waehle', $sp->id)
        ->set('form.name', 'KW 24')->call('speichern')
        ->call('zelleOeffnen', 1, 'mittag')
        ->set('pickerTyp', 'gericht')
        ->set('pickerSuche', 'Kürbis')
        ->call('inhaltHinzu', 'gericht', $this->gericht->id)
        ->assertSee('Tagessuppe Kürbis');

    $e = $sp->eintraege()->first();
    expect($e)->not->toBeNull()
        ->and($e->wochentag)->toBe(1)->and($e->mahlzeit)->toBe('mittag')
        ->and($e->vk_recipe_id)->toBe($this->gericht->id);
});
