<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 §5.5 · Kapitel-Fortschritt im Foodbook-Board läuft über FoodbookService (ein Schreibweg), nicht mehr als
 * direktes `$k->update` in der Livewire-Komponente.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $fb = $this->makeFoodbook($this->rootTeam, 'Board');
    $this->kap = $this->makeChapter($fb, ['title' => 'Vorspeisen']);
});

it('Spec 84 §5.5 · Service: Besitzer setzt die Stufe, unbekannte Stufe und geerbtes Kapitel werden abgewiesen', function () {
    $svc = app(FoodbookService::class);

    expect($svc->kapitelFortschritt($this->rootTeam, $this->kap->id, 'in_arbeit')->fortschritt)->toBe('in_arbeit')
        ->and(fn () => $svc->kapitelFortschritt($this->rootTeam, $this->kap->id, 'quatsch'))->toThrow(\RuntimeException::class, 'Unbekannte')
        ->and(fn () => $svc->kapitelFortschritt($this->childA, $this->kap->id, 'fertig'))->toThrow(\RuntimeException::class, 'Geerbtes Foodbook')
        ->and(FoodAlchemistFoodbookKapitel::find($this->kap->id)->fortschritt)->toBe('in_arbeit');
});

it('Spec 84 §5.5 · Board: Kind-Team ändert den Fortschritt eines geerbten Kapitels nicht', function () {
    $this->actingAs($this->makeUser($this->childA, 'Kind'));

    Livewire::test(\Platform\FoodAlchemist\Livewire\Foodbooks\Index::class)->call('kapitelFortschritt', $this->kap->id, 'fertig');

    expect(FoodAlchemistFoodbookKapitel::find($this->kap->id)->fortschritt)->not->toBe('fertig');
});
