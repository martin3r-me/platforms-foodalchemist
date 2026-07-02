<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gericht-Anlegen: Basisrezept ist jetzt OPTIONAL (nur Name Pflicht). Ohne Basis →
 * leeres Verkaufsrezept; Komponenten kommen danach im Editor dazu (entblockt u.a.
 * den Stück-Test). Mit Name leer → Fehler.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('legt ein Gericht OHNE Basisrezept an (nur Name)', function () {
    Livewire::test(VkModal::class)
        ->set('neuName', 'HG: Leeres Testgericht')
        ->set('basisId', null)
        ->call('anlegen')
        ->assertSet('fehler', null);

    $vk = FoodAlchemistRecipe::where('name', 'HG: Leeres Testgericht')->first();
    expect($vk)->not->toBeNull()
        ->and((bool) $vk->ist_verkaufsrezept)->toBeTrue()
        ->and($vk->ingredients()->count())->toBe(0);   // wirklich leer
});

it('verlangt weiterhin einen Namen', function () {
    Livewire::test(VkModal::class)
        ->set('neuName', '   ')
        ->set('basisId', null)
        ->call('anlegen')
        ->assertSet('fehler', 'Bitte einen Namen eingeben.');
});
