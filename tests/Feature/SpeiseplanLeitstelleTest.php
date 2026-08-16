<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor as SpeiseplanEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Etappe 5 P5 — Speiseplan als Leitstelle-Trigger: aus den Menü-Linien × dem Zyklus (cycle_weeks ×
 * Mo–Fr × Mittag) eine Voll-Kaskade starten — je LEERER Zelle EIN Gericht-Step + {@see MaterializeSpeiseplanCellJob}
 * — und in den Planung-Editor zur Sammel-Review leiten. Anders als Foodbook/Speisekarte (Slot → Concept)
 * hält eine Zelle EIN Gericht (kind='gericht', nicht 'concept'). Der Service-Pfad
 * ({@see PlanningCascadeService::starteSpeiseplanVollkaskade}) ist in PlanningCascadeTest gepinnt; hier
 * fehlte die Livewire-Trigger-Deckung (Session-Anlage, Redirect, Cap, Fehlerpfad) — 1:1 analog zu
 * FoodbookLeitstelleTest / SpeisekarteLeitstelleTest.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->plaene = app(SpeiseplanService::class);
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('vollKaskadeStarten (Leitstelle P5): legt eine Review-Session an, startet die Voll-Kaskade (Gericht-Step je leerer Zelle) und leitet in den Planung-Editor', function () {
    Queue::fake();

    // 1 Zyklus-Woche × 3 Starter-Linien × 5 Werktage = 15 leere Zellen (< Cap 30).
    $plan = $this->plaene->create($this->rootTeam, ['name' => 'Leitstelle-Speiseplan', 'cycle_weeks' => 1]);
    $zellen = $plan->lines()->count() * 5;
    expect($zellen)->toBe(15);

    Livewire::test(SpeiseplanEditor::class)
        ->set('planId', $plan->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect()
        ->assertSet('kaskadeMeldung', null);

    // Ausgabe-Modul = Quelle: die Review-Wurzel wird als Planungs-Session mit speiseplan-Herkunft angelegt.
    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'speiseplan_vollkaskade')->latest('id')->first();
    expect($session)->not->toBeNull();

    // Genau ein Voll-Kaskaden-Lauf am Speiseplan + je leerer Zelle ein Gericht-Step; kein Deckel (15 < 30).
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'speiseplan')
        ->where('source_owner_id', $plan->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->scope)->toBe('vollkaskade')
        ->and($run->status)->toBe('running')
        ->and($run->planning_session_id)->toBe($session->id)
        ->and($run->steps()->where('kind', 'gericht')->count())->toBe($zellen)
        ->and($run->params['gedeckelt_zellen_offen'] ?? null)->toBeNull();

    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, $zellen);
});

it('vollKaskadeStarten deckelt den Zell-Fan-out (SPEISEPLAN_MAX_ZELLEN=30) — Rest steht ehrlich im Run', function () {
    Queue::fake();

    // 4 Zyklus-Wochen × 3 Linien × 5 Werktage = 60 leere Zellen → gedeckelt auf 30, 30 offen.
    $plan = $this->plaene->create($this->rootTeam, ['name' => 'Großer Zyklus', 'cycle_weeks' => 4]);
    expect($plan->lines()->count() * 4 * 5)->toBe(60);

    Livewire::test(SpeiseplanEditor::class)
        ->set('planId', $plan->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect()
        ->assertSet('kaskadeMeldung', null);

    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'speiseplan')
        ->where('source_owner_id', $plan->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->steps()->where('kind', 'gericht')->count())->toBe(30)   // harter Cap
        ->and($run->params['gedeckelt_zellen_offen'] ?? null)->toBe(30);     // kein stiller Deckel

    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, 30);
});

it('vollKaskadeStarten ohne Menü-Linien meldet ehrlich (kaskadeMeldung) — kein Lauf, kein Redirect, kein Job', function () {
    Queue::fake();

    $plan = $this->plaene->create($this->rootTeam, ['name' => 'Plan ohne Linien']);
    foreach ($plan->lines()->pluck('id') as $linieId) {
        $this->plaene->removeLinie($this->rootTeam, (int) $linieId);
    }
    expect($plan->lines()->count())->toBe(0);

    Livewire::test(SpeiseplanEditor::class)
        ->set('planId', $plan->id)
        ->call('vollKaskadeStarten')
        ->assertNoRedirect()
        ->assertSet('kaskadeMeldung', fn ($v) => is_string($v) && $v !== '');

    expect(FoodAlchemistCascadeRun::where('source_owner_type', 'speiseplan')->where('source_owner_id', $plan->id)->count())->toBe(0);
    Queue::assertNotPushed(MaterializeSpeiseplanCellJob::class);
});
