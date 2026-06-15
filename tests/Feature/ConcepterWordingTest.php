<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Concept-übergreifendes Wording: ein Schreibstil fürs ganze Konzept erzeugt (über das
 * Gateway) je Position einen Brand-Voice-Anzeigenamen + einen Konzept-Einleitungstext.
 * KI über FakeAiProvider/Spy (echter Text erst mit LLM-Key — Muster wie VkModal::ki).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count',
    ]);
    $this->green = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'Green Power',
        'status' => 'approved', 'ist_verkaufsrezept' => true, 'vk_netto' => 2.00, 'ek_total_eur' => 0.60,
    ]);
    $this->concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
});

it('✨ erzeugt Konzept-Intro (in beschreibung) + Brand-Voice-Namen je Position', function () {
    $comp = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id);
    $comp->call('positionEinfuegen', 'gericht', $this->green->id);
    $slot = $this->concept->slots()->orderBy('position')->first();

    // Spy liefert kontrolliertes Wording (intro + slots[slotId]) — echter LLM wäre der Provider.
    $spy = new class($slot->id) extends FakeAiProvider
    {
        public function __construct(public int $slotId) {}

        public function chat(array $messages, array $options = []): array
        {
            return [
                'content' => json_encode(['werte' => [
                    'intro' => 'Ein sommerliches Grill-Erlebnis.',
                    'slots' => [$this->slotId => 'Knuspriger Hot-Dog-Traum'],
                ], 'confidence' => 0.9]),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'spy', 'tool_calls' => null,
            ];
        }
    };
    app()->instance(FakeAiProvider::class, $spy);

    $comp->call('wordingGenerieren')->assertSet('fehler', null);

    expect($this->concept->fresh()->beschreibung)->toBe('Ein sommerliches Grill-Erlebnis.')
        ->and($slot->fresh()->wording)->toBe('Knuspriger Hot-Dog-Traum');
});

it('manuelles Überschreiben des Slot-Wordings persistiert', function () {
    $comp = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->concept->id);
    $comp->call('positionEinfuegen', 'gericht', $this->green->id);
    $slot = $this->concept->slots()->orderBy('position')->first();

    $comp->set("slotForm.{$slot->id}.wording", 'Mein Anzeigename')->call('wordingSpeichern', $slot->id);

    expect($slot->fresh()->wording)->toBe('Mein Anzeigename');

    // Leeren → null
    $comp->set("slotForm.{$slot->id}.wording", '')->call('wordingSpeichern', $slot->id);
    expect($slot->fresh()->wording)->toBeNull();
});
