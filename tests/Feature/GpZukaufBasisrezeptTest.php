<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\BuildZukaufRecipeJob;
use Platform\FoodAlchemist\Livewire\Gps\ZukaufRezept;
use Platform\FoodAlchemist\Livewire\Planung\ZukaufWare;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\ZukaufBasisrezeptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Zukauf-Basisrezept aus der Planung (Dominique 13.11.): Knopf am GP → vorbefüllte Planung (Reiter Basisrezept) →
 * normaler Go → Zukauf-Weg mit fest gesetzter Ware. Kein Doppel, kein WG-Filter außer Non-Food, Rezepte mit dem GP
 * bleiben unberührt; derselbe Einstieg per „Zukauf-Ware wählen“ und per MCP.
 */
beforeEach(function () {
    Queue::fake();
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->unitG($this->rootTeam);
    app()->bind(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, fn () => new class extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => [
                'schritte' => ['Ware aus der Verpackung in GN-Behälter füllen.', 'Kurz vor dem Service portionieren.'],
                'hilfsstoffe' => [],
            ], 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'fake', 'tool_calls' => null];
        }
    });
    $this->gpMit = fn (\Platform\Core\Models\Team $team, string $name, string $wg, ?string $sub = null) => tap($this->makeGp($team, $name))
        ->update(['commodity_group_code' => $wg, 'sub_category' => $sub]);
    $this->regelAn = function () {
        $regel = \Platform\FoodAlchemist\Models\FoodAlchemistRule::where('schluessel', 'basisrezept.zukauf.typ')->firstOrFail();
        app(\Platform\FoodAlchemist\Services\Regeln\RegelService::class)->setzeAktiv($regel->id, true);
    };
    $this->zukaufZahl = fn () => FoodAlchemistRecipe::where('name', 'like', '%(Zukauf)')->count();
});

it('Knopf am GP (auch Rohware-WG) öffnet die Planung mit vorbefüllter Session — kein Rezept', function () {
    $gp = ($this->gpMit)($this->rootTeam, 'Erbsensprossen: frisch', '01', '01.3 Blattgemuese');

    $lw = Livewire::test(ZukaufRezept::class, ['gpId' => $gp->id])
        ->assertSee('Zukauf-Basisrezept planen')
        ->call('planen');

    $s = FoodAlchemistPlanningSession::where('source_gp_id', $gp->id)->firstOrFail();
    $lw->assertRedirect(route('foodalchemist.planung.index', ['session' => $s->id, 'open' => 1, 'tab' => 'basisrezept']));
    expect($s->title)->toBe('Zukauf-Basisrezept: Erbsensprossen: frisch')
        ->and($s->created_via)->toBe('gp')
        ->and((int) $s->team_id)->toBe((int) $this->rootTeam->id)
        ->and($s->brief)->toContain('Erbsensprossen: frisch')->toContain('nicht nachgekocht')
        ->and($s->zukauf_typ)->toBeNull()                    // Regel aus, kein Vorschlag → im Reiter wählen, nie raten
        ->and(($this->zukaufZahl)())->toBe(0);

    // Noch einmal: dieselbe offene Planung, keine zweite
    Livewire::test(ZukaufRezept::class, ['gpId' => $gp->id])->call('planen');
    expect(FoodAlchemistPlanningSession::where('source_gp_id', $gp->id)->count())->toBe(1);
});

it('Go baut über den Zukauf-Weg mit fest gesetzter Ware; danach Link am GP, Rezepte mit dem GP unverändert', function () {
    ($this->regelAn)();
    $gp = ($this->gpMit)($this->rootTeam, 'Kraeutercrumble gruen Jorda: trocken, Topping', '10', '10.4 Gewuerzmischungen');
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Kabeljau', ['status' => 'draft', 'is_sales_recipe' => true]);
    $zeile = $this->makeIngredient($gericht, 'Kräutercrumble', $gp, '20', 1);

    $e = app(PlanningSessionService::class)->zukaufAusGp($this->rootTeam, (int) $gp->id);
    expect($e['typ'])->toBe('Crunch')->and($e['typ_vorschlag'])->toBe('Crunch')->and($e['neu'])->toBeTrue();
    $session = $e['session'];

    // Go wie die Oberfläche (plan_first) — der Zukauf-Weg gewinnt, kein Komponenten-Plan, kein Generator
    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'rezept', $session, 'voll_kreativ', ['plan_first' => true, 'created_via' => 'plan_go']);
    Queue::assertNotPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::class);
    Queue::assertNotPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipeJob::class);
    $job = null;
    Queue::assertPushed(BuildZukaufRecipeJob::class, function ($j) use ($gp, &$job) {
        $job = $j;

        return $j->ware['gp_id'] === (int) $gp->id && $j->ware['typ'] === 'Crunch' && $j->ware['bezeichnung'] === 'Kraeutercrumble gruen Jorda';
    });
    $step = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->sole();
    expect($step->parent_step_id)->toBeNull()->and($run->planning_session_id)->toBe($session->id);

    $job->handle(app(ZukaufBasisrezeptService::class), app(PlanningCascadeService::class), app(RecipeDependencyWorkflowService::class));

    $step->refresh();
    $r = FoodAlchemistRecipe::findOrFail($step->ref_id);
    expect($step->status)->toBe('done')->and($step->error)->toBeNull()
        ->and($r->name)->toBe('Crunch: Kraeutercrumble gruen Jorda (Zukauf)')
        ->and($r->is_sales_recipe)->toBeFalse()
        ->and($r->production_depth)->toBe('convenience')
        ->and($r->ingredients()->pluck('gp_id')->map(fn ($v) => (int) $v)->all())->toBe([(int) $gp->id])
        ->and($r->steps()->count())->toBe(2)
        ->and($session->fresh()->status)->toBe('konvergenz')       // Lineage wie GenerateRecipeJob
        ->and((int) $zeile->fresh()->gp_id)->toBe((int) $gp->id)  // Rezepte mit dem GP NICHT umgestellt
        ->and($zeile->fresh()->referenced_recipe_id)->toBeNull();

    Livewire::test(ZukaufRezept::class, ['gpId' => $gp->id])
        ->assertSee('Zukauf-Basisrezept:')->assertSee($r->name)->assertDontSee('Zukauf-Basisrezept planen');

    // Kein Doppel: weder neue Session noch zweiter Lauf
    $nochmal = app(PlanningSessionService::class)->zukaufAusGp($this->rootTeam, (int) $gp->id);
    expect($nochmal['session'])->toBeNull()->and((int) $nochmal['recipe']->id)->toBe((int) $r->id);
    expect(fn () => app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'rezept', $session->fresh(), 'voll_kreativ'))
        ->toThrow(\RuntimeException::class, 'schon das Zukauf-Basisrezept');
});

it('Go ohne Typ: Fehler mit Hinweis statt raten, kein Rumpf-Lauf; Typ im Reiter gewählt → Go läuft', function () {
    $gp = ($this->gpMit)($this->rootTeam, 'Gulasch Rind: TK, fertig', '13', '13.1 Fertiggerichte');
    $session = app(PlanningSessionService::class)->zukaufAusGp($this->rootTeam, (int) $gp->id)['session'];

    expect(fn () => app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'rezept', $session, 'voll_kreativ'))
        ->toThrow(\RuntimeException::class, 'fehlt der Typ');
    expect(FoodAlchemistCascadeRun::where('planning_session_id', $session->id)->count())->toBe(0);

    Livewire::test(ZukaufWare::class, ['sessionId' => $session->id])
        ->assertSee('Gulasch Rind: TK, fertig')->assertSee('bitte wählen')
        ->set('typ', 'Crunch')->assertSet('fehler', null);
    expect($session->fresh()->zukauf_typ)->toBe('Crunch');

    app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'rezept', $session->fresh(), 'voll_kreativ');
    Queue::assertPushed(BuildZukaufRecipeJob::class, fn ($j) => $j->ware['typ'] === 'Crunch' && $j->ware['gp_id'] === (int) $gp->id);

    expect(fn () => app(PlanningSessionService::class)->setzeZukaufTyp($this->rootTeam, $session->id, 'Gibtsnicht'))
        ->toThrow(\RuntimeException::class, 'Unbekannter Basisrezept-Typ');
});

it('vorhandenes Zukauf-Basisrezept (auch aus der Kaskade): Link statt Knopf, keine Session', function () {
    $gp = ($this->gpMit)($this->rootTeam, 'Kuerbiskernoel: trocken', '11', '11.1 Oel');
    $vorhanden = $this->makeRecipe($this->rootTeam, 'Öl: Kürbiskern (Zukauf)');
    $this->makeIngredient($vorhanden, 'Kürbiskernöl', $gp, '1000', 1);

    Livewire::test(ZukaufRezept::class, ['gpId' => $gp->id])
        ->assertSee('Öl: Kürbiskern (Zukauf)')->assertDontSee('Zukauf-Basisrezept planen');
    Livewire::test(ZukaufWare::class)->call('sucheUmschalten')->set('suche', 'Kuerbiskern')
        ->assertSee('Kuerbiskernoel: trocken')->call('waehle', $gp->id)
        ->assertNoRedirect()->assertSet('vorhanden', ['id' => (int) $vorhanden->id, 'name' => 'Öl: Kürbiskern (Zukauf)']);
    expect(FoodAlchemistPlanningSession::where('source_gp_id', $gp->id)->count())->toBe(0);
});

it('„Zukauf-Ware wählen“ im Reiter Basisrezept: GP-Suche ohne Non-Food, Auswahl öffnet die Zukauf-Planung', function () {
    $gp = ($this->gpMit)($this->rootTeam, 'Microgreens Rotkohl: frisch', '01', '01.8 Sprossen');
    ($this->gpMit)($this->rootTeam, 'Microgreens Schale: Kunststoff', '16');

    $lw = Livewire::test(ZukaufWare::class)->assertSee('Zukauf-Ware wählen')
        ->call('sucheUmschalten')->set('suche', 'Microgreens')
        ->assertSee('Microgreens Rotkohl: frisch')->assertDontSee('Microgreens Schale: Kunststoff')
        ->call('waehle', $gp->id);
    $s = FoodAlchemistPlanningSession::where('source_gp_id', $gp->id)->sole();
    $lw->assertRedirect(route('foodalchemist.planung.index', ['session' => $s->id, 'open' => 1, 'tab' => 'basisrezept']));
});

it('Non-Food und fremder GP: kein Knopf, Einstieg lehnt ab', function () {
    $nonFood = ($this->gpMit)($this->rootTeam, 'Servietten: weiss', '16');
    $fremd = ($this->gpMit)($this->childB, 'Roestzwiebeln: trocken, frittiert', '10', '10.4 Gewuerzmischungen');

    foreach ([$nonFood, $fremd] as $gp) {
        Livewire::test(ZukaufRezept::class, ['gpId' => $gp->id])->assertDontSee('Zukauf-Basisrezept planen')->assertDontSee('Zukauf-Basisrezept:');
    }
    expect(fn () => app(PlanningSessionService::class)->zukaufAusGp($this->rootTeam, (int) $nonFood->id))->toThrow(\RuntimeException::class, 'nur für Lebensmittel')
        ->and(fn () => app(PlanningSessionService::class)->zukaufAusGp($this->rootTeam, (int) $fremd->id))->toThrow(\RuntimeException::class, 'nicht gefunden')
        ->and(FoodAlchemistPlanningSession::whereNotNull('source_gp_id')->count())->toBe(0);
});

it('MCP planung_session.POST mit gp_id: Session + Typ + nächste Schritte; Dublette, fremder GP, Non-Food', function () {
    ($this->regelAn)();
    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $gp = ($this->gpMit)($this->rootTeam, 'Roestzwiebeln: trocken, frittiert', '10', '10.4 Gewuerzmischungen');

    $r = $registry->get('foodalchemist.planung_session.POST')->execute(['gp_id' => $gp->id], $ctx);
    expect($r->success)->toBeTrue()
        ->and($r->data['zukauf'])->toBe(['gp_id' => (int) $gp->id, 'typ' => 'Crunch', 'typ_vorschlag' => 'Crunch'])
        ->and($r->data['neu'])->toBeTrue()
        ->and(implode(' ', $r->data['naechste_schritte']))->toContain('foodalchemist.planung_kaskade.START')->toContain('scope=rezept');
    expect(FoodAlchemistPlanningSession::find($r->data['session_id'])->created_via)->toBe('mcp_gp');

    // Dublette: vorhandenes Zukauf-Basisrezept → keine Session, recipe_id
    $roh = ($this->gpMit)($this->rootTeam, 'Kuerbiskernoel: trocken', '11', '11.1 Oel');
    $vorhanden = $this->makeRecipe($this->rootTeam, 'Öl: Kürbiskern (Zukauf)');
    $this->makeIngredient($vorhanden, 'Kürbiskernöl', $roh, '1000', 1);
    $d = $registry->get('foodalchemist.planung_session.POST')->execute(['gp_id' => $roh->id], $ctx);
    expect($d->success)->toBeTrue()->and($d->data['zukauf_basisrezept_vorhanden'])->toBeTrue()->and($d->data['recipe_id'])->toBe((int) $vorhanden->id);

    $fremd = ($this->gpMit)($this->childB, 'Senf: mittelscharf', '13');
    $f = $registry->get('foodalchemist.planung_session.POST')->execute(['gp_id' => $fremd->id], $ctx);
    expect($f->success)->toBeFalse()->and($f->errorCode)->toBe('NOT_FOUND');

    $nf = $registry->get('foodalchemist.planung_session.POST')->execute(['gp_id' => ($this->gpMit)($this->rootTeam, 'Servietten: weiss', '16')->id], $ctx);
    expect($nf->success)->toBeFalse()->and($nf->errorCode)->toBe('VALIDATION_ERROR');
    expect(FoodAlchemistPlanningSession::whereNotNull('source_gp_id')->count())->toBe(1);
});

it('GP-Dialog, Detail-Panel und Planung binden die Blöcke ein', function () {
    $gp = ($this->gpMit)($this->rootTeam, 'Roestzwiebeln: trocken, frittiert', '10', '10.4 Gewuerzmischungen');

    Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\DetailPanel::class, ['gpId' => $gp->id])->assertSee('Zukauf-Basisrezept planen');
    Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\GpModal::class)->call('oeffnen', $gp->id)->assertSee('Zukauf-Basisrezept planen');
    $frei = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Freies Basisrezept']);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Planung\Index::class)->call('oeffne', $frei->id, 'basisrezept')->assertSee('Zukauf-Ware wählen');
    $zk = app(PlanningSessionService::class)->zukaufAusGp($this->rootTeam, (int) $gp->id)['session'];
    Livewire::test(\Platform\FoodAlchemist\Livewire\Planung\Index::class)->call('oeffne', $zk->id, 'basisrezept')
        ->assertSeeHtml('data-planung-zukauf-ware')->assertSeeHtml('data-planung-zukauf-typ');
});
