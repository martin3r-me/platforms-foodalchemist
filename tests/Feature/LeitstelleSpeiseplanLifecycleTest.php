<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec-42-Vollzug Stufe 4 — „Speiseplan aus Brief" IN der Leitstelle. Anders als Foodbook/Speisekarte
 * braucht der Speiseplan kein Gänge-Gerüst: create() legt GV-Standard-Linien + Zyklus + Start-Montag an,
 * die Grid-Kaskade füllt jede Zelle (Tag × Mahlzeit × Linie) brief-gesteuert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('speiseplanAusBrief: legt einen GV-Speiseplan an + startet die Grid-Kaskade (owner=speiseplan), Brief steuert die Zellen', function () {
    Queue::fake();

    Livewire::test(PlanungIndex::class)
        ->set('spTitel', 'GV-Herbst')
        ->set('spBrief', 'Herbstlicher Kantinen-Speiseplan, regional, viel Gemüse.')
        ->call('speiseplanAusBrief')
        ->assertSet('spMeldung', null)
        ->assertSet('spBrief', '');

    // 1. Speiseplan-Hülle mit GV-Standard-Linien (create legt sie an).
    $plan = FoodAlchemistSpeiseplan::where('team_id', $this->rootTeam->id)->where('name', 'GV-Herbst')->latest('id')->first();
    expect($plan)->not->toBeNull()
        ->and($plan->lines()->count())->toBeGreaterThanOrEqual(1);

    // 2. Review-Session + Voll-Kaskade mit Speiseplan-Owner.
    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'leitstelle_speiseplan_brief')->latest('id')->first();
    expect($session)->not->toBeNull();
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'speiseplan')->where('source_owner_id', $plan->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->scope)->toBe('vollkaskade')
        ->and($run->planning_session_id)->toBe($session->id);

    // 3. Je leerer Zelle ein MaterializeSpeiseplanCellJob; der Brief-Rahmen steuert die Generierung mit.
    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, fn ($j) => str_contains($j->brief, 'Rahmen: Herbstlicher Kantinen-Speiseplan'));
});

it('speiseplanAusBrief: Zyklus aus dem Brief („2 Wochen") wird übernommen', function () {
    Queue::fake();

    Livewire::test(PlanungIndex::class)
        ->set('spBrief', 'Rollierender Plan über 2 Wochen, mediterran.')
        ->call('speiseplanAusBrief')
        ->assertSet('spMeldung', null);

    $plan = FoodAlchemistSpeiseplan::where('team_id', $this->rootTeam->id)->latest('id')->firstOrFail();
    expect((int) $plan->cycle_weeks)->toBe(2);
});

it('speiseplanAusBrief: leerer Brief → Meldung, nichts angelegt', function () {
    Queue::fake();

    $comp = Livewire::test(PlanungIndex::class)
        ->set('spBrief', '   ')
        ->call('speiseplanAusBrief');

    expect($comp->get('spMeldung'))->not->toBeNull();
    expect(FoodAlchemistSpeiseplan::where('team_id', $this->rootTeam->id)->count())->toBe(0);
    Queue::assertNotPushed(MaterializeSpeiseplanCellJob::class);
});

/**
 * Stage 2 (SK/SP-Parität, Dominique 2026-08-24) — Speiseplan-Auswähler in der Leitstelle: einen BESTEHENDEN
 * Speiseplan wählen setzt den Owner + reaktiviert dessen jüngste Planungs-Session (updatedSpOwnerId).
 */
it('Speiseplan-Auswähler aktiviert die jüngste Owner-Session', function () {
    $plan = app(\Platform\FoodAlchemist\Services\SpeiseplanService::class)->create($this->rootTeam, ['name' => 'GV-Woche']);
    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)
        ->create($this->rootTeam, ['title' => 'SP-Planung']);
    FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'vollkaskade',
        'creative_mode' => 'voll_kreativ', 'brief' => 'x', 'status' => 'done', 'staged' => false,
        'source_owner_type' => 'speiseplan', 'source_owner_id' => $plan->id, 'created_via' => 'test',
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('spOwnerId', $plan->id)
        ->assertSet('spOwnerId', $plan->id)
        ->assertSet('sessionId', $session->id);
});

it('Speiseplan-Auswähler ohne Vor-Session setzt nur den Owner', function () {
    $plan = app(\Platform\FoodAlchemist\Services\SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Leerer Plan']);
    Livewire::test(PlanungIndex::class)
        ->set('spOwnerId', $plan->id)
        ->assertSet('spOwnerId', $plan->id)
        ->assertSet('sessionId', null);
});
