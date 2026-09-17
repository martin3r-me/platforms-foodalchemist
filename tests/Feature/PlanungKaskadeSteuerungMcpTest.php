<?php

use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Etappe 9 · Slice 2 (Roadmap »Mise en Place«) — MCP-Kaskaden-Steuerung START + FREIGABE, WRITE.
 *
 * Beweisziele:
 *  1. Registry-Smoke: beide Tools registriert + WRITE (nicht read-only).
 *  2. START legt für eine team-EIGENE Session einen Lauf an (running) + liefert den Status.
 *  3. START-Tenancy (Slice 4): eine geerbte Session (childA sieht Root-Session) → INHERITED, kein Lauf.
 *  4. FREIGABE gibt einen done-Schritt frei (→ freigegeben) + liefert den Status.
 *  5. FREIGABE-Tenancy: childA gibt einen childB-Schritt NICHT frei (ownedStep) → FREIGABE_FAILED.
 */
beforeEach(function () {
    Queue::fake();   // START dispatcht Generator-Jobs — im Test nicht real laufen lassen (provider-los)
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
});

it('Registry-Smoke: START + FREIGABE + SYNC sind registriert + WRITE', function () {
    foreach (['foodalchemist.planung_kaskade.START', 'foodalchemist.planung_kaskade.FREIGABE', 'foodalchemist.planung_kaskade.SYNC'] as $name) {
        $tool = $this->registry->get($name);
        expect($tool)->not->toBeNull()
            ->and($tool->getName())->toBe($name)
            ->and($tool->getMetadata()['read_only'])->toBeFalse()
            ->and($tool->getMetadata()['risk_level'])->toBe('write');
    }
});

it('START legt für eine team-eigene Session einen Kaskaden-Lauf an (running) + liefert den Status', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'MCP-Menü', 'brief' => 'Ein Teller.']);

    $r = $this->registry->get('foodalchemist.planung_kaskade.START')
        ->execute(['session_id' => (int) $session->id, 'scope' => 'gericht'], $this->ctx);

    expect($r->success)->toBeTrue()
        ->and($r->data['lauf']['scope'])->toBe('gericht')
        ->and($r->data['lauf']['status'])->toBe('running');
    expect(FoodAlchemistCascadeRun::where('planning_session_id', (int) $session->id)->count())->toBe(1);
});

it('START weist eine GEERBTE Session ab (Slice 4: isOwnedBy) → INHERITED, kein Lauf', function () {
    // Root besitzt die Session; childA sieht sie geerbt (visibleToTeam), besitzt sie NICHT.
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Root-Menü', 'brief' => 'x']);
    $childAUser = $this->makeUser($this->childA, 'Kind A');
    $ctxA = new ToolContext($childAUser, $this->childA);

    $r = $this->registry->get('foodalchemist.planung_kaskade.START')
        ->execute(['session_id' => (int) $session->id, 'scope' => 'gericht'], $ctxA);

    expect($r->success)->toBeFalse()
        ->and($r->errorCode)->toBe('INHERITED');
    expect(FoodAlchemistCascadeRun::where('planning_session_id', (int) $session->id)->count())->toBe(0);
});

it('FREIGABE gibt einen done-Schritt frei (→ freigegeben) + liefert den Status', function () {
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'review', 'staged' => false, 'brief' => 'x',
    ]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'done', 'label' => 'Sud', 'sort' => 1,   // ref_id null → keine Live-Setzung, nur freigegeben
    ]);

    $r = $this->registry->get('foodalchemist.planung_kaskade.FREIGABE')
        ->execute(['step_id' => (int) $step->id], $this->ctx);

    expect($r->success)->toBeTrue();
    expect($step->refresh()->status)->toBe('freigegeben');
});

it('FREIGABE-Tenancy: childA gibt einen childB-Schritt NICHT frei (ownedStep) → FREIGABE_FAILED', function () {
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->childB->id, 'scope' => 'rezept', 'status' => 'review', 'staged' => false, 'brief' => 'x',
    ]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->childB->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'done', 'label' => 'Fremd-Sud', 'sort' => 1,
    ]);
    $childAUser = $this->makeUser($this->childA, 'Kind A');
    $ctxA = new ToolContext($childAUser, $this->childA);

    $r = $this->registry->get('foodalchemist.planung_kaskade.FREIGABE')
        ->execute(['step_id' => (int) $step->id], $ctxA);

    expect($r->success)->toBeFalse()
        ->and($r->errorCode)->toBe('FREIGABE_FAILED');
    expect($step->refresh()->status)->toBe('done');   // unangetastet
});

/**
 * Nachtrag (Paket-E-Trace, Lauf 74/Step 513): ein GEPLANTER Nicht-Concept-Step (Sub-Rezept/erfundenes
 * Gericht) war über FREIGABE ein stiller No-op — nur `kind=concept` routete auf den Erzeugen-Pfad. Die
 * UI kennt denselben Fall längst über `erzeugeGeplant`.
 */
it('FREIGABE auf einen GEPLANTEN Sub-Rezept-Step (kind=rezept) erzeugt ihn (Gate 1), statt No-op', function () {
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true, 'brief' => 'x',
    ]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'geplant', 'label' => 'Schweinejus', 'sort' => 1,
    ]);

    $r = $this->registry->get('foodalchemist.planung_kaskade.FREIGABE')
        ->execute(['step_id' => (int) $step->id], $this->ctx);

    expect($r->success)->toBeTrue()
        ->and($r->data['aktion'] ?? null)->toBe('geplant_erzeugt')
        ->and($r->data['aktion_hinweis'] ?? null)->toBeNull();
    expect($step->refresh()->status)->toBe('running');
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipeJob::class, fn ($job) => (int) ($job->parameter['cascade_step_id'] ?? 0) === (int) $step->id);
});

it('FREIGABE auf einen bereits laufenden Step bleibt ein No-op — jetzt SICHTBAR ueber aktion+hinweis', function () {
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'running', 'staged' => false, 'brief' => 'x',
    ]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'running', 'label' => 'Laeuft schon', 'sort' => 1,
    ]);

    $r = $this->registry->get('foodalchemist.planung_kaskade.FREIGABE')
        ->execute(['step_id' => (int) $step->id], $this->ctx);

    expect($r->success)->toBeTrue()
        ->and($r->data['aktion'] ?? null)->toBe('no_op_status_running')
        ->and($r->data['aktion_hinweis'] ?? null)->toContain('running');
    expect($step->refresh()->status)->toBe('running');   // unangetastet
});
