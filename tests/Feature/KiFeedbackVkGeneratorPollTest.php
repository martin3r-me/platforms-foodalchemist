<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Livewire\Verkauf\VkGeneratorModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G, Aufgabe 3 (Job-Knöpfe), Restliste — dieselbe Prüfung wie im
 * Basisrezept-Generator ({@see \tests\Feature\KiFeedbackGeneratorPollTest}):
 * der VK-Generator teilt sich den Lauf-Trait (HatGeneratorLauf) mit dem
 * Basisrezept-Generator, „Generieren" dispatcht denselben GenerateRecipeJob
 * (vkModus: true) und hält die Poll-Regel schon ein — kein Code-Fix nötig,
 * dieser Test hält den Ist-Stand als Beleg fest.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
});

it('VK-Generator-Modal: kein wire:poll im Ruhezustand, wire:poll erscheint nach dem Klick', function () {
    $component = Livewire::test(VkGeneratorModal::class);

    expect($component->html())->not->toContain('wire:poll');

    $component->set('description', 'Testgericht für den Poll-Beleg')->call('generieren');
    Queue::assertPushed(GenerateRecipeJob::class);

    expect($component->html())->toContain('wire:poll.2s="pruefeErgebnis"')
        ->toContain('data-vk-generator-laeuft');
});
