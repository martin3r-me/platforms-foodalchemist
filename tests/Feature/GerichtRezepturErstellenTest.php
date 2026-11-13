<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\StubAusrezeptierenJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Livewire\Verkauf\RezepturErstellen;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\ConceptVorgabenService;
use Platform\FoodAlchemist\Services\GerichtHuelleService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Rezeptur erstellen" für ein vorhandenes Gericht (Dominique 13.11.2026): ein Gericht im Entwurf ohne Komponenten
 * (Hülle) wird zum Ziel der Planung. Der Bau schreibt in die Hülle, statt ein zweites Gericht anzulegen.
 */
const HUELLE_NAME = '[HG] Gezupftes Kikok-Hähnchen | Frikassee-Espuma | Buchweizen-Gemüse-Salat';
const HUELLE_TEXT = 'Hauptgang NOSE TO TAIL, Komponenten getrennt angerichtet. Kikok ganz pochiert und gezupft.';

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    Queue::fake();
    $this->svc = app(GerichtHuelleService::class);
    $this->huelle = fn (string $name = HUELLE_NAME, array $attrs = []) => $this->makeRecipe($this->rootTeam, $name,
        $attrs + ['is_sales_recipe' => true, 'status' => 'draft', 'description' => HUELLE_TEXT, 'n_ingredients_total' => 0]);
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->g = $this->unitG($this->rootTeam);
    $this->zutat = fn (FoodAlchemistRecipe $r) => FoodAlchemistRecipeIngredient::create(['team_id' => $r->team_id, 'recipe_id' => $r->id,
        'position' => 1, 'raw_text' => 'Hähnchen', 'display_name' => 'Hähnchen', 'quantity' => 100, 'unit_vocab_id' => $this->g->id]);
});

// ── Was eine Hülle ist ──────────────────────────────────────────────────────

it('Hülle: Gericht des eigenen Teams, Entwurf, ohne Komponenten — sonst nichts', function () {
    $huelle = ($this->huelle)();
    expect(GerichtHuelleService::huelle($this->rootTeam, $huelle->id)?->id)->toBe($huelle->id);

    $freigegeben = ($this->huelle)('[HG] Freigegeben', ['status' => 'approved']);
    $basis = ($this->huelle)('Fond: Geflügel', ['is_sales_recipe' => false]);
    $mitZutat = ($this->huelle)('[HG] Hat Komponenten');
    ($this->zutat)($mitZutat);

    expect(GerichtHuelleService::huelle($this->rootTeam, $freigegeben->id))->toBeNull()   // Bestand bleibt unangetastet
        ->and(GerichtHuelleService::huelle($this->rootTeam, $basis->id))->toBeNull()
        ->and(GerichtHuelleService::huelle($this->rootTeam, $mitZutat->id))->toBeNull()
        ->and(GerichtHuelleService::huelle($this->childA, $huelle->id))->toBeNull();          // Kind-Team sieht es, besitzt es aber nicht
});

// ── Einstieg: Menüpunkt am Gericht → vorbefüllte Planung ────────────────────

it('sessionFuer: Planung mit Name als Titel, Beschreibung als Briefing und dem Gericht als Ziel; eine offene wird wiederverwendet', function () {
    $huelle = ($this->huelle)();

    $session = $this->svc->sessionFuer($this->rootTeam, $huelle->id);
    expect($session->title)->toBe(HUELLE_NAME)->and($session->brief)->toBe(HUELLE_TEXT)
        ->and((int) $session->target_recipe_id)->toBe((int) $huelle->id)->and($session->created_via)->toBe('gericht');

    expect($this->svc->sessionFuer($this->rootTeam, $huelle->id)->id)->toBe($session->id)
        ->and(FoodAlchemistPlanningSession::where('target_recipe_id', $huelle->id)->count())->toBe(1);
});

it('sessionFuer: nicht für geerbte, freigegebene oder schon ausrezeptierte Gerichte', function () {
    $huelle = ($this->huelle)();
    $freigegeben = ($this->huelle)('[HG] Freigegeben', ['status' => 'approved']);

    expect(fn () => $this->svc->sessionFuer($this->childA, $huelle->id))->toThrow(RuntimeException::class, 'Besitzer-Team');
    expect(fn () => $this->svc->sessionFuer($this->rootTeam, $freigegeben->id))->toThrow(RuntimeException::class, 'Entwurf ohne Komponenten');
    expect(fn () => $this->svc->sessionFuer($this->rootTeam, 999999))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(FoodAlchemistPlanningSession::whereNotNull('target_recipe_id')->count())->toBe(0);
});

it('Menüpunkt: nur für die Hülle sichtbar, „planen" öffnet die Leitstelle im Reiter Gericht', function () {
    $huelle = ($this->huelle)();
    $fertig = ($this->huelle)('[HG] Hat Komponenten');
    ($this->zutat)($fertig);

    Livewire::test(RezepturErstellen::class, ['recipeId' => $fertig->id])->assertDontSeeHtml('data-vk-rezeptur-knopf');

    $c = Livewire::test(RezepturErstellen::class, ['recipeId' => $huelle->id])->assertSeeHtml('data-vk-rezeptur-knopf')->call('planen');
    $session = FoodAlchemistPlanningSession::where('target_recipe_id', $huelle->id)->firstOrFail();
    $c->assertRedirect(route('foodalchemist.planung.index', ['session' => $session->id, 'open' => 1, 'tab' => 'gericht']));
});

it('Leitstelle: Titel und Briefing des Gericht-Reiters kommen aus der Planung, der Hinweis nennt das Ziel', function () {
    $huelle = ($this->huelle)();
    $session = $this->svc->sessionFuer($this->rootTeam, $huelle->id);

    Livewire::withQueryParams(['session' => $session->id, 'open' => 1, 'tab' => 'gericht'])->test(PlanungIndex::class)
        ->assertSet('eingabe.gericht.titel', HUELLE_NAME)
        ->assertSet('eingabe.gericht.brief', HUELLE_TEXT)
        ->assertSeeHtml('data-planung-rezeptur-ziel')
        ->assertSee('Es entsteht kein neues Gericht');
});

it('Leitstelle: eine Planung ohne Ziel-Gericht bleibt wie sie war', function () {
    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Frei', 'brief' => 'Irgendwas']);

    Livewire::withQueryParams(['session' => $session->id, 'open' => 1, 'tab' => 'gericht'])->test(PlanungIndex::class)
        ->assertSet('eingabe.gericht.titel', '')
        ->assertDontSeeHtml('data-planung-rezeptur-ziel');
});

// ── Bau: in die Hülle statt neu ─────────────────────────────────────────────

it('Generator: mit gericht_fuellen_id entsteht kein zweites Gericht, die Komponenten landen in der Hülle', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $huelle = ($this->huelle)();
    $vorher = FoodAlchemistRecipe::where('team_id', $this->rootTeam->id)->where('is_sales_recipe', true)->count();

    $res = app(RecipeGeneratorService::class)->generiere($this->rootTeam, HUELLE_NAME . ' — ' . HUELLE_TEXT,
        ['gericht_fuellen_id' => $huelle->id, 'titel_vorgabe' => HUELLE_NAME],
        ['name' => '[HG] Hähnchen anders benannt', 'description' => 'KI-Beschreibung', 'preparation' => 'Pochieren, zupfen, anrichten.',
            'zutaten' => [['text' => 'Hähnchen', 'quantity' => 150, 'unit' => 'g'], ['text' => 'Buchweizen', 'quantity' => 60, 'unit' => 'g']]],
        true);

    expect((int) $res['recipe']->id)->toBe((int) $huelle->id)
        ->and(FoodAlchemistRecipe::where('team_id', $this->rootTeam->id)->where('is_sales_recipe', true)->count())->toBe($vorher)
        ->and($huelle->refresh()->name)->toBe(HUELLE_NAME)                       // der Name der Hülle gilt, nicht der KI-Vorschlag
        ->and($huelle->description)->toBe(HUELLE_TEXT)                           // Kopf bleibt (alsEntwurf fasst ein Gericht nicht an)
        ->and($huelle->ingredients()->count())->toBe(2)
        ->and(GerichtHuelleService::huelle($this->rootTeam, $huelle->id))->toBeNull();   // jetzt keine Hülle mehr
});

it('Generator-Weiche: ohne gericht_fuellen_id oder für ein fertiges Gericht gibt es keine Hülle (normaler Weg: neues Gericht)', function () {
    $huelle = ($this->huelle)();
    $fertig = ($this->huelle)('[HG] Hat Komponenten');
    ($this->zutat)($fertig);

    expect(StubAusrezeptierenJob::huelle($this->rootTeam, ['gericht_fuellen_id' => $huelle->id], true)?->id)->toBe($huelle->id)
        ->and(StubAusrezeptierenJob::huelle($this->rootTeam, [], true))->toBeNull()
        ->and(StubAusrezeptierenJob::huelle($this->rootTeam, ['gericht_fuellen_id' => $fertig->id], true))->toBeNull()
        ->and(StubAusrezeptierenJob::huelle($this->rootTeam, ['gericht_fuellen_id' => $huelle->id], false))->toBeNull();   // Basisrezept-Modus kennt nur stub_fuellen_id
});

it('Bau-Weiche: der Wurzel-Schritt einer Planung mit Ziel-Gericht baut in die Hülle; was der Mensch gesetzt hat, bleibt', function () {
    $huelle = ($this->huelle)();
    $klasseVorher = $huelle->dish_main_group_id;
    $session = $this->svc->sessionFuer($this->rootTeam, $huelle->id);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    expect($this->svc->zielFuerStep($this->rootTeam, (int) $step->id)?->id)->toBe($huelle->id);

    $gesehen = null;
    $this->mock(RecipeGeneratorService::class, function ($m) use (&$gesehen, $huelle) {
        $m->shouldReceive('generiere')->once()->andReturnUsing(function ($team, $beschreibung, $parameter) use (&$gesehen, $huelle) {
            $gesehen = $parameter;
            // der Generator überschreibt Kopf-Felder, wie er es bei einem neuen Gericht täte
            $huelle->forceFill(['description' => 'KI-Text', 'description_source' => 'ki', 'dish_main_group_id' => null, 'sales_quantity_per_unit_g' => 180])->save();

            return ['recipe' => $huelle->fresh(), 'statistik' => [], 'offene' => []];
        });
    });

    $gen = $this->svc->generiere($this->rootTeam, (int) $step->id, 'Bauplan', ['cascade_step_id' => $step->id], [], fn () => null);

    expect($gesehen)->toMatchArray(['gericht_fuellen_id' => $huelle->id, 'titel_vorgabe' => HUELLE_NAME, 'cascade_step_id' => $step->id])
        ->and((int) $gen['recipe']->id)->toBe((int) $huelle->id)
        ->and($gen['recipe']->description)->toBe(HUELLE_TEXT)
        ->and($gen['recipe']->description_source)->toBeNull()
        ->and($gen['recipe']->dish_main_group_id)->toBe($klasseVorher)
        ->and((float) $gen['recipe']->sales_quantity_per_unit_g)->toBe(180.0);   // vorher leer: der Wert des Baus bleibt
});

it('Bau-Weiche: ohne Ziel-Gericht läuft der Bau unverändert, eine schon ausrezeptierte Hülle wird nicht doppelt gebaut', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $frei = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    $fertig = ($this->huelle)('[HG] Schon gebaut');
    ($this->zutat)($fertig);
    $doppelt = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running',
        'parent_step_id' => $frei->id, 'context_snapshot' => ['ziel_recipe_id' => $fertig->id]]);

    $neu = ($this->huelle)('[HG] Neu gebaut');
    $this->mock(RecipeGeneratorService::class, fn ($m) => $m->shouldReceive('generiere')->once()
        ->withArgs(fn ($team, $beschreibung, $parameter) => ! isset($parameter['gericht_fuellen_id']))
        ->andReturn(['recipe' => $neu, 'statistik' => [], 'offene' => []]));

    expect((int) $this->svc->generiere($this->rootTeam, (int) $frei->id, 'Bauplan', [], [], fn () => null)['recipe']->id)->toBe((int) $neu->id)
        ->and((int) $this->svc->generiere($this->rootTeam, (int) $doppelt->id, 'Bauplan', [], [], fn () => null)['recipe']->id)->toBe((int) $fertig->id);
});

// ── Concept: leere Gerichte in den Positionen ───────────────────────────────

it('Fan-out: eine Hülle in einer Position bekommt einen geplanten Schritt, freigegebener Bestand nicht; doppelte Position zählt einmal', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Green & Tasty', ['status' => 'draft']);
    $huelle = ($this->huelle)();
    $bestand = ($this->huelle)('[VOR] Rote Bete', ['status' => 'approved']);
    $a = $this->makeConceptSlot($concept, ['position' => 1, 'sales_recipe_id' => $huelle->id, 'quantity' => 2]);
    $this->makeConceptSlot($concept, ['position' => 2, 'sales_recipe_id' => $bestand->id]);
    $this->makeConceptSlot($concept, ['position' => 3, 'sales_recipe_id' => $huelle->id]);   // dasselbe Gericht noch einmal
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $conceptStep = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept',
        'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $concept->id]);
    $this->mock(IdeenService::class, fn ($m) => $m->shouldNotReceive('kiDivergenzConcept'));   // keine leere Position: nichts zu erfinden

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $concept->id, 'voll_kreativ');

    $kinder = FoodAlchemistCascadeRunStep::where('parent_step_id', $conceptStep->id)->get();
    expect($kinder)->toHaveCount(1);
    $step = $kinder->first();
    $idee = FoodAlchemistDishIdea::findOrFail((int) $step->context_snapshot['dish_idea_id']);
    expect($step->status)->toBe('geplant')
        ->and($step->label)->toBe(\Illuminate\Support\Str::limit('Rezeptur: ' . HUELLE_NAME, 120))
        ->and((int) $step->context_snapshot['ziel_recipe_id'])->toBe((int) $huelle->id)
        ->and($idee->source_meta['quelle'])->toBe('gericht_huelle')
        ->and($this->svc->zielFuerStep($this->rootTeam, (int) $step->id)?->id)->toBe($huelle->id);

    // Nach dem Bau bleibt die Position, wie sie war: dasselbe Gericht, Menge unverändert.
    app(ConceptVorgabenService::class)->setzeGericht($this->rootTeam, (int) $a->id, (int) $huelle->id, $idee);
    expect((float) $a->refresh()->quantity)->toBe(2.0)->and($a->sales_recipe_id)->toBe($huelle->id);
});
