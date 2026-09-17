<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53), Restliste — dieselbe Umstellung wie im Recipe-/VK-Modal
 * ({@see \tests\Feature\KiFeedbackRecipeModalTest}), fürs Angebots-Editor-Kapitel.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(AngebotService::class);
    $this->angebot = $this->svc->create($this->rootTeam, ['name' => 'KI-Feedback-Angebot', 'personen' => 20]);
});

it('Angebots-Editor: „KI-Text" (kiKapitelText) hat data-ki-action + wire:target', function () {
    $t = Livewire::test(Editor::class)->call('oeffnen', $this->angebot->id);
    $t->call('kapitelNeu');

    expect($t->html())->toContain('data-ki-action="kiKapitelText"')
        ->toContain('wire:target="kiKapitelText"');
});

it('Angebots-Editor: „Kapitel-Wording" (kapitelWordingGenerieren) hat data-ki-action + wire:target', function () {
    $t = Livewire::test(Editor::class)->call('oeffnen', $this->angebot->id);
    $t->call('kapitelNeu');

    expect($t->html())->toContain('data-ki-action="kapitelWordingGenerieren"')
        ->toContain('wire:target="kapitelWordingGenerieren"');
});

it('Angebots-Editor: „KI-Kundentext" (kiKundentext) hat data-ki-action + wire:target', function () {
    $c = FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'KI-Feedback-Concept', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 9.0, 'ek_per_person_cache' => 3.0,
    ]);

    $t = Livewire::test(Editor::class)->call('oeffnen', $this->angebot->id);
    $t->call('kapitelNeu');
    $kapId = \Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter::where('offer_id', $this->angebot->id)->orderBy('position')->value('id');
    $t->call('kapitelWaehle', $kapId)->call('conceptHinzu', $c->id);
    $blockId = \Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock::where('chapter_id', $kapId)->where('type', 'concept_ref')->value('id');
    $t->call('blockBearbeiten', $blockId);

    expect($t->html())->toContain('data-ki-action="kiKundentext"')
        ->toContain('wire:target="kiKundentext"');
});
