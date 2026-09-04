<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** A1: Eine Buffet-STATION (≥2 Positionen) wird ein PAKET (kind=paket) mit Header + N inneren Gerichten,
 *  eingebettet als EIN Slot ins Haupt-Concept; der Fan-out fächert die INNEREN Gerichte rekursiv. */

$mkIdee = function (int $teamId, int $conceptId, string $t): FoodAlchemistDishIdea {
    return FoodAlchemistDishIdea::create([
        'team_id' => $teamId, 'concept_id' => $conceptId, 'title' => $t, 'status' => 'entwurf',
        'target_form' => 'einzel', 'generation_status' => 'entwurf', 'position' => 1, 'created_via' => 'test',
    ]);
};

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
});

// ── materialisiereLeereSlots: Station → Paket (via Reflection auf die private Methode) ──────────────

it('materialisiereLeereSlots: eine Station (target_count>=2) wird ein Paket mit Header + N inneren Gerichten', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Business-Lunch', ['status' => 'draft']);
    $frameSvc = app(PlanningFrameService::class);
    $frame = $frameSvc->frameFor($this->rootTeam, 'concept', (int) $concept->id);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Bowl-Station', 'slot_type' => 'station', 'target_count' => 3]);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Dessert', 'slot_type' => 'gang', 'target_count' => 1]);

    $svc = app(ConceptGeneratorService::class);
    $m = new ReflectionMethod($svc, 'materialisiereLeereSlots');
    $m->setAccessible(true);
    $m->invoke($svc, $this->rootTeam, $concept->fresh(), FoodAlchemistPlanningFrame::find($frame->id));

    // Haupt-Concept: EIN Paket-Slot (Station) + EIN flacher Gang-Slot (Dessert)
    $slots = FoodAlchemistConceptSlot::where('concept_id', $concept->id)->orderBy('position')->get();
    expect($slots)->toHaveCount(2);
    $paketSlot = $slots->firstWhere('type', 'paket');
    $gangSlot = $slots->firstWhere('role', 'Dessert');
    expect($paketSlot)->not->toBeNull()
        ->and((int) $paketSlot->embedded_concept_id)->toBeGreaterThan(0)
        ->and($paketSlot->role)->toBe('Bowl-Station')
        ->and($gangSlot)->not->toBeNull()
        ->and($gangSlot->embedded_concept_id)->toBeNull();

    // Das eingebettete Paket-Concept: kind=paket, 1 Header (Stationsname) + 3 leere Gericht-Slots
    $paket = \Platform\FoodAlchemist\Models\FoodAlchemistConcept::find($paketSlot->embedded_concept_id);
    expect($paket->kind)->toBe('paket')->and($paket->name)->toBe('Bowl-Station');
    $paketSlots = FoodAlchemistConceptSlot::where('concept_id', $paket->id)->get();
    expect($paketSlots->where('type', 'header'))->toHaveCount(1)
        ->and($paketSlots->firstWhere('type', 'header')->title)->toBe('Bowl-Station')
        ->and($paketSlots->whereNull('embedded_concept_id')->whereNull('sales_recipe_id')->where('type', '!=', 'header'))->toHaveCount(3);
});

it('materialisiereLeereSlots: eine Einzel-Station (target_count=1) bleibt flach (kein Paket)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Empfang', ['status' => 'draft']);
    $frameSvc = app(PlanningFrameService::class);
    $frame = $frameSvc->frameFor($this->rootTeam, 'concept', (int) $concept->id);
    $frameSvc->addSlot($this->rootTeam, $frame, ['label' => 'Signature', 'slot_type' => 'station', 'target_count' => 1]);

    $svc = app(ConceptGeneratorService::class);
    $m = new ReflectionMethod($svc, 'materialisiereLeereSlots');
    $m->setAccessible(true);
    $m->invoke($svc, $this->rootTeam, $concept->fresh(), FoodAlchemistPlanningFrame::find($frame->id));

    $slots = FoodAlchemistConceptSlot::where('concept_id', $concept->id)->get();
    expect($slots)->toHaveCount(1)->and($slots->first()->type)->not->toBe('paket');
});

// ── fanoutConceptInvention: Paket-Slot ist NICHT leer + innere Gerichte werden rekursiv gefächert ──

it('fanout: ein eingebetteter Paket-Slot gilt NICHT als leer — die INNEREN Gerichte werden gefächert', function () use ($mkIdee) {
    $concept = $this->makeConcept($this->rootTeam, 'Buffet', ['status' => 'draft']);
    $paket = app(ConceptService::class)->createPaket($this->rootTeam, ['name' => 'Bowl-Station']);
    $innerA = $this->makeConceptSlot($paket, ['position' => 1, 'role' => 'Bowl-Station']);
    $innerB = $this->makeConceptSlot($paket, ['position' => 2, 'role' => 'Bowl-Station']);
    // Header im Paket (darf NICHT als leer zählen)
    app(ConceptService::class)->addBlock($this->rootTeam, (int) $paket->id, 'header', ['title' => 'Bowl-Station']);
    // Aussen NUR der Paket-Slot (kein direktes Gericht)
    $this->makeConceptSlot($concept, ['position' => 1, 'type' => 'paket', 'embedded_concept_id' => $paket->id]);

    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $concept->id]);

    $i1 = $mkIdee($this->rootTeam->id, (int) $paket->id, 'Fisch-Bowl');
    $i2 = $mkIdee($this->rootTeam->id, (int) $paket->id, 'Beilagen-Salat');
    // Divergenz wird GENAU EINMAL gefragt — für das PAKET (anzahl=2), nicht fürs Aussen-Concept (0 leere)
    $this->mock(IdeenService::class, fn ($m) => $m->shouldReceive('kiDivergenzConcept')->once()
        ->withArgs(fn ($team, $cid, $n, $e = null, $t = null) => $cid === (int) $paket->id && $n === 2)
        ->andReturn(['angelegt' => [$i1, $i2], 'roh' => 2, 'confidence' => 0.8, 'call_log_id' => null]));

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $concept->id, 'voll_kreativ');

    // Zwei Gericht-Steps (für die inneren Paket-Slots), Ideen an die INNEREN Slots gehängt
    expect($run->steps()->where('kind', 'gericht')->count())->toBe(2)
        ->and((int) ($i1->refresh()->source_meta['target_concept_slot_id'] ?? 0))->toBe((int) $innerA->id)
        ->and((int) ($i2->refresh()->source_meta['target_concept_slot_id'] ?? 0))->toBe((int) $innerB->id);
    // KORREKTUR 2026-09-03: hier stand `assertPushed(MaterializeConceptIdeaJob, 2)`. Der
    // Fan-out dispatcht seit dem VORSCHLAG-GATE (ff91d522, 2026-09-01) NICHTS mehr — er legt
    // pro Idee nur einen Bauplan im Status `geplant` an; materialisiert wird erst, wenn der
    // Mensch klickt (erzeugeGeplantenStep). Der Test ist zwei Tage ÄLTER als das Gate und
    // beschreibt die Welt davor.
    //
    // Der Ersatz macht ihn nicht bloss grün, er behält die AUSSAGE: jeder innere Slot wurde
    // bedient UND ist bedienbar. Der zweite Teil hängt am `dish_idea_id`-Griff im
    // context_snapshot — ohne ihn rendert die Karte nicht und erzeugeGeplantenStep fällt
    // still durch, der Vorschlag wäre also eine unerreichbare Sackgasse. Genau diese
    // Regression hat das alte assertPushed gefangen; der Status allein belegt sie NICHT.
    $innere = $run->steps()->where('kind', 'gericht')->get();
    expect($innere->pluck('status')->unique()->all())->toBe(['geplant'])
        ->and($innere->map(fn ($s) => (int) (($s->context_snapshot ?? [])['dish_idea_id'] ?? 0))->sort()->values()->all())
        ->toBe(collect([(int) $i1->id, (int) $i2->id])->sort()->values()->all());
    Queue::assertNotPushed(\Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob::class);
});

it('fanout mixed: direktes Gericht + Paket-Station → beide gefächert (1 + 2 innere)', function () use ($mkIdee) {
    $concept = $this->makeConcept($this->rootTeam, 'Buffet', ['status' => 'draft']);
    $direkt = $this->makeConceptSlot($concept, ['position' => 1, 'role' => 'Suppe']);   // leeres direktes Gericht
    $paket = app(ConceptService::class)->createPaket($this->rootTeam, ['name' => 'Bowl-Station']);
    $this->makeConceptSlot($paket, ['position' => 1, 'role' => 'Bowl-Station']);
    $this->makeConceptSlot($paket, ['position' => 2, 'role' => 'Bowl-Station']);
    $this->makeConceptSlot($concept, ['position' => 2, 'type' => 'paket', 'embedded_concept_id' => $paket->id]);

    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $concept->id]);

    $this->mock(IdeenService::class, function ($m) use ($mkIdee, $concept, $paket) {
        $m->shouldReceive('kiDivergenzConcept')
            ->withArgs(fn ($team, $cid, $n, $e = null, $t = null) => $cid === (int) $concept->id)
            ->andReturn(['angelegt' => [$mkIdee($this->rootTeam->id, (int) $concept->id, 'Suppe')], 'roh' => 1, 'confidence' => 0.8, 'call_log_id' => null]);
        $m->shouldReceive('kiDivergenzConcept')
            ->withArgs(fn ($team, $cid, $n, $e = null, $t = null) => $cid === (int) $paket->id)
            ->andReturn(['angelegt' => [$mkIdee($this->rootTeam->id, (int) $paket->id, 'Bowl'), $mkIdee($this->rootTeam->id, (int) $paket->id, 'Salat')], 'roh' => 2, 'confidence' => 0.8, 'call_log_id' => null]);
    });

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $concept->id, 'voll_kreativ');

    expect($run->steps()->where('kind', 'gericht')->count())->toBe(3);   // 1 direkt + 2 innere

    // Gleiche Wurzel wie oben (Vorschlag-Gate ff91d522). Die Ideen entstehen hier anonym in
    // der Mock-Closure, sind also nicht namentlich vergleichbar — darum die Existenz-Form
    // über alle drei. Sie genügt: ein Zweig ohne Griff macht den Test rot.
    $alle = $run->steps()->where('kind', 'gericht')->get();
    expect($alle->pluck('status')->unique()->all())->toBe(['geplant'])
        ->and($alle->filter(fn ($s) => (int) (($s->context_snapshot ?? [])['dish_idea_id'] ?? 0) > 0))->toHaveCount(3);
    Queue::assertNotPushed(\Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob::class);
});
