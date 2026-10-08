<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Services\Ai\KnowledgeBudget;
use Platform\FoodAlchemist\Services\Ai\KnowledgeBudgetExceeded;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Anlass Lauf 72 (2026-09-17): kiDivergenzConcept() baute den Wissensblock aus zwei contextFor-
 * Deckeln (14.000 + 5.000 Zeichen, B1 2026-09-02) ohne ihn je gegen die propose()-Ceiling für
 * `foodbook.kapitel_ideen` (KnowledgeBudget::forKey(), Default 16.200) abzugleichen — überstieg der
 * Block die Ceiling, warf propose() KnowledgeBudgetExceeded VOR jedem Call-Log-Eintrag, und
 * fanoutConceptInvention schluckte das in einem nackten catch OHNE Log. Symptom: 0 Skizzen, kein
 * Fehler irgendwo, ein irreführender deckel_hinweis „die KI hat keine geliefert".
 *
 * Fix: (a) kiDivergenzConcept kappt den zusammengesetzten Block hart auf das Prompt-Key-Budget,
 * (b) fanoutConceptInvention loggt jeden Fehlschlag (KnowledgeBudgetExceeded separat erkennbar),
 * (c) der deckel_hinweis nennt den echten Grund statt „die KI hat keine geliefert" zu vermuten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    KnowledgeBudget::vergiss();
});

afterEach(fn () => KnowledgeBudget::vergiss());

/** Planung aus dem Trendradar mit 8 Trends à langer Definition — der Ursprungsblock (Spec 79) trägt
 *  damit allein ~1.500 Zeichen bei (ursprungsTrendBlock kappt selbst auf 1.500). */
function seedGrosseTrendPlanung(\Platform\Core\Models\Team $team): int
{
    $svc = app(\Platform\FoodAlchemist\Services\TrendService::class);
    $ids = [];
    foreach (range(1, 8) as $i) {
        $ids[] = $svc->anlegen($team, ['name' => "Grosser Trend {$i}", 'typ' => 'trend', 'ebene' => 'mega', 'kategorie' => 'food',
            'definition' => str_repeat('Fermentation ist ein starker Food-Trend. ', 8)])->id;
    }

    return (int) app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->ausTrendradar($team, $ids)->id;
}

it('(a) kiDivergenzConcept kappt den Wissensblock hart aufs Prompt-Key-Budget — kein KnowledgeBudgetExceeded mehr', function () {
    $sessionId = seedGrosseTrendPlanung($this->rootTeam);
    $concept = $this->makeConcept($this->rootTeam, 'Budget-Test-Konzept', ['status' => 'draft']);

    // Absichtlich winzig — kleiner als der alleinige Ursprungs-Trend-Beitrag (~1550 Zeichen). Vor dem
    // Fix hätte das REGELMÄSSIG KnowledgeBudgetExceeded geworfen (der Block wurde nie gegen dieses
    // Budget gekappt); jetzt wird er hart draufgeschnitten, propose() sieht nie mehr als das Budget.
    config(['foodalchemist.ai.knowledge_budget' => ['foodbook.kapitel_ideen' => 400]]);
    config(['foodalchemist.ai.provider' => 'fake']);

    expect(fn () => app(IdeenService::class)->kiDivergenzConcept($this->rootTeam, (int) $concept->id, 1, null, $sessionId))
        ->not->toThrow(KnowledgeBudgetExceeded::class);
});

it('(b) fanoutConceptInvention: KnowledgeBudgetExceeded wird geloggt und der Hinweis nennt den echten Grund', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Buffet-Budget', ['status' => 'draft']);
    $this->makeConceptSlot($concept, ['position' => 1]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $concept->id]);

    $this->mock(IdeenService::class, fn ($m) => $m->shouldReceive('kiDivergenzConcept')->once()
        ->andThrow(new KnowledgeBudgetExceeded('foodbook.kapitel_ideen', 19000, 16200)));
    Log::spy();

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $concept->id, 'voll_kreativ');

    $h = $run->refresh()->deckel_hinweise;
    expect($h)->toHaveCount(1)
        ->and($h[0]['text'])->toContain('Wissensbudget überschritten')
        ->and($h[0]['text'])->not->toContain('die KI hat keine geliefert');
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn ($message, $context) => str_contains($message, 'Wissensbudget überschritten') && ($context['concept_id'] ?? null) === (int) $concept->id);
});

it('(c) fanoutConceptInvention: ein anderer Ideen-Fehlschlag wird geloggt und der Hinweis nennt Klasse + Grund', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Buffet-Fehler', ['status' => 'draft']);
    $this->makeConceptSlot($concept, ['position' => 1]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $concept->id]);

    $this->mock(IdeenService::class, fn ($m) => $m->shouldReceive('kiDivergenzConcept')->once()
        ->andThrow(new \RuntimeException('Provider nicht erreichbar')));
    Log::spy();

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $concept->id, 'voll_kreativ');

    $h = $run->refresh()->deckel_hinweise;
    expect($h)->toHaveCount(1)
        ->and($h[0]['text'])->toContain('Ideen-Aufruf fehlgeschlagen')
        ->and($h[0]['text'])->toContain('RuntimeException')
        ->and($h[0]['text'])->toContain('Provider nicht erreichbar');
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn ($message, $context) => str_contains($message, 'kiDivergenzConcept fehlgeschlagen')
            && ($context['concept_id'] ?? null) === (int) $concept->id
            && ($context['exception'] ?? null) === \RuntimeException::class);
});

it('(regression) ein erfolgreicher Ideen-Call bleibt unveraendert — keine Lücke, kein Log', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Buffet-OK', ['status' => 'draft']);
    $this->makeConceptSlot($concept, ['position' => 1]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $concept->id]);
    $idee = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $concept->id, 'title' => 'Erfunden', 'status' => 'entwurf', 'target_form' => 'einzel', 'generation_status' => 'entwurf', 'position' => 1, 'created_via' => 'test']);

    $this->mock(IdeenService::class, fn ($m) => $m->shouldReceive('kiDivergenzConcept')->once()
        ->andReturn(['angelegt' => [$idee], 'roh' => 1, 'confidence' => 0.8, 'call_log_id' => null]));
    Log::spy();

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $concept->id, 'voll_kreativ');

    expect($run->refresh()->deckel_hinweise)->toBeEmpty();
    Log::shouldNotHaveReceived('warning');
});
