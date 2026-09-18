<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53), Restliste — dieselbe Umstellung wie im Recipe-/VK-Modal
 * ({@see \tests\Feature\KiFeedbackRecipeModalTest}), fürs Concepter-Wording.
 * Einziger echter KI-Knopf in concepter/editor.blade.php — die übrigen
 * $btnAi-Knöpfe (vorschlagFuerSlot, slotZutatTauschen, ...) sind laut eigenem
 * Titel-Text explizit "Ohne KI" (deterministisch), daher nicht im Scope.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'KI-Feedback-Konzept']);
});

it('Concepter-Editor: „Wording" (wordingGenerieren) hat data-ki-action + wire:target', function () {
    $t = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id);
    $t->call('setTab', 'stammdaten');

    expect($t->html())->toContain('data-ki-action="wordingGenerieren"')
        ->toContain('wire:target="wordingGenerieren"');
});
