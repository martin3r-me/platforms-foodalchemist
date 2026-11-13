<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Leitstelle, Reiter Concept: ein bestehendes Concept in die Planung nehmen (Trait PlanBestehendesConcept).
 * Vorher entstand der geplante Pfad nur über „Plan ausarbeiten lassen", also immer mit einem neuen Concept.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
    $this->concepts = app(ConceptService::class);
    $this->session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Green & Tasty']);
});

it('Auswahl zeigt nur eigene Konzepte: kein Paket, keine Vorlage, kein fremdes Team', function () {
    $eigen = $this->concepts->create($this->rootTeam, ['name' => 'Green & Tasty']);
    $this->concepts->create($this->rootTeam, ['name' => 'Gerüst-Vorlage', 'is_template' => true]);
    FoodAlchemistConcept::where('id', $this->concepts->create($this->rootTeam, ['name' => 'Station als Paket'])->id)->update(['kind' => 'paket']);
    $this->concepts->create($this->childA, ['name' => 'Fremdes Concept']);

    $liste = Livewire::test(PlanungIndex::class)->call('oeffne', $this->session->id)->instance()->bestehendeConcepts();

    expect($liste->pluck('id')->all())->toBe([(int) $eigen->id]);
});

it('Wählen setzt den Plan, speichert ihn an der Planung und belegt leeren Titel und leeres Briefing vor', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Green & Tasty']);
    $concept->update(['description' => 'Drei Stationen, ein Kreislauf.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->session->id)
        ->set('eingabe.concept.titel', '')
        ->set('eingabe.concept.brief', '')
        ->call('bestehendesConceptWaehlen', (string) $concept->id)
        ->assertSet('fehler', null)
        ->assertSet('planConceptId', (int) $concept->id)
        ->assertSet('eingabe.concept.titel', 'Green & Tasty')
        ->assertSet('eingabe.concept.brief', 'Drei Stationen, ein Kreislauf.')
        ->assertSeeHtml('data-planung-plan-panel')
        ->assertSeeHtml('data-planung-concept-bestehend');

    expect((int) $this->session->refresh()->plan_concept_id)->toBe((int) $concept->id);
});

it('ein schon getipptes Briefing bleibt stehen', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Green & Tasty']);
    $concept->update(['description' => 'Aus dem Concept.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->session->id)
        ->set('eingabe.concept.brief', 'Mein eigener Auftrag.')
        ->call('bestehendesConceptWaehlen', $concept->id)
        ->assertSet('eingabe.concept.brief', 'Mein eigener Auftrag.');
});

it('Go mit bestehendem Concept: kein neues Concept, der Lauf zeigt auf das gewählte', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Green & Tasty']);
    $vorher = FoodAlchemistConcept::where('team_id', $this->rootTeam->id)->count();

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->session->id)
        ->call('bestehendesConceptWaehlen', $concept->id)
        ->call('goKaskade', 'concept')
        ->assertSet('fehler', null)
        ->assertSet('laeuft', true)
        ->assertSet('planConceptId', null);   // verbraucht

    Queue::assertNotPushed(GenerateConceptJob::class);
    expect(FoodAlchemistConcept::where('team_id', $this->rootTeam->id)->count())->toBe($vorher);
    $step = FoodAlchemistCascadeRun::where('planning_session_id', $this->session->id)->firstOrFail()->steps()->first();
    expect($step->kind)->toBe('concept')->and($step->ref_type)->toBe('concept')->and((int) $step->ref_id)->toBe((int) $concept->id);
});

it('fremdes Team, Paket oder Vorlage lassen sich nicht wählen', function () {
    $fremd = $this->concepts->create($this->childA, ['name' => 'Fremdes Concept']);
    $vorlage = $this->concepts->create($this->rootTeam, ['name' => 'Gerüst-Vorlage', 'is_template' => true]);

    foreach ([$fremd->id, $vorlage->id, 999999] as $id) {
        Livewire::test(PlanungIndex::class)
            ->call('oeffne', $this->session->id)
            ->call('bestehendesConceptWaehlen', $id)
            ->assertSet('planConceptId', null)
            ->assertSet('fehler', 'Das gewählte Concept wurde nicht gefunden oder gehört zu einem anderen Team.');
    }
    expect($this->session->refresh()->plan_concept_id)->toBeNull();
});

it('leere Auswahl löst den Plan wieder, das Concept bleibt bestehen', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Green & Tasty']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->session->id)
        ->call('bestehendesConceptWaehlen', $concept->id)
        ->call('bestehendesConceptWaehlen', '')
        ->assertSet('planConceptId', null);

    expect($this->session->refresh()->plan_concept_id)->toBeNull()
        ->and(FoodAlchemistConcept::whereKey($concept->id)->exists())->toBeTrue();
});
