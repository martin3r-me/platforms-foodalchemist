<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 80 Teil D — Fortschritt als Baum · Gericht-Cluster · Rezept. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
    $this->session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Speisekarte Herbst', 'brief' => 'x']);
    $frame = FoodAlchemistPlanningFrame::create(['team_id' => $this->rootTeam->id, 'owner_type' => 'foodbook', 'owner_id' => 1]);
    $this->hauptgang = FoodAlchemistPlanningFrameSlot::create(['frame_id' => $frame->id, 'position' => 1, 'label' => 'Hauptgerichte', 'slot_type' => 'gang']);
    $this->lauf = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $this->session->id,
        'scope' => 'vollkaskade', 'status' => 'review', 'staged' => true, 'brief' => 'Herbstkarte']);
    $this->gericht = $this->makeRecipe($this->rootTeam, '[HG] Kartoffelteig | Pilzjus', ['status' => 'draft', 'is_sales_recipe' => true]);
    $this->jus = $this->makeRecipe($this->rootTeam, 'Jus: Pilz', ['status' => 'draft']);
    $this->kopf = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $this->lauf->id, 'kind' => 'gericht',
        'status' => 'done', 'label' => '[HG] Kartoffelteig | Pilzjus', 'ref_type' => 'recipe', 'ref_id' => $this->gericht->id, 'slot_id' => $this->hauptgang->id]);
    $this->kindJus = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $this->lauf->id, 'kind' => 'rezept',
        'status' => 'done', 'label' => 'Jus: Pilz', 'parent_step_id' => $this->kopf->id, 'depth' => 1, 'ref_type' => 'recipe', 'ref_id' => $this->jus->id,
        'context_snapshot' => ['suchbegriffe' => ['Steinpilz', 'Jus'], 'pairing' => ['anker' => ['Steinpilz'], 'ohne_anker' => ['Pilzjus']],
            'bestand_abgelehnt' => [['recipe_id' => 9, 'name' => 'Garnitur: Pilz', 'grund' => 'Typ „Garnitur“ steht nicht im Typ-Vokabular']]]]);
    $this->kindFehler = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $this->lauf->id, 'kind' => 'rezept',
        'status' => 'failed', 'label' => 'Matte: Petersilie', 'parent_step_id' => $this->kopf->id, 'depth' => 1, 'error' => 'Duplicate entry']);
    FoodAlchemistRecipeIngredient::create(['team_id' => $this->jus->team_id, 'recipe_id' => $this->jus->id, 'raw_text' => 'Steinpilze getrocknet',
        'quantity' => '120', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);
});

it('zeigt als einzige Ansicht Baum, Cluster mit Basisrezepten und Stufenleiste', function () {
    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->session->id)
        ->assertSeeHtml('data-fortschritt-neu')
        ->assertSeeHtml('data-fortschritt-knoten="slot:' . $this->hauptgang->id . '"')
        ->assertSee('Hauptgerichte')
        ->assertSeeHtml('data-fortschritt-kopf="' . $this->kopf->id . '"')
        ->assertSeeHtml('data-fortschritt-zeile="' . $this->kindJus->id . '"')
        ->assertSee('Basisrezepte')
        ->assertSee('HG');
});

it('rechts: Rezeptansicht mit Zutaten und „Woher das kommt"', function () {
    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->session->id)
        ->call('waehleSchritt', $this->kindJus->id)
        ->assertSeeHtml('data-fortschritt-rezept')
        ->assertSee('Steinpilze getrocknet')
        ->assertSee('Steinpilz')
        ->assertSee('kein Anker für: Pilzjus')
        ->assertSee('nicht übernommen: Garnitur: Pilz');
});

it('Filter „Fehler" zeigt nur die gescheiterte Zeile, Knoten-Auswahl setzt den Pfad', function () {
    $c = Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->session->id)
        ->call('setzeFortschrittFilter', 'fehler');

    $c->assertSeeHtml('data-fortschritt-zeile="' . $this->kindFehler->id . '"')
        ->assertDontSeeHtml('data-fortschritt-zeile="' . $this->kindJus->id . '"');

    $c->call('setzeFortschrittFilter', 'alle')->call('waehleKnoten', 'slot:' . $this->hauptgang->id)
        ->assertSeeHtml('aria-current="true"');
});

it('Cluster-Fuß reichert nur diesen Cluster an und gibt frei', function () {
    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $this->session->id)
        ->call('clusterFreigeben', $this->kopf->id);

    expect($this->kopf->refresh()->status)->toBe('freigegeben')
        ->and($this->kindJus->refresh()->status)->toBe('freigegeben')
        ->and($this->kindFehler->refresh()->status)->toBe('failed');
});
