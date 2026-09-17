<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Livewire\Recipes\GeneratorModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G, Aufgabe 3 (Job-Knöpfe): Beleg, dass der einzige echte Job-Knopf,
 * den die geprüften Editoren haben (Basisrezept-Generator, dispatcht
 * GenerateRecipeJob), die geforderte Poll-Regel bereits einhält —
 * `wire:poll` NUR solange ein Lauf läuft, Ruhezustand ohne Poll (Muster
 * PlanungLeitstelleTest „kein wire:poll im Ruhezustand"). Vorgefunden, nicht
 * von Paul neu gebaut — Phase 0 (GeneratorFeedbackTest) hat das bereits
 * für exakt diesen Knopf gehärtet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
});

it('Generator-Modal: kein wire:poll im Ruhezustand, wire:poll erscheint nach dem Klick', function () {
    $component = Livewire::test(GeneratorModal::class);

    expect($component->html())->not->toContain('wire:poll');

    $component->set('description', 'Testrezept für den Poll-Beleg')->call('generieren');
    Queue::assertPushed(GenerateRecipeJob::class);

    expect($component->html())->toContain('wire:poll.2s="pruefeErgebnis"')
        ->toContain('data-generator-laeuft');
});
