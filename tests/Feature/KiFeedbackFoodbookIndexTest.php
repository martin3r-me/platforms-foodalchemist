<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Paket G (Spec 53), Restliste — dieselbe Umstellung wie im Angebots-Editor
 * ({@see \tests\Feature\KiFeedbackAngebotEditorTest}), fürs Foodbook-Kapitel
 * (strukturell identisch: Editor ist der geforkte Zwilling von Angebote\Editor).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->foodbooks = app(FoodbookService::class);
    $this->fb = $this->foodbooks->create($this->rootTeam, ['label' => 'KI-Feedback-Foodbook', 'personen' => 20]);
});

it('Foodbook-Index: „KI-Text" (kiKapitelText) hat data-ki-action + wire:target', function () {
    $t = Livewire::test(Index::class)->call('waehle', $this->fb->id);
    $kapId = $this->foodbooks->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Menü'])->id;
    $t->call('kapitelWaehle', $kapId);

    expect($t->html())->toContain('data-ki-action="kiKapitelText"')
        ->toContain('wire:target="kiKapitelText"');
});

it('Foodbook-Index: „Kapitel-Wording" (kapitelWordingGenerieren) hat data-ki-action + wire:target', function () {
    $t = Livewire::test(Index::class)->call('waehle', $this->fb->id);
    $kapId = $this->foodbooks->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Menü'])->id;
    $t->call('kapitelWaehle', $kapId);

    expect($t->html())->toContain('data-ki-action="kapitelWordingGenerieren"')
        ->toContain('wire:target="kapitelWordingGenerieren"');
});

it('Foodbook-Index: „KI-Kundentext" (kiKundentext) hat data-ki-action + wire:target', function () {
    $c = FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'KI-Feedback-Concept', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 9.0, 'ek_per_person_cache' => 3.0,
    ]);
    $t = Livewire::test(Index::class)->call('waehle', $this->fb->id);
    $kapId = $this->foodbooks->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Menü'])->id;
    $t->call('kapitelWaehle', $kapId)->call('conceptHinzu', $c->id);
    $blockId = FoodAlchemistFoodbookBlock::where('chapter_id', $kapId)->where('type', 'concept_ref')->value('id');
    $t->call('blockBearbeiten', $blockId);

    expect($t->html())->toContain('data-ki-action="kiKundentext"')
        ->toContain('wire:target="kiKundentext"');
});
