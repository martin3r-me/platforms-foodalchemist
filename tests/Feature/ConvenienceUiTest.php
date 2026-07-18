<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\GeneratorModal;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Livewire\Verkauf\VkGeneratorModal;
use Platform\FoodAlchemist\Services\ConvenienceHighlightService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 06·H4 — UI: Convenience-Toggle an den Generator-Modals + „nur Convenience-
 * Highlights"-Filter im GP-Picker (browseKatalog).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);

    // ein gepinnter Convenience-GP + ein normaler GP
    $this->conv = $this->makeGp($this->rootTeam, 'TK-Spätzle');
    $this->conv->update(['status' => 'approved', 'tag_is_convenience' => true]);
    app(ConvenienceHighlightService::class)->pin($this->conv, 1);

    $this->normal = $this->makeGp($this->rootTeam, 'Frischer Spinat');
    $this->normal->update(['status' => 'approved']);
});

it('Rezept-Generator-Modal rendert mit Convenience-Checkbox', function () {
    Livewire::test(GeneratorModal::class)
        ->assertOk()
        ->assertSee('Convenience-Liste')
        ->set('useConvenienceList', true)
        ->assertSet('useConvenienceList', true);
});

it('VK-Generator-Modal rendert mit Convenience-Checkbox', function () {
    Livewire::test(VkGeneratorModal::class)
        ->assertOk()
        ->assertSee('Convenience-Liste');
});

it('GP-Picker-Filter „nur_convenience" verengt auf Highlights', function () {
    $comp = Livewire::test(IngredientEditor::class);

    $alle = $comp->instance()->browseKatalog([], [], 'spät');
    // ohne Filter: TK-Spätzle sichtbar
    expect(collect($alle['gps']['items'])->pluck('name'))->toContain('TK-Spätzle');

    $nurConv = $comp->instance()->browseKatalog(['nur_convenience' => true], [], '');
    $namen = collect($nurConv['gps']['items'])->pluck('name');
    expect($namen)->toContain('TK-Spätzle')->not->toContain('Frischer Spinat');
});
