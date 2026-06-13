<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Kalkulation\Index as KalkulationIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M12-02: Kalkulations-Übersicht — HK1/HK2/VK/DB je Gericht, Voll-Page-Render.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    app(TeamSettingsService::class)->update($this->rootTeam, ['hk2_zuschlag_pct' => 20]);

    FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g1', 'name' => 'HG: Filet Wellington', 'status' => 'approved',
        'ist_verkaufsrezept' => true, 'ek_total_eur' => 10.00, 'vk_anzahl_einheiten' => 5, 'nebenkosten_eur' => 2.50, 'vk_netto' => 8.00,
    ]);
});

it('Kalkulations-Übersicht rendert HK1/HK2/DB pro Gericht', function () {
    Livewire::test(KalkulationIndex::class)
        ->assertOk()
        ->assertSee('Filet Wellington')
        ->assertSee('2,90')                                          // HK2 pro Portion (14,50/5)
        ->assertSee('5,10');                                         // DB (8,00 − 2,90)
});

it('Tab-Wechsel zu Concepts funktioniert', function () {
    Livewire::test(KalkulationIndex::class)
        ->call('setTab', 'concepts')
        ->assertSet('tab', 'concepts')
        ->assertOk();
});
