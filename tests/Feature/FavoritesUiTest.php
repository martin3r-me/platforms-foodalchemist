<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\GeneratorModal;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Livewire\Verkauf\VkGeneratorModal;
use Platform\FoodAlchemist\Services\FavoriteGpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 06·H4/H4b — UI: Favoriten-Toggle an den Generator-Modals + „nur Favoriten"-
 * Filter im GP-Picker (browseKatalog).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);

    // ein gepinnter Favorit (hier convenience-getaggt) + ein nicht gepinnter GP
    $this->conv = $this->makeGp($this->rootTeam, 'TK-Spätzle');
    $this->conv->update(['status' => 'approved', 'tag_is_convenience' => true]);
    app(FavoriteGpService::class)->pin($this->conv, 1);

    $this->normal = $this->makeGp($this->rootTeam, 'Frischer Spinat');
    $this->normal->update(['status' => 'approved']);
});

it('Rezept-Generator-Modal rendert mit Favoriten-Checkbox', function () {
    Livewire::test(GeneratorModal::class)
        ->assertOk()
        ->assertSee('Auf Basis meiner Favoriten bauen')
        ->set('useFavoritesList', true)
        ->assertSet('useFavoritesList', true)
        ->set('favoritesConvenienceOnly', true)
        ->assertSet('favoritesConvenienceOnly', true);
});

it('VK-Generator-Modal rendert mit Favoriten-Checkbox', function () {
    Livewire::test(VkGeneratorModal::class)
        ->assertOk()
        ->assertSee('Auf Basis meiner Favoriten bauen');
});

it('GP-Picker-Filter „nur_favoriten" verengt auf gepinnte Favoriten', function () {
    $comp = Livewire::test(IngredientEditor::class);

    $alle = $comp->instance()->browseKatalog([], [], 'spät');
    // ohne Filter: TK-Spätzle sichtbar
    expect(collect($alle['gps']['items'])->pluck('name'))->toContain('TK-Spätzle');

    $nurFav = $comp->instance()->browseKatalog(['nur_favoriten' => true], [], '');
    $namen = collect($nurFav['gps']['items'])->pluck('name');
    expect($namen)->toContain('TK-Spätzle')->not->toContain('Frischer Spinat');
});
