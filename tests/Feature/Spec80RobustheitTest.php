<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Exceptions\PlanungAktionLaeuftBereitsException;
use Platform\FoodAlchemist\Jobs\ConformanceCheckJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\ConformanceService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\RecipeReviseService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 80 Teil C — Robustheit der Kaskade (Befunde aus demo Lauf #79 und dem Prüfbericht 09.10.). */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(PlanningCascadeService::class);
    $this->lauf = fn (array $extra = []) => FoodAlchemistCascadeRun::create($extra + [
        'team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'review',
    ]);
    $this->rezept = fn (string $name = 'Püree: Petersilie') => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'k' . md5($name . microtime()), 'name' => $name, 'status' => 'draft',
    ]);
});

it('C1: Neu generieren ist gesperrt, solange Prüfung oder Anreicherung am Entwurf arbeitet', function () {
    $run = ($this->lauf)();
    $r = ($this->rezept)();
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done',
        'ref_type' => 'recipe', 'ref_id' => $r->id, 'phase' => PlanningCascadeService::PHASE_KONFORMITAET,
    ]);

    expect(fn () => $this->svc->regeneriereStep($this->rootTeam, $step->id))->toThrow(PlanungAktionLaeuftBereitsException::class);
    expect(FoodAlchemistRecipe::find($r->id))->not->toBeNull();

    $step->update(['phase' => null, 'deferred' => ['enrich' => ['status' => 'running']]]);
    expect(fn () => $this->svc->regeneriereStep($this->rootTeam, $step->id))->toThrow(PlanungAktionLaeuftBereitsException::class);
});

it('C2: Neu generieren löscht nie ein übernommenes Bestandsrezept', function () {
    $run = ($this->lauf)();
    $bestand = ($this->rezept)('Püree: Petersilienwurzel');
    $bestand->update(['status' => 'approved']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'skipped',
        'ref_type' => 'recipe', 'ref_id' => $bestand->id,
    ]);

    $this->svc->regeneriereStep($this->rootTeam, $step->id);

    expect(FoodAlchemistRecipe::find($bestand->id))->not->toBeNull()
        ->and($step->fresh()->status)->toBe('skipped');
});

it('C5: ein abgebrochener Lauf springt durch späte Rückmeldungen nicht zurück auf „Zu prüfen"', function () {
    $run = ($this->lauf)(['status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'running',
    ]);
    expect($this->svc->brecheLaufAb($this->rootTeam, $run->id))->toBeTrue();

    $step->update(['status' => 'done']);          // späte Rückmeldung eines nicht stoppbaren Provider-Calls
    $this->svc->recomputeRunStatus($run->id);

    expect($run->fresh()->status)->toBe('failed');
});

it('C1: der Prüf-Job hört still auf, wenn der Step inzwischen zu einem anderen Rezept gehört', function () {
    $run = ($this->lauf)();
    $alt = ($this->rezept)();
    $neu = ($this->rezept)('Püree: Petersilie (neu)');
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'running',
        'ref_type' => 'recipe', 'ref_id' => $neu->id, 'phase' => 'Rezept wird erzeugt …',
    ]);
    $this->mock(ConformanceService::class)->shouldNotReceive('pruefeUndHeile');

    (new ConformanceCheckJob($this->rootTeam->id, auth()->id(), 'basisrezept', $alt->id, null, $step->id))
        ->handle(app(ConformanceService::class));

    expect($step->fresh()->phase)->toBe('Rezept wird erzeugt …');   // Phase des neuen Versuchs bleibt
});

it('C3: Heilung lässt Verweiszeilen-Text stehen und löst eine umbenannte Grundprodukt-Zuordnung', function () {
    $r = ($this->rezept)();
    $sub = ($this->rezept)('Garnitur: Kräutermatte Petersilie (Vegan)');
    $wuerfel = $this->makeGp($this->rootTeam, 'Schalotten: frisch, Wuerfel 5 mm');
    $butter = $this->makeGp($this->rootTeam, 'Butter: frisch');
    $g = $this->unitG($this->rootTeam)->id;
    $verweis = FoodAlchemistRecipeIngredient::create(['team_id' => $r->team_id, 'recipe_id' => $r->id, 'referenced_recipe_id' => $sub->id, 'raw_text' => $sub->name, 'quantity' => '250', 'unit_vocab_id' => $g, 'position' => 1]);
    $schalotte = FoodAlchemistRecipeIngredient::create(['team_id' => $r->team_id, 'recipe_id' => $r->id, 'gp_id' => $wuerfel->id, 'raw_text' => $wuerfel->name, 'quantity' => '300', 'unit_vocab_id' => $g, 'position' => 2]);
    $fett = FoodAlchemistRecipeIngredient::create(['team_id' => $r->team_id, 'recipe_id' => $r->id, 'gp_id' => $butter->id, 'raw_text' => $butter->name, 'quantity' => '60', 'unit_vocab_id' => $g, 'position' => 3]);

    $zeilen = app(RecipeReviseService::class)->syncZeilen($r->fresh('ingredients'), [
        ['id' => $verweis->id, 'text' => 'Gel: Kräutermatte Petersilie (vegan)', 'quantity' => 250, 'einheit_slug' => 'g'],
        ['id' => $schalotte->id, 'text' => 'Schalotten: frisch, ganz', 'quantity' => 300, 'einheit_slug' => 'g'],
        ['id' => $fett->id, 'text' => 'Butter: frisch', 'quantity' => 80, 'einheit_slug' => 'g'],
    ]);
    $je = collect($zeilen)->keyBy('id');

    expect($je[$verweis->id]['raw_text'])->toBe($sub->name)
        ->and($je[$verweis->id]['referenced_recipe_id'])->toBe($sub->id)
        ->and($je[$schalotte->id]['gp_id'])->toBeNull()                  // umbenannt → neu zuordnen
        ->and($je[$fett->id]['gp_id'])->toBe($butter->id)                 // nur Menge geändert → bleibt
        ->and((float) $je[$fett->id]['quantity'])->toBe(80.0);
});

it('C7: Stufe nur aus gescheiterten Schritten heißt „fehlgeschlagen", nicht „erledigt"', function () {
    $inst = Livewire::test(PlanungIndex::class)->instance();
    $stufen = $inst->stufenAusSteps(collect([
        (object) ['kind' => 'rezept', 'status' => 'failed', 'deferred' => null],
    ]));

    expect($stufen[0]['zustand'])->toBe('fehlgeschlagen')
        ->and($stufen[0]['fertig'])->toBe(0);
});

it('C4: „keine Diät" aus dem Briefing löscht eine vorher gesetzte Diät', function () {
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    app()->bind(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['leitplanken' => ['level' => 'gehoben', 'diaet_hart' => []]], 'confidence' => 0.8, 'reasoning' => 'stub']),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'fake', 'tool_calls' => null];
        }
    });

    $c = Livewire::test(PlanungIndex::class)
        ->set('regler.gericht.diaet_hart', ['vegetarisch'])
        ->set('eingabe.gericht.brief', 'Abendessen, keine Diät-Vorgaben')
        ->call('leitplankenAusBriefing', 'gericht');

    expect($c->get('regler.gericht.diaet_hart'))->toBe([]);
});
