<?php

use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M1-08 (DoD): Leak-Test auf UI-Ebene — der Kind-User sieht für geerbte
 * Katalog-Zeilen KEINE Edit-Buttons (Curate::canCurate gated jede Zeile).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    app(VocabularyService::class)->createEinheit($this->rootTeam, ['slug' => 'stk', 'display_de' => 'Stück']);
});

function renderEinheitenView($testCase): string
{
    return view('foodalchemist::livewire.settings.einheiten', [
        'team' => auth()->user()->currentTeamRelation,
        'einheiten' => app(VocabularyService::class)->listEinheiten(auth()->user()->currentTeamRelation),
        'fehler' => null,
        'editId' => null,
        'includeInactive' => false,
        'neu' => ['slug' => '', 'display_de' => '', 'dimension' => '', 'default_in_g' => '', 'default_in_ml' => '', 'sort_order' => 50],
        'form' => [],
    ])->render();
}

it('Kind-User sieht für geerbte Einheiten KEINE Edit-Buttons, nur die geerbt-Pill', function () {
    $this->actingAs($this->makeUser($this->childA, 'Kind User'));

    $html = renderEinheitenView($this);

    expect($html)->toContain('data-einheit-aktionen="readonly"')
        ->and($html)->not->toContain('data-einheit-aktionen="edit"')
        ->and($html)->toContain('geerbt')
        ->and($html)->not->toContain('wire:click="edit(');
});

it('Besitzer-User sieht die Edit-Buttons', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $html = renderEinheitenView($this);

    expect($html)->toContain('data-einheit-aktionen="edit"')
        ->and($html)->toContain('wire:click="edit(')
        ->and($html)->not->toContain('data-einheit-aktionen="readonly"');
});
