<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\TitelVorschlagService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Planung-Leitstelle: die KI-Erstellung (Regler-Leitplanken) lebt konsolidiert in der Planung.
 * Beweisziele:
 *  1. Die Regler des Cockpit-Go werden als Lauf-`params` gereicht UND als `generation_params`
 *     der Session persistiert (whitelist-gefiltert) → Fan-out kann sie erben.
 *  2. Der Kaskaden-Fan-out (materialisiereConceptGericht) reicht die Session-Regler an `generiere`.
 *  3. Freie 1-Klick-Erstellung legt eine `cockpit_frei`-Session an (de-trend) und öffnet den Editor.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
});

it('setGenerationParams: filtert auf die Whitelist und macht leere Auswahl zu null', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X']);
    $svc = app(PlanningSessionService::class);

    $svc->setGenerationParams($this->rootTeam, (int) $session->id, [
        'level' => 'gehoben', 'bio' => true,
        'aroma' => '',                 // leerer String → raus
        'diaet_hart' => [],            // leeres Array → raus
        'unbekannt' => 'boese',        // nicht in der Whitelist → raus
    ]);
    expect($session->refresh()->generation_params)->toBe(['level' => 'gehoben', 'bio' => true]);

    // Nur Leerwerte/Fremdkeys → null (kein leeres {} persistieren)
    $svc->setGenerationParams($this->rootTeam, (int) $session->id, ['aroma' => '', 'unbekannt' => 'x']);
    expect($session->refresh()->generation_params)->toBeNull();
});

it('Leitstelle: goKaskade reicht die Regler als params UND persistiert sie als generation_params', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Rotwein-Reduktion', 'brief' => 'Dunkle Reduktion.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.rezept.brief', 'Dunkle Reduktion.')
        ->set('regler.rezept.level', 'gehoben')
        ->set('regler.rezept.convenience', 'from_scratch')
        ->set('regler.rezept.bio_praeferenz', 'bio')      // → bio-Bool true
        ->call('goKaskade', 'rezept')
        ->assertSet('laeuft', true)
        ->assertNoRedirect();

    // Leitplanken an der Session (für den Fan-out) — bio-Bool aus der dreiwertigen Präferenz.
    expect($session->refresh()->generation_params)->toMatchArray([
        'level' => 'gehoben', 'convenience' => 'from_scratch', 'bio' => true,
    ]);

    // Der Depth-1-Job trägt die Regler im parameter (nicht mehr leer).
    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->vkModus === false
        && ($job->parameter['level'] ?? null) === 'gehoben'
        && ($job->parameter['convenience'] ?? null) === 'from_scratch'
        && ($job->parameter['bio'] ?? null) === true);
});

it('Fan-out erbt die Regler: materialisiereConceptGericht reicht generation_params an generiere', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer', 'brief' => 'x']);
    app(PlanningSessionService::class)->setGenerationParams($this->rootTeam, (int) $session->id, [
        'level' => 'gehoben', 'bio' => true, 'convenience' => 'from_scratch',
    ]);

    $idea = FoodAlchemistDishIdea::create([
        'team_id' => $this->rootTeam->id, 'title' => 'Erfundenes Gericht',
        'generation_status' => 'queued', 'status' => 'offen',
        'source_meta' => ['target_concept_slot_id' => 0],   // 0 → fillSlot wird übersprungen
    ]);
    $recipe = $this->makeRecipe($this->rootTeam, 'Erfundenes Gericht', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);

    // Workflow neutralisieren (Grounding/Vererbung sind hier nicht das Testziel).
    $this->mock(RecipeDependencyWorkflowService::class, function ($m) {
        $m->shouldReceive('prepare')->andReturn([]);
        $m->shouldReceive('afterGenerated')->andReturnNull();
    });
    // generiere: die übergebenen $params (3. Arg) einfangen, echtes Rezept zurückgeben.
    $erhaltene = [];
    $this->mock(RecipeGeneratorService::class, function ($m) use (&$erhaltene, $recipe) {
        $m->shouldReceive('generiere')->andReturnUsing(function (...$args) use (&$erhaltene, $recipe) {
            $erhaltene = $args[2] ?? [];
            return ['recipe' => $recipe, 'offene' => []];
        });
    });

    app(PlanningCascadeService::class)->materialisiereConceptGericht($this->rootTeam, (int) $idea->id, (int) $step->id, (int) $session->id);

    expect($erhaltene['level'] ?? null)->toBe('gehoben')
        ->and($erhaltene['bio'] ?? null)->toBeTrue()
        ->and($erhaltene['convenience'] ?? null)->toBe('from_scratch')
        ->and((int) ($erhaltene['cascade_step_id'] ?? 0))->toBe((int) $step->id);   // Steuer-Key bleibt
});

it('Freie 1-Klick-Erstellung: schnellErstellen legt eine cockpit_frei-Session an (de-trend) + öffnet den Editor', function () {
    Livewire::test(PlanungIndex::class)
        ->call('schnellErstellen', 'gericht')
        ->assertDispatched('modal.open');

    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)->latest('id')->first();
    expect($session)->not->toBeNull()
        ->and($session->created_via)->toBe('cockpit_frei')
        ->and($session->title)->toBe('Freies Gericht')
        ->and($session->source_knowledge_document_id)->toBeNull();   // kein Trend
});

it('Scope-Treue: schnellErstellen öffnet den Editor auf dem zur Ebene passenden Tab (Basisrezept ≠ Gericht)', function () {
    // „Freies Basisrezept" (scope=rezept) muss auf dem Basisrezept-Tab landen, nicht auf Gericht —
    // sonst erzeugte der nächste Go eine Gerichte-Stufe (Roadmap Etappe 1, Scope-Treue).
    Livewire::test(PlanungIndex::class)
        ->call('schnellErstellen', 'rezept')
        ->assertDispatched('modal.open', name: 'planung-editor', tab: 'basisrezept');

    Livewire::test(PlanungIndex::class)
        ->call('schnellErstellen', 'gericht')
        ->assertDispatched('modal.open', name: 'planung-editor', tab: 'gericht');

    Livewire::test(PlanungIndex::class)
        ->call('schnellErstellen', 'concept')
        ->assertDispatched('modal.open', name: 'planung-editor', tab: 'concept');
});

it('oeffne aus der Liste (ohne Start-Tab) bleibt auf dem Editor-Default (tab=null → tabInit)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertDispatched('modal.open', name: 'planung-editor', tab: null);
});

it('Cockpit rendert die Regler-Leitplanken + die freie Erstell-Leiste (Blade kompiliert, KI-Fläche ist in der Planung)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-planung-regler')      // volle Regler-Fläche im Planung-Tab
        ->assertSeeHtml('data-frei-rezept')          // freie 1-Klick-Erstellung
        ->assertSeeHtml('data-planung-ziel-vk');     // Gericht-Achse Ziel-VK
});

it('Etappe 2a: Concept-Go persistiert die Menü-Leitplanken (Gänge + Zielpreis-Korridor je Person) als generation_params', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer-Menü', 'brief' => 'Vier Gänge, mediterran.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'Vier Gänge, mediterran.')
        ->set('regler.concept.menue_gaenge', '4')
        ->set('regler.concept.menue_preis_min', '35,00')
        ->set('regler.concept.menue_preis_ziel', '45,00')
        ->set('regler.concept.menue_preis_max', '60,00')
        ->call('goKaskade', 'concept')
        ->assertNoRedirect();

    // Die Menü-Achsen werden in kanonische _pp-Keys geparst und (whitelist-gefiltert) persistiert → Fan-out erbt sie.
    expect($session->refresh()->generation_params)->toMatchArray([
        'menue_gaenge' => 4,
        'menue_preis_min_pp' => 35.0,
        'menue_preis_ziel_pp' => 45.0,
        'menue_preis_max_pp' => 60.0,
    ]);
});

it('Concept-Typ Buffet (#35): menue_typ=buffet wird als generation_param persistiert (Fan-out-Erbe)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Grill-Buffet', 'brief' => 'Sommerliches Grill-Buffet.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'Sommerliches Grill-Buffet.')
        ->set('regler.concept.menue_typ', 'buffet')
        ->set('regler.concept.menue_gaenge', '6')
        ->call('goKaskade', 'concept')
        ->assertNoRedirect();

    expect($session->refresh()->generation_params)->toMatchArray([
        'menue_typ' => 'buffet',
        'menue_gaenge' => 6,
    ]);
});

it('Concept-Typ Menü (#35): Default menue_typ=menue setzt KEINEN generation_param (byte-identisch)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü', 'brief' => 'Vier Gänge.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'Vier Gänge.')
        ->set('regler.concept.menue_typ', 'menue')
        ->call('goKaskade', 'concept')
        ->assertNoRedirect();

    expect($session->refresh()->generation_params ?? [])->not->toHaveKey('menue_typ');
});

it('Etappe 2a: Menü-Leitplanken sind Concept-only — am Gericht-Tab fließen menue_*-Felder NICHT in die Params', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Gericht', 'brief' => 'Ein Teller.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.gericht.brief', 'Ein Teller.')
        ->set('regler.gericht.menue_gaenge', '4')            // gesetzt, aber Gericht-Scope ignoriert Menü-Achsen
        ->set('regler.gericht.menue_preis_ziel', '45,00')
        ->call('goKaskade', 'gericht')
        ->assertNoRedirect();

    $params = $session->refresh()->generation_params ?? [];
    expect($params)->not->toHaveKey('menue_gaenge')
        ->and($params)->not->toHaveKey('menue_preis_ziel_pp');
});

it('Etappe 2a: mistgetippter Menü-Preis am Concept wird GESAGT (fehler), kein Lauf gestartet', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü', 'brief' => 'x']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'x')
        ->set('regler.concept.menue_preis_ziel', 'viereuro')   // unparsbar
        ->call('goKaskade', 'concept')
        ->assertSet('laeuft', false)                            // kein Lauf
        ->assertNotSet('fehler', null);                         // Absender wird korrigiert statt still verworfen

    expect(FoodAlchemistCascadeRun::where('planning_session_id', $session->id)->count())->toBe(0);
});

it('Etappe 2a: Concept-Tab rendert die Menü-Leitplanken-Sektion (Gänge + Korridor-Felder)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-menue-leitplanken')
        ->assertSeeHtml('data-menue-gaenge')
        ->assertSeeHtml('data-menue-preis-ziel');
});

it('Etappe 2a Teil 2: Concept-Go persistiert die Diät-Quoten (Vegan-/Vegetarisch-Anteil) als _pct-Params', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü', 'brief' => 'Grüner Fokus.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'Grüner Fokus.')
        ->set('regler.concept.menue_quote_vegan', '30')
        ->set('regler.concept.menue_quote_vegetarisch', '50 %')   // %-Zeichen + Space werden tolerant gestrippt
        ->call('goKaskade', 'concept')
        ->assertNoRedirect();

    expect($session->refresh()->generation_params)->toMatchArray([
        'menue_quote_vegan_pct' => 30,
        'menue_quote_vegetarisch_pct' => 50,
    ]);
});

it('Etappe 2a Teil 2: Diät-Quoten sind Concept-only — am Gericht-Tab fließen sie NICHT in die Params', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Gericht', 'brief' => 'Ein Teller.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.gericht.brief', 'Ein Teller.')
        ->set('regler.gericht.menue_quote_vegan', '30')            // gesetzt, aber Gericht-Scope ignoriert Menü-Achsen
        ->call('goKaskade', 'gericht')
        ->assertNoRedirect();

    $params = $session->refresh()->generation_params ?? [];
    expect($params)->not->toHaveKey('menue_quote_vegan_pct')
        ->and($params)->not->toHaveKey('menue_quote_vegetarisch_pct');
});

it('Etappe 2a Teil 2: mistgetippter Diät-Anteil am Concept wird GESAGT (fehler), kein Lauf gestartet', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü', 'brief' => 'x']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'x')
        ->set('regler.concept.menue_quote_vegan', '150')           // außerhalb 0–100
        ->call('goKaskade', 'concept')
        ->assertSet('laeuft', false)                               // kein Lauf
        ->assertNotSet('fehler', null);                            // Absender wird korrigiert statt still verworfen

    expect(FoodAlchemistCascadeRun::where('planning_session_id', $session->id)->count())->toBe(0);
});

it('Etappe 2a Teil 2: Concept-Tab rendert die Diät-Quoten-Felder (Anteil ≠ harter Ausschluss)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-menue-diaet-quoten')
        ->assertSeeHtml('data-menue-quote-vegan')
        ->assertSeeHtml('data-menue-quote-vegetarisch');
});

it('Etappe 2a Rest: Concept-Go persistiert die Portfolio-Balance (Menü-Vielfalt) als menue_balance-Param', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü', 'brief' => 'Bunt gemischt.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'Bunt gemischt.')
        ->set('regler.concept.menue_balance', 'ausgewogen')
        ->call('goKaskade', 'concept')
        ->assertNoRedirect();

    expect($session->refresh()->generation_params)->toMatchArray([
        'menue_balance' => 'ausgewogen',
    ]);
});

it('Etappe 2a Rest: Portfolio-Balance ist Concept-only — am Gericht-Tab fließt sie NICHT in die Params', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Gericht', 'brief' => 'Ein Teller.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.gericht.brief', 'Ein Teller.')
        ->set('regler.gericht.menue_balance', 'ausgewogen')       // gesetzt, aber Gericht-Scope ignoriert Menü-Achsen
        ->call('goKaskade', 'gericht')
        ->assertNoRedirect();

    $params = $session->refresh()->generation_params ?? [];
    expect($params)->not->toHaveKey('menue_balance');
});

it('Etappe 2a Rest: unbekannter Portfolio-Balance-Wert am Concept wird still verworfen (nur Enum durchgereicht)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü', 'brief' => 'x']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'x')
        ->set('regler.concept.menue_balance', 'chaotisch')        // kein MENUE_BALANCE-Enum → kein Key
        ->call('goKaskade', 'concept')
        ->assertNoRedirect();

    $params = $session->refresh()->generation_params ?? [];
    expect($params)->not->toHaveKey('menue_balance');
});

it('Etappe 2a Rest: Concept-Tab rendert das Portfolio-Balance-Feld', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-menue-balance')
        ->assertSeeHtml('data-menue-balance-select');
});

it('Queue-Watchdog: Lauf hängt lange OHNE Step-Fortschritt → sichtbarer Hinweis (kein Worker), kein Abbruch', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'rezept', 'status' => 'running']);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'running']);
    // Raw-Update, um den created_at-Touch zu umgehen: „vor 2 Minuten gestartet, immer noch nichts fertig".
    FoodAlchemistCascadeRun::where('id', $run->id)->update(['created_at' => now()->subSeconds(120)]);

    Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->set('laeuft', true)
        ->call('pruefeLauf')
        ->assertSet('laeuft', true)              // kein Abbruch — weiter pollen
        ->assertNotSet('hinweis', null);         // Watchdog schlägt an
});

it('Queue-Watchdog schweigt, wenn ein Schritt Fortschritt gemacht hat (Worker bewiesen aktiv, legitim langer Fan-out)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'concept', 'status' => 'running']);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept', 'status' => 'done']);      // Fortschritt!
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    FoodAlchemistCascadeRun::where('id', $run->id)->update(['created_at' => now()->subSeconds(300)]);

    Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->set('laeuft', true)
        ->call('pruefeLauf')
        ->assertSet('hinweis', null);            // trotz 5 Min Laufzeit kein Hinweis — Worker lebt
});

// ── Etappe 8 — Idempotenz/Resume: „Abgebrochene Schritte freiräumen" im Cockpit ──────────────
it('laufFortsetzen: reapt verwaiste Steps und macht den hängenden Lauf handlungsfähig', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    // Raw-Update (kein Timestamp-Touch): der Job liegt seit 45 Min ohne Rückmeldung → verwaist.
    FoodAlchemistCascadeRunStep::where('id', $step->id)->update(['updated_at' => now()->subMinutes(45)]);

    Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->set('laeuft', true)
        ->call('laufFortsetzen')
        ->assertSet('fehler', null)
        ->assertSet('laeuft', false);            // Run raus aus dem ewigen `running`

    expect($step->refresh()->status)->toBe('failed')
        ->and($run->refresh()->status)->toBe('failed');
});

it('laufFortsetzen: nichts verwaist → ehrliche Meldung, kein Step angetastet', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    // frisch — nicht verwaist

    Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->set('laeuft', true)
        ->call('laufFortsetzen');

    expect($step->refresh()->status)->toBe('running')
        ->and($run->refresh()->status)->toBe('running');
});

// ── Etappe 8 — Idempotenz/Resume Teil 3: „Gescheiterte Schritte fortsetzen" im Cockpit ──────────
it('laufWiederAufnehmen: nimmt alle gescheiterten generierbaren Steps gebündelt wieder auf', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'failed', 'staged' => true]);
    $f1 = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'failed', 'label' => 'Fail 1']);
    $f2 = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'failed', 'label' => 'Fail 2']);
    $done = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => 1]);

    Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->call('laufWiederAufnehmen')
        ->assertSet('fehler', null);

    expect($f1->refresh()->status)->toBe('running')
        ->and($f2->refresh()->status)->toBe('running')
        ->and($done->refresh()->status)->toBe('done');  // done unberührt
    Queue::assertPushed(GenerateRecipeJob::class, 2);
});

it('laufWiederAufnehmen: kein gescheiterter Step → ehrliche Meldung, kein Job', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true]);
    FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => 1]);

    Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->call('laufWiederAufnehmen')
        ->assertSet('meldung', 'Kein gescheiterter Schritt zum Fortsetzen.');

    Queue::assertNotPushed(GenerateRecipeJob::class);
});

it('#1b Grounding-Preview: wissenVorschau baut nur den Kontext, generiert NICHT (kein Lauf)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Rotwein-Reduktion', 'brief' => 'Dunkle Reduktion.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.rezept.brief', 'Dunkle Reduktion.')
        ->assertSet('wissenVorschau', null)
        ->call('wissenVorschau', 'rezept')
        ->assertSet('laeuft', false);            // Vorschau startet keinen Lauf

    // Preview ≠ Generierung: kein Kaskaden-Lauf angelegt.
    expect(FoodAlchemistCascadeRun::where('planning_session_id', $session->id)->count())->toBe(0);
});

it('#4/#1a Cockpit-Baum: Fan-out-Kind eingerückt + „Verwendetes Wissen" aus context_snapshot', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    $parent = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done',
        'label' => 'Wurzel-Gericht', 'context_snapshot' => ['knowledge_files' => ['pairings/tomate.md', 'domains/suppen.md']],
    ]);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $parent->id,
        'kind' => 'rezept', 'status' => 'done', 'label' => 'Kind-Basisrezept', 'depth' => 1,   // Fan-out-Kind → eingerückt
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('sessionId', $session->id)
        ->set('laufId', $run->id)
        ->assertSee('Wurzel-Gericht')
        ->assertSee('Kind-Basisrezept')          // #4 Fan-out-Kind sichtbar
        ->assertSee('Verwendetes Wissen')        // #1a aus context_snapshot
        ->assertSee('↳');                        // Einrückungs-Marker des Kindes
});

it('A: Inline-Zutaten-Review — Toggle mountet den IngredientEditor on-demand für einen Draft', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Draft-Suppe', ['status' => 'draft']);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'rezept', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done',
        'label' => 'Draft-Suppe', 'ref_id' => $rezept->id,
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('sessionId', $session->id)
        ->set('laufId', $run->id)
        ->assertSee('Zutaten prüfen')            // Toggle da, Editor NOCH nicht offen …
        ->assertSet('zutatenOffen', [])
        ->call('toggleZutaten', $step->id)
        ->assertSet('zutatenOffen', [$step->id]) // … Klick öffnet DIESEN Draft
        ->assertSee('Zutaten schließen')         // Editor gemountet (Toggle-Label kippt)
        ->call('toggleZutaten', $step->id)
        ->assertSet('zutatenOffen', []);         // zu
});

/**
 * Provider-Stub für den KI-Kopf-Flow (Etappe 2b): minimales Gerüst (concept.brief_geruest) +
 * kreative Canvas (concept.plan). Selbst-enthalten in DIESEM File (nicht auf ConceptGeneratorTest
 * angewiesen — die Routine fährt PlanungLeitstelleTest gezielt, ohne das andere File zu laden).
 */
function bindKiKopfStub(): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class implements LLMProviderContract
    {
        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            $prompt = collect($messages)->pluck('content')->filter()->implode("\n");
            if (str_contains($prompt, 'Concept-Canvas')) {   // concept.plan (kreative Handschrift)
                return ['content' => json_encode(['werte' => [
                    'name_claim' => 'Sommerglanz', 'leitidee' => 'Leichte Küche für laue Abende.',
                ], 'confidence' => 0.7, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
            }

            // concept.brief_geruest (Slots/Preis) — 1 Gang = 1 Fan-out-Ziel
            return ['content' => json_encode(['werte' => [
                'name' => 'KI-Kopf-Menü', 'target_price_pp' => 42,
                'slots' => [['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]],
            ], 'confidence' => 0.8, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

        public function getAvailableModels(): array
        {
            return ['stub'];
        }

        public function getDefaultModel(): string
        {
            return 'stub';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });
}

it('KI-Kopf: arbeitet den Plan aus dem Concept-Briefing aus + öffnet den Conceptor auf „Konzept & Planung", startet KEINE Kaskade', function () {
    bindKiKopfStub();
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer-Menü']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'CHEFS.CORNER — Sommer-Menü, 30 Gäste, leicht, ca. 42 € p. P.')
        ->set('eingabe.concept.titel', 'Sommerglanz')
        ->call('kiKopf')
        ->assertSet('fehler', null)
        // öffnet den vollen inline-Conceptor direkt auf dem 'konzept'-Tab (Prüfung/Korrektur)
        ->assertDispatched('concepter-editor.oeffnen', type: 'concepts', startTab: 'konzept');

    // Draft-Concept steht (Lineage), Name aus dem Nutzer-Titel — und KEINE Kaskade gestartet.
    $concept = FoodAlchemistConcept::where('team_id', $this->rootTeam->id)->where('created_via', 'concept_plan_ui')->first();
    expect($concept)->not->toBeNull()
        ->and($concept->name)->toBe('Sommerglanz')
        ->and($concept->status)->toBe('draft');
    expect(FoodAlchemistCascadeRun::where('planning_session_id', $session->id)->count())->toBe(0);
});

it('KI-Kopf: leeres Concept-Briefing wird gesagt (kein Draft, keine Öffnung)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Leer']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', '   ')
        ->call('kiKopf')
        ->assertSet('fehler', 'Für den KI-Kopf erst ein Concept-Briefing eingeben.')
        ->assertNotDispatched('concepter-editor.oeffnen');

    expect(FoodAlchemistConcept::where('team_id', $this->rootTeam->id)->where('created_via', 'concept_plan_ui')->count())->toBe(0);
});

// ── Etappe 2b: „Beide Pfade behalten" — Caller-Verdrahtung KI-Kopf → planConceptId → Go ──────

it('Geplanter Pfad: KI-Kopf merkt planConceptId; der Concept-Go referenziert ihn (kein GenerateConceptJob) und verbraucht die Prop', function () {
    bindKiKopfStub();
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer-Menü']);

    $component = Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'CHEFS.CORNER — Sommer-Menü, 30 Gäste, leicht.')
        ->call('kiKopf');

    // KI-Kopf hat den geprüften Draft gemerkt (transient) → nächster Go ist der geplante Pfad.
    $concept = FoodAlchemistConcept::where('team_id', $this->rootTeam->id)->where('created_via', 'concept_plan_ui')->firstOrFail();
    $component->assertSet('planConceptId', (int) $concept->id);
    Queue::assertNotPushed(GenerateConceptJob::class);   // KI-Kopf generiert synchron, kein Concept-Job

    // Go: referenziert den geprüften Draft statt neu zu generieren; Prop wird verbraucht (→ null).
    $component->call('goKaskade', 'concept')
        ->assertSet('fehler', null)
        ->assertSet('laeuft', true)
        ->assertSet('planConceptId', null);

    Queue::assertNotPushed(GenerateConceptJob::class);   // KEIN neuer Concept-Job — Step zeigt auf den Plan
    $run = FoodAlchemistCascadeRun::where('planning_session_id', $session->id)->firstOrFail();
    $step = $run->steps()->first();
    expect($step->kind)->toBe('concept')
        ->and($step->status)->toBe('done')
        ->and($step->ref_type)->toBe('concept')
        ->and((int) $step->ref_id)->toBe((int) $concept->id);
});

it('Fail-soft: ein toter planConceptId (gelöscht/Team-fremd) blockt den Go NICHT — er fällt auf den Schnell-Pfad zurück (frische Generierung)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'Leichtes Sommer-Buffet.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'Leichtes Sommer-Buffet.')
        ->set('planConceptId', 999999)          // zeigt auf nichts (Existenz-/Team-Guard schlägt fehl)
        ->call('goKaskade', 'concept')
        ->assertSet('fehler', null)              // NICHT hart blocken
        ->assertSet('laeuft', true)
        ->assertSet('planConceptId', null);      // tote Referenz still verworfen

    // Schnell-Pfad: es wird frisch generiert (GenerateConceptJob), nicht referenziert.
    Queue::assertPushed(GenerateConceptJob::class);
});

it('planVerwerfen: löst die Plan-Referenz und wechselt zurück auf den Schnell-Pfad (löscht nichts)', function () {
    Livewire::test(PlanungIndex::class)
        ->set('planConceptId', 4242)
        ->call('planVerwerfen')
        ->assertSet('planConceptId', null)
        ->assertSet('fehler', null)
        ->assertSet('meldung', 'Vorbereiteter Plan verworfen — der Go generiert wieder frisch aus dem Briefing.');
});

// ── #53 KI-Kopf persistent: der geprüfte Plan übersteht einen Reload (plan_concept_id an der Session) ──

it('#53 persistent: KI-Kopf schreibt plan_concept_id an die Session; der Go verbraucht + nullt sie', function () {
    bindKiKopfStub();
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Persistenz-Menü']);

    $component = Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.concept.brief', 'Sommer-Menü, 30 Gäste, leicht.')
        ->call('kiKopf');

    $concept = FoodAlchemistConcept::where('team_id', $this->rootTeam->id)->where('created_via', 'concept_plan_ui')->firstOrFail();
    // Persistiert an der Session → übersteht Reload (rehydrate in ladeForm).
    expect((int) $session->refresh()->plan_concept_id)->toBe((int) $concept->id);

    // Go verbraucht den Plan → gespeicherter Zeiger wieder null (kein Wieder-Anbieten nach Reload).
    $component->call('goKaskade', 'concept')->assertSet('laeuft', true);
    expect($session->refresh()->plan_concept_id)->toBeNull();
});

it('#53 persistent: oeffne rehydriert planConceptId aus der Session-Spalte (Plan übersteht Reload)', function () {
    $concept = app(\Platform\FoodAlchemist\Services\ConceptService::class)->create($this->rootTeam, ['name' => 'Geprüfter Plan', 'status' => 'draft']);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Reload-Menü']);
    $session->update(['plan_concept_id' => (int) $concept->id]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSet('planConceptId', (int) $concept->id);
});

it('#53 persistent: oeffne verwirft einen toten plan_concept_id still (kein Geister-Plan)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Tot']);
    $session->update(['plan_concept_id' => 999999]);   // zeigt auf nichts

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSet('planConceptId', null);
});

it('#53 persistent: planVerwerfen löst auch den gespeicherten Zeiger (löscht das Draft nicht)', function () {
    $concept = app(\Platform\FoodAlchemist\Services\ConceptService::class)->create($this->rootTeam, ['name' => 'Plan', 'status' => 'draft']);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Verwerfen']);
    $session->update(['plan_concept_id' => (int) $concept->id]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSet('planConceptId', (int) $concept->id)
        ->call('planVerwerfen')
        ->assertSet('planConceptId', null);

    // Spalte gelöst, das Draft-Concept selbst bleibt bestehen.
    expect($session->refresh()->plan_concept_id)->toBeNull()
        ->and(FoodAlchemistConcept::whereKey($concept->id)->exists())->toBeTrue();
});

// ── #17 Hauptseite / Planung-Landing: Kaskaden-Status je Session (Badge + Stufen-Fortschritt) ──

it('#17 Hauptseite: landingKaskadenMap spiegelt den jüngsten Lauf-Status je Session (läuft nach Go)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Landing', 'brief' => 'Ein Teller.']);

    $component = Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.gericht.brief', 'Ein Teller.')
        ->call('goKaskade', 'gericht')
        ->assertSet('laeuft', true);

    $map = $component->instance()->landingKaskadenMap($this->rootTeam, [(int) $session->id]);
    expect($map[(int) $session->id]['status'])->toBe('läuft')
        ->and($map[(int) $session->id]['running'])->toBeTrue()
        ->and($map[(int) $session->id]['stufen'])->not->toBeEmpty();
});

it('#17 Hauptseite: Session ohne Lauf fehlt in der Map → die Blade zeigt „Entwurf" (verwaister Entwurf sichtbar)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Verwaist']);

    $map = Livewire::test(PlanungIndex::class)->instance()->landingKaskadenMap($this->rootTeam, [(int) $session->id]);
    expect($map)->not->toHaveKey((int) $session->id);
});

it('#17 Hauptseite: die Landing rendert das Kaskaden-Status-Badge in der linken Liste', function () {
    app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Badge-Test', 'brief' => 'x']);

    Livewire::test(PlanungIndex::class)
        ->assertSeeHtml('data-planung-status');
});

/**
 * Etappe 4 — Skizzen-Integration (Teil 1): eine Session-Skizze wird zum Kaskaden-Eingang, indem
 * sie in den Gericht-Tab übertragen wird (Titel → Titel, Beschreibung → Brief). Der Mensch drückt
 * dann selbst „Go" — geführte Freigabe, kein stiller Lauf.
 */
it('skizzeAlsGericht: überträgt Titel + Beschreibung einer Session-Skizze in den Gericht-Tab', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Herbst-Menü']);
    $idee = app(\Platform\FoodAlchemist\Services\IdeenService::class)->add($this->rootTeam, [
        'planning_session_id' => $session->id,
        'title' => 'Rehrücken mit Wacholderjus',
        'description' => 'Kräftig, herbstlich, Wildaromatik.',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('skizzeAlsGericht', $idee->id)
        ->assertSet('eingabe.gericht.titel', 'Rehrücken mit Wacholderjus')
        ->assertSet('eingabe.gericht.brief', 'Kräftig, herbstlich, Wildaromatik.')
        ->assertSet('fehler', null)
        ->assertSet('meldung', 'Skizze in den Gericht-Tab übernommen — Leitplanken prüfen, dann „Go".');

    // Prefill verbraucht die Skizze NICHT — sie bleibt entwurf (erst der Go erzeugt etwas).
    expect($idee->refresh()->status)->toBe('entwurf');
});

it('skizzeAlsGericht: eine verworfene Skizze wird nicht übernommen (gesagt, nicht still) und lässt den Gericht-Tab leer', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    $svc = app(\Platform\FoodAlchemist\Services\IdeenService::class);
    $idee = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Verworfene Idee']);
    $svc->setStatus($this->rootTeam, $idee->id, 'verworfen');

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('skizzeAlsGericht', $idee->id)
        ->assertSet('eingabe.gericht.titel', '')
        ->assertSet('eingabe.gericht.brief', '')
        ->assertSet('fehler', 'Skizze nicht gefunden (oder verworfen) — bitte neu wählen.');
});

/**
 * Etappe 4 — Skizzen-Integration (Teil 2a — Lineage): startet der Gericht-Go aus einer übertragenen
 * Skizze, wird der Kaskaden-Lauf auf sie zurückgestempelt (origin_dish_idea_id) — die Voraussetzung
 * dafür, dass die Skizzen-Karte später den Lauf-Status zeigen kann (Teil 2b).
 */
it('Skizzen-Lineage: ein Gericht-Go aus einer übertragenen Skizze stempelt den Lauf auf sie zurück', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Herbst-Menü']);
    $idee = app(\Platform\FoodAlchemist\Services\IdeenService::class)->add($this->rootTeam, [
        'planning_session_id' => $session->id,
        'title' => 'Rehrücken mit Wacholderjus',
        'description' => 'Kräftig, herbstlich, Wildaromatik.',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('skizzeAlsGericht', $idee->id)
        ->assertSet('skizzeGerichtId', $idee->id)   // gemerkt für den nächsten Gericht-Go
        ->call('goKaskade', 'gericht')
        ->assertSet('laeuft', true)
        ->assertSet('skizzeGerichtId', null);       // nach dem Go verbraucht

    $run = FoodAlchemistCascadeRun::where('team_id', $this->rootTeam->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->scope)->toBe('gericht')
        ->and((int) $run->origin_dish_idea_id)->toBe((int) $idee->id);
});

it('Skizzen-Lineage: ein Gericht-Go OHNE Skizzen-Ursprung lässt origin_dish_idea_id null', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Direkt', 'brief' => 'Direktes Gericht.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.gericht.brief', 'Direktes Gericht.')
        ->call('goKaskade', 'gericht')
        ->assertSet('laeuft', true);

    $run = FoodAlchemistCascadeRun::where('team_id', $this->rootTeam->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->origin_dish_idea_id)->toBeNull();
});

it('Skizzen-Lineage fail-soft: eine inzwischen verworfene Skizze kippt den Go nicht (Lauf ohne Herkunft)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    $svc = app(\Platform\FoodAlchemist\Services\IdeenService::class);
    $idee = $svc->add($this->rootTeam, [
        'planning_session_id' => $session->id,
        'title' => 'Skizze', 'description' => 'Ein Brief.',
    ]);

    $comp = Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('skizzeAlsGericht', $idee->id)
        ->assertSet('skizzeGerichtId', $idee->id);

    // Skizze NACH dem Prefill verwerfen — der Go muss trotzdem durchlaufen, nur ohne Herkunft.
    $svc->setStatus($this->rootTeam, $idee->id, 'verworfen');

    $comp->call('goKaskade', 'gericht')
        ->assertSet('laeuft', true)
        ->assertSet('skizzeGerichtId', null);   // tote Referenz still verworfen

    $run = FoodAlchemistCascadeRun::where('team_id', $this->rootTeam->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->origin_dish_idea_id)->toBeNull();
});

/**
 * Etappe 4 — Skizzen-Integration (Teil 2b — Status auf die Karte): der aus einer Skizze gestartete
 * Gericht-Go (Teil 2a, origin_dish_idea_id) schlägt als abgestuftes Status-Badge auf die Skizzen-
 * Karte im Divergenz-Board zurück (läuft/prüfen/fertig/fehlgeschlagen) — ohne den Worker zu öffnen.
 */
it('Skizzen-Status 2b: der verknüpfte Lauf (review) zeigt „▸ prüfen" auf der Skizzen-Karte', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Herbst-Menü']);
    $idee = app(\Platform\FoodAlchemist\Services\IdeenService::class)->add($this->rootTeam, [
        'planning_session_id' => $session->id,
        'title' => 'Rehrücken mit Wacholderjus',
        'description' => 'Kräftig, herbstlich.',
    ]);
    FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id,
        'scope' => 'gericht', 'status' => 'review', 'origin_dish_idea_id' => $idee->id,
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSee('Rehrücken mit Wacholderjus')
        ->assertSee('▸ prüfen');
});

it('Skizzen-Status 2b: eine Skizze OHNE verknüpften Lauf trägt kein Status-Badge', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    app(\Platform\FoodAlchemist\Services\IdeenService::class)->add($this->rootTeam, [
        'planning_session_id' => $session->id,
        'title' => 'Skizze ohne Go', 'description' => 'Nur ein Entwurf.',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSee('Skizze ohne Go')
        ->assertDontSee('▸ ');   // keine der Status-Marken (läuft/prüfen/fertig/fehlgeschlagen)
});

it('Skizzen-Status 2b: bei mehreren Läufen je Skizze gewinnt der jüngste (Retry: done schlägt failed)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    $idee = app(\Platform\FoodAlchemist\Services\IdeenService::class)->add($this->rootTeam, [
        'planning_session_id' => $session->id,
        'title' => 'Zweiter Versuch', 'description' => 'Erst gescheitert, dann fertig.',
    ]);
    // Älterer Lauf failed, jüngerer (höhere id) done → die Karte zeigt den jüngsten.
    FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id,
        'scope' => 'gericht', 'status' => 'failed', 'origin_dish_idea_id' => $idee->id,
    ]);
    FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id,
        'scope' => 'gericht', 'status' => 'done', 'origin_dish_idea_id' => $idee->id,
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSee('▸ fertig')
        ->assertDontSee('▸ fehlgeschlagen');
});

/**
 * Etappe 4 — Skizzen-Integration (Teil 3b-b — Live-Poll der Karten-Badges): solange ein aus einer
 * Skizze gestarteter Lauf noch `running` ist, refresht sich das Board selbst (bare wire:poll), damit
 * die Badges live von „läuft" auf „prüfen"/„fertig" kippen — ohne das Einzel-Cockpit anzuwerfen.
 * Sobald kein verknüpfter Lauf mehr running ist, entfällt das Poll-Element.
 */
it('Skizzen-Poll 3b-b: ein running-Lauf blendet den Live-Poll ein („▸ läuft" + wire:poll)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    $idee = app(\Platform\FoodAlchemist\Services\IdeenService::class)->add($this->rootTeam, [
        'planning_session_id' => $session->id,
        'title' => 'Läuft gerade', 'description' => 'Worker arbeitet.',
    ]);
    FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id,
        'scope' => 'gericht', 'status' => 'running', 'origin_dish_idea_id' => $idee->id,
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSee('▸ läuft')
        ->assertSeeHtml('data-skizzen-poll');
});

it('Skizzen-Poll 3b-b: ein wartender Lauf (review) zeigt das Badge, aber KEINEN Live-Poll', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    $idee = app(\Platform\FoodAlchemist\Services\IdeenService::class)->add($this->rootTeam, [
        'planning_session_id' => $session->id,
        'title' => 'Wartet auf Freigabe', 'description' => 'Prüfen.',
    ]);
    FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id,
        'scope' => 'gericht', 'status' => 'review', 'origin_dish_idea_id' => $idee->id,
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSee('▸ prüfen')
        ->assertDontSeeHtml('data-skizzen-poll');
});

it('Skizzen-Poll 3b-b: ohne verknüpften Lauf kein Live-Poll', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    app(\Platform\FoodAlchemist\Services\IdeenService::class)->add($this->rootTeam, [
        'planning_session_id' => $session->id,
        'title' => 'Nur Entwurf', 'description' => 'Kein Go.',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSee('Nur Entwurf')
        ->assertDontSeeHtml('data-skizzen-poll');
});

/**
 * Etappe 4 — Skizzen-Integration (Teil 3): KI-Divergenz-Skizzen als BATCH-Kaskaden-Eingang. Ein Klick
 * startet für ALLE bearbeitbaren Session-Skizzen je einen gestuften Gericht-Lauf (staged), jeder auf
 * seine Ursprungs-Skizze gestempelt (origin_dish_idea_id → Karte zeigt den Stand, Teil 2b). Kein
 * Cockpit-Hijack — der Stand erscheint je Karte, nicht im Einzel-Cockpit.
 */
it('skizzenBatchAlsGerichte: startet je bearbeitbarer Skizze einen gestuften Gericht-Lauf (gestempelt)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Herbst-Menü']);
    $svc = app(\Platform\FoodAlchemist\Services\IdeenService::class);
    $a = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Rehrücken', 'description' => 'Wild, herbstlich.']);
    $b = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Kürbissuppe', 'description' => 'Cremig.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('skizzenBatchAlsGerichte')
        ->assertSet('fehler', null)
        ->assertSet('laeuft', false)   // kein Cockpit-Hijack — der Stand erscheint je Karte
        ->assertSee('2 Skizzen als Gerichte gestartet');

    $runs = FoodAlchemistCascadeRun::where('team_id', $this->rootTeam->id)->get();
    expect($runs)->toHaveCount(2)
        ->and($runs->every(fn ($r) => $r->scope === 'gericht'))->toBeTrue()
        ->and($runs->every(fn ($r) => (bool) $r->staged === true))->toBeTrue()
        ->and($runs->pluck('origin_dish_idea_id')->map(fn ($v) => (int) $v)->sort()->values()->all())
        ->toBe([(int) $a->id, (int) $b->id]);
    Queue::assertPushed(GenerateRecipeJob::class, 2);
});

it('skizzenBatchAlsGerichte: verworfene + Bestands-Skizzen (sales_recipe_id) werden übersprungen', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    $svc = app(\Platform\FoodAlchemist\Services\IdeenService::class);
    $ok = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Echtes Gericht', 'description' => 'Brief.']);
    $weg = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Verworfen']);
    $svc->setStatus($this->rootTeam, $weg->id, 'verworfen');
    // Bestands-Zeiger (Reuse eines echten VK-Gerichts, kein Generierungs-Brief) → nicht batchbar.
    $bestand = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Bestand']);
    $bestand->update(['sales_recipe_id' => 999999]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('skizzenBatchAlsGerichte')
        ->assertSee('1 Skizzen als Gerichte gestartet');

    $runs = FoodAlchemistCascadeRun::where('team_id', $this->rootTeam->id)->get();
    expect($runs)->toHaveCount(1)
        ->and((int) $runs->first()->origin_dish_idea_id)->toBe((int) $ok->id);
    Queue::assertPushed(GenerateRecipeJob::class, 1);
});

it('skizzenBatchAlsGerichte: ohne bearbeitbare Skizze wird es gesagt (fehler), kein Lauf gestartet', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Leer']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('skizzenBatchAlsGerichte')
        ->assertSet('fehler', 'Keine bearbeitbaren Skizzen — leg oben eine an (Bestands-Übernahmen zählen nicht).');

    expect(FoodAlchemistCascadeRun::where('team_id', $this->rootTeam->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('skizzenBatchAlsGerichte: mehr als der Cap → gedeckelt + gesagt', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Viele']);
    $svc = app(\Platform\FoodAlchemist\Services\IdeenService::class);
    for ($n = 1; $n <= 13; $n++) {
        $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => "Skizze {$n}"]);
    }

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('skizzenBatchAlsGerichte')
        ->assertSee('12 Skizzen als Gerichte gestartet')
        ->assertSee('gedeckelt');

    expect(FoodAlchemistCascadeRun::where('team_id', $this->rootTeam->id)->count())->toBe(12);
    Queue::assertPushed(GenerateRecipeJob::class, 12);
});

/**
 * Etappe 4 — Teil 3b: gezielte AUSWAHL statt „alle". Sind Skizzen angehakt ($skizzenAuswahl), startet
 * der Batch NUR genau diese (Schnittmenge mit den bearbeitbaren); leere Auswahl bleibt „alle".
 */
it('skizzenBatchAlsGerichte: mit Auswahl startet NUR die angehakten Skizzen (Rest unberührt), Auswahl danach verbraucht', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Auswahl-Menü']);
    $svc = app(\Platform\FoodAlchemist\Services\IdeenService::class);
    $a = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Rehrücken', 'description' => 'Wild.']);
    $b = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Kürbissuppe', 'description' => 'Cremig.']);
    $c = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Apfeltarte', 'description' => 'Süß.']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('skizzenAuswahl', [(string) $a->id, (string) $c->id])   // Strings wie aus Livewire-Checkboxen
        ->call('skizzenBatchAlsGerichte')
        ->assertSet('fehler', null)
        ->assertSet('skizzenAuswahl', [])   // nach dem Start verbraucht
        ->assertSee('2 Skizzen als Gerichte gestartet');

    $runs = FoodAlchemistCascadeRun::where('team_id', $this->rootTeam->id)->get();
    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('origin_dish_idea_id')->map(fn ($v) => (int) $v)->sort()->values()->all())
        ->toBe([(int) $a->id, (int) $c->id]);   // b (nicht gewählt) blieb unberührt
    Queue::assertPushed(GenerateRecipeJob::class, 2);
});

it('skizzenBatchAlsGerichte: sind nur nicht-startbare Skizzen angehakt, wird es spezifisch gesagt, kein Lauf', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    $svc = app(\Platform\FoodAlchemist\Services\IdeenService::class);
    $ok = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Startbar', 'description' => 'Brief.']);
    $weg = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Verworfen']);
    $svc->setStatus($this->rootTeam, $weg->id, 'verworfen');

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('skizzenAuswahl', [(string) $weg->id])   // nur die verworfene angehakt
        ->call('skizzenBatchAlsGerichte')
        ->assertSet('fehler', 'Keine der angehakten Skizzen ist startbar (verworfen oder Bestands-Übernahme) — Auswahl prüfen.');

    expect(FoodAlchemistCascadeRun::where('team_id', $this->rootTeam->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('skizzenAuswahlLeeren: hebt die gezielte Auswahl auf', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    $svc = app(\Platform\FoodAlchemist\Services\IdeenService::class);
    $a = $svc->add($this->rootTeam, ['planning_session_id' => $session->id, 'title' => 'Skizze A']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('skizzenAuswahl', [(string) $a->id])
        ->call('skizzenAuswahlLeeren')
        ->assertSet('skizzenAuswahl', []);
});

// ── Brief-Vorlagen je Sektor/Anlass (Etappe 4 — Schnellstart statt Blank Page) ──

it('briefVorlage: füllt Briefing + Sektor/Anlass/Serviceform in den Gericht-Tab, Titel nur wenn leer', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Event']);

    $vorlage = PlanungIndex::BRIEF_VORLAGEN['catering_empfang_flying'];

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('briefVorlage', 'gericht', 'catering_empfang_flying')
        ->assertSet('eingabe.gericht.brief', $vorlage['brief'])
        ->assertSet('eingabe.gericht.titel', $vorlage['titel'])   // war leer → Vorlagen-Titel (hier '')
        ->assertSet('regler.gericht.sektor', 'catering')
        ->assertSet('regler.gericht.occasion', 'empfang')
        ->assertSet('regler.gericht.serviceform', 'flying')
        ->assertSet('fehler', null);
});

it('briefVorlage: überschreibt einen bereits getippten Titel NICHT', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Event']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.gericht.titel', 'Mein Amuse')
        ->call('briefVorlage', 'gericht', 'catering_galadinner')
        ->assertSet('eingabe.gericht.titel', 'Mein Amuse')       // getippter Titel bleibt
        ->assertSet('regler.gericht.sektor', 'catering');        // Kontext wird trotzdem gesetzt
});

it('briefVorlage: unbekannter Key oder falscher Scope → fehler, keine Änderung', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Event']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        // gültiger Key, aber für 'rezept' NICHT freigegeben (Teil 1: nur Gericht)
        ->call('briefVorlage', 'rezept', 'catering_empfang_flying')
        ->assertSet('fehler', 'Unbekannte oder für diesen Tab ungültige Vorlage.')
        ->assertSet('eingabe.rezept.brief', '')
        // unbekannter Key
        ->call('briefVorlage', 'gericht', 'gibts_nicht')
        ->assertSet('fehler', 'Unbekannte oder für diesen Tab ungültige Vorlage.')
        ->assertSet('eingabe.gericht.brief', '');
});

it('vorlagenFuer: Gericht hat Vorlagen, Basisrezept (rezept) hat in Teil 1 keine', function () {
    $comp = Livewire::test(PlanungIndex::class);
    $inst = $comp->instance();

    expect($inst->vorlagenFuer('gericht'))->not->toBeEmpty();
    expect($inst->vorlagenFuer('rezept'))->toBe([]);
    // jede Vorlage nutzt nur reale Leitplanken-Enums (keine erfundenen Sektoren/Anlässe)
    $sektoren = ['betriebsgastronomie', 'catering', 'restaurant', 'care', 'schule_kita'];
    foreach ($inst->vorlagenFuer('gericht') as $v) {
        expect($sektoren)->toContain($v['sektor']);
    }
});

// ── Titel-/Namensvorschlag aus dem Brief (Etappe 4, Teil 3 — UI) ──

it('titelVorschlagen: leeres Titelfeld + Brief → Service-Vorschlag füllt den Titel (empty-only)', function () {
    $this->mock(TitelVorschlagService::class, function ($m) {
        $m->shouldReceive('titelVorschlag')->once()
            ->with('rezept', 'Dunkle Rotwein-Reduktion mit Schalotten.')
            ->andReturn('Sauce: Rotwein-Reduktion');
    });
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.rezept.brief', 'Dunkle Rotwein-Reduktion mit Schalotten.')
        ->call('titelVorschlagen', 'rezept')
        ->assertSet('eingabe.rezept.titel', 'Sauce: Rotwein-Reduktion')
        ->assertSet('fehler', null);
});

it('titelVorschlagen: ein bereits getippter Titel wird NICHT überschrieben (Service nicht gerufen)', function () {
    $this->mock(TitelVorschlagService::class, function ($m) {
        $m->shouldNotReceive('titelVorschlag');    // empty-only Guard greift VOR dem Service
    });
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.gericht.titel', 'Mein Teller')
        ->set('eingabe.gericht.brief', 'Ein Hauptgang.')
        ->call('titelVorschlagen', 'gericht')
        ->assertSet('eingabe.gericht.titel', 'Mein Teller')   // getippter Titel bleibt
        ->assertSet('fehler', null);
});

it('titelVorschlagen: leeres Briefing → fehler, kein Titel (Service nicht gerufen)', function () {
    $this->mock(TitelVorschlagService::class, function ($m) {
        $m->shouldNotReceive('titelVorschlag');    // Brief-leer-Guard greift VOR dem Service
    });
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.rezept.brief', '   ')
        ->call('titelVorschlagen', 'rezept')
        ->assertSet('eingabe.rezept.titel', '')
        ->assertSet('fehler', 'Für den Titelvorschlag erst ein Briefing im Tab eingeben.');
});

it('titelVorschlagen: Service liefert null (KI weg/leer) → fail-soft fehler, kein Titel gefüllt', function () {
    $this->mock(TitelVorschlagService::class, function ($m) {
        $m->shouldReceive('titelVorschlag')->once()->andReturn(null);
    });
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('eingabe.gericht.brief', 'Ein Teller mit unklarem Fokus.')
        ->call('titelVorschlagen', 'gericht')
        ->assertSet('eingabe.gericht.titel', '')
        ->assertSet('fehler', 'Kein Titelvorschlag möglich — bitte das Briefing schärfen oder manuell benennen.');
});

/**
 * Etappe 4, Teil 1 — Trend-Anbindung: eine aus einem Trend eröffnete Session
 * (source_knowledge_document_id gesetzt) muss ihren Brief/Titel ins Go-Briefing je Tab
 * vorbefüllen, sonst erreicht das Trendradar-Signal die Generierung nie (Blank-Briefing-Bug).
 */
it('Trend-Anbindung: Trend-Session-Open befüllt alle Tab-Briefings + Titel (ebenen-spezifischer Lead, Teil 2)', function () {
    // source_knowledge_document_id ist ein loser Zeiger — der Wert muss nicht auf ein echtes
    // Trend-Doc zeigen, damit der Prefill (rein sessionbasiert) greift.
    $session = app(PlanningSessionService::class)->create($this->rootTeam, [
        'title' => 'Fermentierte Chili-Pasten',
        'brief' => 'Aus diesem Food-Trend ein Konzept/Gericht/Basisrezept entwickeln: Fermentierte Chili-Pasten.',
        'source_knowledge_document_id' => 4242,
        'created_via' => 'trend',
    ]);

    $comp = Livewire::test(PlanungIndex::class)->call('oeffne', $session->id);

    // Teil 2: der agnostische Lead wird je Tab auf die Ziel-Ebene geschärft; Titel bleibt gleich.
    $nomen = ['rezept' => 'Basisrezept', 'gericht' => 'Gericht', 'concept' => 'Konzept'];
    foreach ($nomen as $scope => $wort) {
        $comp->assertSet("eingabe.$scope.brief", "Aus diesem Food-Trend ein {$wort} entwickeln: Fermentierte Chili-Pasten.")
            ->assertSet("eingabe.$scope.titel", 'Fermentierte Chili-Pasten');
    }
});

it('Trend-Anbindung: Einordnung/Kernaussage bleiben scope-neutral, nur der Lead wird geschärft (Teil 2)', function () {
    // Ein voll ausgebauter Trend-Brief (Lead + Einordnung + Kernaussage). Nur der Lead trägt die Ebene.
    $brief = "Aus diesem Food-Trend ein Konzept/Gericht/Basisrezept entwickeln: Postbiotic Drinks.\n"
        . "Einordnung: Getränke › Fermentierte Getränke.\n"
        . 'Kernaussage: Fermentation ist ein starker Food-Trend 2026.';
    $session = app(PlanningSessionService::class)->create($this->rootTeam, [
        'title' => 'Postbiotic Drinks',
        'brief' => $brief,
        'source_knowledge_document_id' => 7,
        'created_via' => 'trend',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSet('eingabe.gericht.brief',
            "Aus diesem Food-Trend ein Gericht entwickeln: Postbiotic Drinks.\n"
            . "Einordnung: Getränke › Fermentierte Getränke.\n"
            . 'Kernaussage: Fermentation ist ein starker Food-Trend 2026.')
        ->assertSet('eingabe.concept.brief',
            "Aus diesem Food-Trend ein Konzept entwickeln: Postbiotic Drinks.\n"
            . "Einordnung: Getränke › Fermentierte Getränke.\n"
            . 'Kernaussage: Fermentation ist ein starker Food-Trend 2026.');
});

it('briefFuerScope: ein Brief ohne agnostischen Lead bleibt unverändert (Fallback = Bestandsverhalten)', function () {
    // Edierter/fremder Brief ohne die »ein Konzept/Gericht/Basisrezept entwickeln«-Phrase.
    expect(PlanningSessionService::briefFuerScope('Mein eigener Brief ohne Trend-Lead.', 'gericht'))
        ->toBe('Mein eigener Brief ohne Trend-Lead.')
        // unbekannter Scope → ebenfalls unverändert
        ->and(PlanningSessionService::briefFuerScope(
            'Aus diesem Food-Trend ein Konzept/Gericht/Basisrezept entwickeln: X.', 'foobar'))
        ->toBe('Aus diesem Food-Trend ein Konzept/Gericht/Basisrezept entwickeln: X.');
});

it('Trend-Anbindung: ein bereits getipptes Tab-Briefing wird NICHT überschrieben (empty-only)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, [
        'title' => 'Trend-Titel',
        'brief' => 'Trend-Brief.',
        'source_knowledge_document_id' => 4242,
        'created_via' => 'trend',
    ]);

    // Der Nutzer hat im Gericht-Tab schon etwas getippt; die Concept-/Rezept-Tabs sind leer.
    Livewire::test(PlanungIndex::class)
        ->set('eingabe.gericht.brief', 'Mein eigener Gericht-Brief.')
        ->call('oeffne', $session->id)
        ->assertSet('eingabe.gericht.brief', 'Mein eigener Gericht-Brief.')   // getipptes bleibt
        ->assertSet('eingabe.concept.brief', 'Trend-Brief.')                  // leeres wird gefüllt
        ->assertSet('eingabe.rezept.brief', 'Trend-Brief.');
});

it('Trend-Anbindung: eine Nicht-Trend-Session lässt die Tab-Briefings leer', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, [
        'title' => 'Freie Planung',
        'brief' => 'Session-Brief ohne Trend-Herkunft.',
        // kein source_knowledge_document_id → kein Prefill
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSet('eingabe.gericht.brief', '')
        ->assertSet('eingabe.concept.brief', '')
        ->assertSet('eingabe.rezept.brief', '');
});

/**
 * Etappe 6 — EK/VK/Marge je Stufe im Worker-Cockpit sichtbar (nicht erst nach dem Speichern):
 * Index::render bündelt die abgeleitete Kalkulation (SalesRecipeService::cockpit) je Rezept-/
 * Gericht-Step und die step-zeile rendert eine kompakte EK/VK/Marge-Kachel schon am Draft.
 */
it('Cockpit: Gericht-Step zeigt EK/VK/Marge-Kachel schon am Draft', function () {
    $ak = \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::create([
        'code' => 'ALC', 'label' => 'A la Carte', 'raw_markup_pct' => 420, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);
    // EK 2,50 € + manueller VK 10,00 € → Marge 75,0 % / Wareneinsatz 25,0 %.
    $recipe = $this->makeRecipe($this->rootTeam, 'Marge-Gericht', [
        'is_sales_recipe' => true, 'status' => 'draft',
        'ek_total_eur' => 2.50, 'ek_per_kg_eur' => 5.00, 'sales_net' => 10.00,
        'sales_quantity_per_unit_g' => 250, 'sales_unit_count' => 4,
        'markup_class_id' => $ak->id, 'vat_rate' => 19,
    ]);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Marge']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Marge-Gericht',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-kalkulation="')
        ->assertSeeHtml('2,50')      // EK gesamt
        ->assertSeeHtml('10,00')     // VK netto
        ->assertSeeHtml('75,0')      // Marge %
        ->assertSeeHtml('25,0');     // Wareneinsatz %
});

it('Cockpit: Concept-Step trägt KEINE Rezept-Marge-Kachel (Menü ≠ Rezept)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Menü']);
    $concept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Menü A', 'status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'concept', 'status' => 'review']);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'concept', 'status' => 'done', 'ref_type' => 'concept', 'ref_id' => $concept->id, 'label' => 'Menü A',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('Menü A')                 // Concept-Step rendert (Cockpit ist offen)
        ->assertDontSeeHtml('data-kalkulation="'); // aber KEINE Rezept-Marge-Kachel
});

it('Cockpit: un-bepreister Draft zeigt die Kachel mit „noch nicht bepreist"', function () {
    // Kein EK, kein VK, keine Aufschlagsklasse → Kachel da, aber ehrlich leer statt einer erfundenen Zahl.
    $recipe = $this->makeRecipe($this->rootTeam, 'Roh-Gericht', [
        'is_sales_recipe' => true, 'status' => 'draft', 'ek_total_eur' => null, 'sales_net' => null,
    ]);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Roh']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Roh-Gericht',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-kalkulation="')
        ->assertSeeHtml('noch nicht bepreist');
});

/*
 * Etappe 6 — unvollständige Bepreisung sichtbar markieren (EK unvollständig):
 * »teil-unbepreist« = EK IST da, aber nicht alle costed Zutaten tragen einen Preis
 * (die Lücken tragen 0 € → EK/Marge zu günstig). Kanonische Wahrheit aus
 * DataQualityService: ek_total_eur != null && ek_n_ingredients_priced < ..._total.
 */
it('Cockpit: teil-unbepreistes Gericht wird trotz gesunder Marge als »EK teil-unbepreist« markiert', function () {
    $ak = \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::create([
        'code' => 'ALC2', 'label' => 'A la Carte', 'raw_markup_pct' => 420, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);
    // EK 2,50 € da + hübsche Marge 75,0 % — ABER nur 2 von 3 Zutaten bepreist: die gezeigte
    // Zahl unterschätzt den echten Wareneinsatz. Genau der still irreführende Fall.
    $recipe = $this->makeRecipe($this->rootTeam, 'Teil-Gericht', [
        'is_sales_recipe' => true, 'status' => 'draft',
        'ek_total_eur' => 2.50, 'ek_per_kg_eur' => 5.00, 'sales_net' => 10.00,
        'sales_quantity_per_unit_g' => 250, 'sales_unit_count' => 4,
        'markup_class_id' => $ak->id, 'vat_rate' => 19,
        'ek_n_ingredients_total' => 3, 'ek_n_ingredients_priced' => 2,
    ]);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Teil']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Teil-Gericht',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('EK teil-unbepreist')
        ->assertSeeHtml('Nur 2 von 3 Zutaten bepreist')  // Tooltip trägt die Zahlen
        ->assertSeeHtml('75,0');                          // Marge steht daneben — kein elseif, beides sichtbar
});

it('Cockpit: voll bepreistes Gericht (priced == total) trägt KEINEN teil-unbepreist-Marker', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Voll-Gericht', [
        'is_sales_recipe' => true, 'status' => 'draft',
        'ek_total_eur' => 2.50, 'ek_per_kg_eur' => 5.00, 'sales_net' => 10.00,
        'ek_n_ingredients_total' => 3, 'ek_n_ingredients_priced' => 3,
    ]);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Voll']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Voll-Gericht',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-kalkulation="')     // Kachel da
        ->assertDontSeeHtml('EK teil-unbepreist'); // aber kein Marker
});

it('Cockpit: fehlende Zähler (nie recomputet) markieren NICHT teil-unbepreist (keine unbelegte Behauptung)', function () {
    // EK gesetzt, aber die Zutaten-Zähler NULL (Draft noch nicht durchgerechnet) → wir behaupten
    // keine Unvollständigkeit, die wir nicht belegen können.
    $recipe = $this->makeRecipe($this->rootTeam, 'Zaehlerlos-Gericht', [
        'is_sales_recipe' => true, 'status' => 'draft',
        'ek_total_eur' => 2.50, 'ek_per_kg_eur' => 5.00, 'sales_net' => 10.00,
        'ek_n_ingredients_total' => null, 'ek_n_ingredients_priced' => null,
    ]);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Zaehlerlos']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Zaehlerlos-Gericht',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-kalkulation="')
        ->assertDontSeeHtml('EK teil-unbepreist');
});

/*
|--------------------------------------------------------------------------
| Etappe 7 — Kosten-Transparenz je Call: die bei der Anreicherung erzeugten
| KI-Fotos werden je Call in foodalchemist_ai_call_log protokolliert
| (RecipeImageService). Das Cockpit hebt die Zahl der kostenpflichtigen
| Bild-Calls + das Modell an den Rezept-/Gericht-Draft — KEIN EUR-Betrag
| (keine Preisquelle im Code → wäre Erfindung).
|--------------------------------------------------------------------------
*/

/** Loggt einen KI-Bild-Call auf ein neu angelegtes Foto des Rezepts (spiegelt RecipeImageService::logCall). */
function seedBildCall(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe, int $teamId, string $feature, string $model = 'gpt-image-1.5'): void
{
    $foto = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $recipe->team_id, 'recipe_id' => $recipe->id, 'pfad' => 'foto-' . uniqid() . '.jpg',
    ]);
    \Illuminate\Support\Facades\DB::table('foodalchemist_ai_call_log')->insert([
        'uuid' => (string) \Illuminate\Support\Str::orderedUuid(),
        'team_id' => $teamId, 'user_id' => null, 'feature' => $feature, 'tier' => 'I', 'model' => $model,
        'prompt_hash' => hash('sha256', $feature), 'response_summary' => $feature,
        'tokens_in' => 0, 'tokens_out' => 0,
        'target_table' => 'foodalchemist_recipe_step_photos', 'target_id' => $foto->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('Cockpit: Gericht-Draft mit KI-Fotos zeigt die Zahl der kostenpflichtigen Bild-Calls + Modell', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Foto-Gericht', ['is_sales_recipe' => true, 'status' => 'draft']);
    // Ein Produktfoto + ein Schrittfoto = 2 kostenpflichtige Calls.
    seedBildCall($recipe, $this->rootTeam->id, \Platform\FoodAlchemist\Services\RecipeImageService::FEATURE_PRODUKTFOTO);
    seedBildCall($recipe, $this->rootTeam->id, \Platform\FoodAlchemist\Services\RecipeImageService::FEATURE_SCHRITTFOTOS);

    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Foto']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Foto-Gericht',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-bild-calls="')
        ->assertSeeHtml('2 KI-Bild-Calls')       // Anzahl (Plural)
        ->assertSeeHtml('gpt-image-1.5');        // Modell (Kosten-Transparenz, kein EUR)
});

it('Cockpit: Draft OHNE KI-Fotos trägt keinen Bild-Call-Hinweis', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Fotolos-Gericht', ['is_sales_recipe' => true, 'status' => 'draft']);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Fotolos']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Fotolos-Gericht',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertDontSeeHtml('data-bild-calls="');
});

it('Cockpit: fremd-Team-geloggte Bild-Calls zählen NICHT (Kosten dieses Teams)', function () {
    // Rezept gehört rootTeam, der Bild-Call ist aber unter einem anderen Team protokolliert →
    // im rootTeam-Cockpit unsichtbar (keine team-übergreifende Kosten-Vermischung).
    $recipe = $this->makeRecipe($this->rootTeam, 'Fremd-Foto-Gericht', ['is_sales_recipe' => true, 'status' => 'draft']);
    seedBildCall($recipe, $this->childB->id, \Platform\FoodAlchemist\Services\RecipeImageService::FEATURE_PRODUKTFOTO);

    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Fremd']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Fremd-Foto-Gericht',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertDontSeeHtml('data-bild-calls="');
});

/*
|--------------------------------------------------------------------------
| Etappe 7 — Bild-Status im Cockpit (Teil 1): erzeugt / angefordert-aber-leer,
| analog zum Anreicherungs-Badge. Ehrlich ableitbar OHNE neue Persistenz:
| run-level `ki_bilder` (angefordert) + deferred.enrich=done (Job durch, Fotos
| laufen darin danach) + real existierende Fotos. »0 Fotos trotz angefordert«
| = nichts erzeugt — KEIN erfundenes »fehlgeschlagen«-Badge (Bild-Erzeugung ist
| still fail-soft, kein Fehler-Zustand protokolliert). Actual-Fotos statt Calls.
|--------------------------------------------------------------------------
*/

// Legt N reale Fotos für ein Rezept an (ohne Kosten-Call — für den »erzeugt«-Status).
function seedFotos(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::create([
            'team_id' => $recipe->team_id, 'recipe_id' => $recipe->id, 'pfad' => 'foto-' . uniqid() . '.jpg',
        ]);
    }
}

// review-Lauf mit einem freigegebenen (enrich=done) Gericht-Step; ki_bilder am Run steuerbar.
function bildStatusRun($team, \Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe, bool $kiBilder): \Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession
{
    $session = app(PlanningSessionService::class)->create($team, ['title' => 'Bild-Status']);
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $team->id, 'planning_session_id' => $session->id,
        'scope' => 'gericht', 'status' => 'review', 'params' => ['ki_bilder' => $kiBilder],
    ]);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $team->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id,
        'label' => $recipe->name, 'deferred' => ['enrich' => ['status' => 'done']],
    ]);

    return $session;
}

it('Cockpit Bild-Status: angefordert + Fotos vorhanden zeigt »N Fotos ✓«', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-OK', ['is_sales_recipe' => true, 'status' => 'draft']);
    seedFotos($recipe, 2);
    $session = bildStatusRun($this->rootTeam, $recipe, true);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-bild-status="')
        ->assertSeeHtml('2 Fotos ✓')
        ->assertDontSeeHtml('keine Fotos erzeugt');
});

it('Cockpit Bild-Status: angefordert, aber 0 Fotos zeigt ehrlich »keine Fotos erzeugt« (kein Fehler-Fake)', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-Leer', ['is_sales_recipe' => true, 'status' => 'draft']);
    $session = bildStatusRun($this->rootTeam, $recipe, true);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-bild-status="')
        ->assertSeeHtml('keine Fotos erzeugt');
});

it('Cockpit Bild-Status: OHNE ki_bilder-Anforderung trägt keinen Bild-Status', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-Aus', ['is_sales_recipe' => true, 'status' => 'draft']);
    seedFotos($recipe, 1); // selbst mit Bestandsfoto: nicht angefordert → kein Status
    $session = bildStatusRun($this->rootTeam, $recipe, false);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertDontSeeHtml('data-bild-status="');
});

/*
|--------------------------------------------------------------------------
| Etappe 7 — Bild-Status Teil 2: explizite Fehler-Persistenz. Der EnrichRecipeJob
| hält das Bild-Ergebnis jetzt in deferred.bilder fest (status done|failed + n) →
| ein echter »fehlgeschlagen«-Zustand statt des stummen 0-Foto-Fallbacks. Fehlt
| deferred.bilder (Alt-Läufe), greift weiter die Teil-1-Foto-Zähl-Logik (oben).
|--------------------------------------------------------------------------
*/

// review-Lauf mit freigegebenem (enrich=done) Gericht-Step + persistiertem deferred.bilder.
function bildStatusRunMitBilder($team, \Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe, array $bilder): \Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession
{
    $session = app(PlanningSessionService::class)->create($team, ['title' => 'Bild-Status Teil 2']);
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $team->id, 'planning_session_id' => $session->id,
        'scope' => 'gericht', 'status' => 'review', 'params' => ['ki_bilder' => true],
    ]);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $team->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id,
        'label' => $recipe->name, 'deferred' => ['enrich' => ['status' => 'done'], 'bilder' => $bilder],
    ]);

    return $session;
}

it('Cockpit Bild-Status: deferred.bilder=failed zeigt »Fotos fehlgeschlagen« (Teil 2)', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-Failed', ['is_sales_recipe' => true, 'status' => 'draft']);
    $session = bildStatusRunMitBilder($this->rootTeam, $recipe, ['status' => 'failed', 'error' => 'API down', 'n' => 0]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('data-bild-status="')
        ->assertSeeHtml('Fotos fehlgeschlagen')
        ->assertDontSeeHtml('keine Fotos erzeugt');
});

it('Cockpit Bild-Status: Teil-Fehler (failed + n>0) zeigt »Fotos fehlgeschlagen (N ok)«', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-Teil', ['is_sales_recipe' => true, 'status' => 'draft']);
    seedFotos($recipe, 2); // Produktfoto ok, ein Schritt kippte
    $session = bildStatusRunMitBilder($this->rootTeam, $recipe, ['status' => 'failed', 'error' => 'ein Schritt kippte', 'n' => 2]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('Fotos fehlgeschlagen (2 ok)');
});

it('Cockpit Bild-Status: deferred.bilder=done fällt auf »N Fotos ✓« zurück (kein Fehler-Badge)', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-Done', ['is_sales_recipe' => true, 'status' => 'draft']);
    seedFotos($recipe, 3);
    $session = bildStatusRunMitBilder($this->rootTeam, $recipe, ['status' => 'done', 'n' => 3]);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('3 Fotos ✓')
        ->assertDontSeeHtml('Fotos fehlgeschlagen');
});

// Etappe 7 Teil 2b: „neu erzeugen" — das failed-Badge bietet einen Knopf, der NUR die KI-Fotos
// re-triggert (EnrichRecipeJob im nurBilder-Modus), ohne Voll-Anreicherung.

it('Cockpit Bild-Status: failed-Badge bietet „neu erzeugen" und re-triggert nur die Fotos (nurBilder)', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-Retrigger', ['is_sales_recipe' => true, 'status' => 'draft']);
    $session = bildStatusRunMitBilder($this->rootTeam, $recipe, ['status' => 'failed', 'error' => 'API down', 'n' => 0]);
    $step = FoodAlchemistCascadeRunStep::where('ref_id', $recipe->id)->where('ref_type', 'recipe')->firstOrFail();

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('neu erzeugen')
        ->assertSeeHtml('wire:click="bilderNeu(' . $step->id . ')"')
        ->call('bilderNeu', $step->id);

    expect($step->refresh()->deferred['bilder']['status'] ?? null)->toBe('queued');
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\EnrichRecipeJob::class,
        fn ($job) => (int) $job->recipeId === (int) $recipe->id && $job->nurBilder === true);
});

it('Cockpit Bild-Status: deferred.bilder=queued zeigt Spinner »erzeugt Fotos …« (Poll aktiv)', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Bild-Queued', ['is_sales_recipe' => true, 'status' => 'draft']);
    $session = bildStatusRunMitBilder($this->rootTeam, $recipe, ['status' => 'queued']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertSeeHtml('erzeugt Fotos …')
        ->assertDontSeeHtml('Fotos fehlgeschlagen');
});

/*
|--------------------------------------------------------------------------
| Etappe 6 — Margen-Gate: Warnung bei Freigabe unter Aufschlagsklasse
|--------------------------------------------------------------------------
| „unter Aufschlagsklasse" = ein MANUELLER VK, der den Klassen-Vorschlag
| unterschreitet. Reine Rückkopplung (Warnung), keine harte Sperre — der
| Mensch entscheidet (Nordstern). Reuse SalesRecipeService::cockpit.
*/

// Baut einen review-Lauf mit je einem done-Gericht-Step pro Rezept.
$margenRun = function ($team, array $recipes) {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $team->id, 'scope' => 'concept', 'status' => 'review', 'staged' => true]);
    foreach ($recipes as $r) {
        FoodAlchemistCascadeRunStep::create([
            'team_id' => $team->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done',
            'ref_type' => 'recipe', 'ref_id' => $r->id,
        ]);
    }

    return $run;
};

it('Margen-Gate: Freigabe mit VK unter Klassen-Vorschlag setzt eine sichtbare Warnung (nur die Unter-Position)', function () use ($margenRun) {
    // Klasse ALC 300 % → Vorschlag = ek_basis × 4. ek_per_kg 10 €/kg × 250 g = 2,50 € basis → 10,00 € Vorschlag.
    $alc = FoodAlchemistMarkupClass::create(['code' => 'ALC', 'label' => 'A la Carte', 'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);
    $unter = $this->makeRecipe($this->rootTeam, 'Unter-Gericht', [
        'status' => 'draft', 'markup_class_id' => $alc->id, 'ek_per_kg_eur' => 10, 'ek_total_eur' => 4.0,
        'sales_quantity_per_unit_g' => 250, 'sales_net' => 6.00,   // manuell 6,00 < Vorschlag 10,00 → drunter
    ]);
    $sauber = $this->makeRecipe($this->rootTeam, 'Sauber-Gericht', [
        'status' => 'draft', 'markup_class_id' => $alc->id, 'ek_per_kg_eur' => 10, 'ek_total_eur' => 4.0,
        'sales_quantity_per_unit_g' => 250, 'sales_net' => 14.00,  // manuell 14,00 > Vorschlag 10,00 → sauber
    ]);
    $run = $margenRun($this->rootTeam, [$unter, $sauber]);

    $comp = Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->call('gibStufeFrei', 'gericht')
        ->assertSeeHtml('data-margen-warnung');

    $w = $comp->get('margenWarnung');
    expect($w)->not->toBeNull()
        ->and($w)->toContain('1 Position unter Aufschlagsklasse')
        ->and($w)->toContain('Unter-Gericht')
        ->and($w)->not->toContain('Sauber-Gericht');   // die saubere Position taucht in der Warnung nicht auf
});

it('Margen-Gate: saubere Freigabe (alle auf/über Klasse) → keine Warnung', function () use ($margenRun) {
    $alc = FoodAlchemistMarkupClass::create(['code' => 'ALC', 'label' => 'A la Carte', 'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);
    $a = $this->makeRecipe($this->rootTeam, 'Klar-A', [
        'status' => 'draft', 'markup_class_id' => $alc->id, 'ek_per_kg_eur' => 10, 'ek_total_eur' => 4.0,
        'sales_quantity_per_unit_g' => 250, 'sales_net' => 12.00,  // > Vorschlag 10,00
    ]);
    $b = $this->makeRecipe($this->rootTeam, 'Klar-B', [
        'status' => 'draft', 'markup_class_id' => $alc->id, 'ek_per_kg_eur' => 10, 'ek_total_eur' => 4.0,
        'sales_quantity_per_unit_g' => 250, 'sales_net' => 10.00,  // exakt auf Vorschlag → nicht drunter
    ]);
    $run = $margenRun($this->rootTeam, [$a, $b]);

    Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->call('gibStufeFrei', 'gericht')
        ->assertSet('margenWarnung', null)
        ->assertDontSeeHtml('data-margen-warnung');
});

it('Margen-Gate: Auto-VK (source=class) und fehlender Vorschlag lösen KEINE Warnung aus (nicht geraten)', function () use ($margenRun) {
    $alc = FoodAlchemistMarkupClass::create(['code' => 'ALC', 'label' => 'A la Carte', 'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);
    // (1) Auto-VK: kein manueller sales_net → source=class → trifft die Klasse exakt, nie drunter.
    $auto = $this->makeRecipe($this->rootTeam, 'Auto-Gericht', [
        'status' => 'draft', 'markup_class_id' => $alc->id, 'ek_per_kg_eur' => 10, 'ek_total_eur' => 4.0,
        'sales_quantity_per_unit_g' => 250, 'sales_net' => null,
    ]);
    // (2) Kein EK/keine Portionierung → kein Klassen-Vorschlag → keine Schwelle, trotz niedrigem manuellem VK.
    $ohneSchwelle = $this->makeRecipe($this->rootTeam, 'Ohne-Schwelle', [
        'status' => 'draft', 'markup_class_id' => $alc->id, 'ek_per_kg_eur' => null,
        'sales_quantity_per_unit_g' => null, 'sales_net' => 1.00,
    ]);
    $run = $margenRun($this->rootTeam, [$auto, $ohneSchwelle]);

    Livewire::test(PlanungIndex::class)
        ->set('laufId', $run->id)
        ->call('gibStufeFrei', 'gericht')
        ->assertSet('margenWarnung', null);
});

/*
|--------------------------------------------------------------------------
| Etappe 7 Teil 2 — manueller Foto-Upload im Cockpit: die NICHT-KI-Alternative
| zur Bild-Erzeugung, neben „neu erzeugen". Empty-only, kein KI-Call, „als
| Ergebnis" = Hero (max. 1). Verdrahtet fotoHochladen → uebernimmManuellesFotoFuerStep.
|--------------------------------------------------------------------------
*/

// Hinweis: makeRecipe ist protected (TestCase-Trait) → der Step-Aufbau wird je Test INLINE im
// Test-Case-Scope gebaut, nicht über einen globalen Helfer (der kann protected nicht rufen).

it('Cockpit-Upload: manuelles Foto als Pool-Foto übernehmen (kein KI-Call)', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $recipe = $this->makeRecipe($this->rootTeam, 'Upload-Pool', ['is_sales_recipe' => true, 'status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Upload-Pool',
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('fotoUploads.' . $step->id, \Illuminate\Http\UploadedFile::fake()->image('teller.jpg'))
        ->call('fotoHochladen', $step->id, false)
        ->assertSet('fehler', null);

    $fotos = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $recipe->id)->get();
    expect($fotos)->toHaveCount(1)
        ->and((bool) $fotos->first()->is_result)->toBeFalse();

    // Kein Kosten-Call-Log (kein KI-Call).
    expect(\Illuminate\Support\Facades\DB::table('foodalchemist_ai_call_log')
        ->where('target_table', 'foodalchemist_recipe_step_photos')
        ->where('target_id', $fotos->first()->id)->exists())->toBeFalse();
});

it('Cockpit-Upload: manuelles Foto als Ergebnis-/Hero-Foto (is_result)', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $recipe = $this->makeRecipe($this->rootTeam, 'Upload-Hero', ['is_sales_recipe' => true, 'status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Upload-Hero',
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('fotoUploads.' . $step->id, \Illuminate\Http\UploadedFile::fake()->image('hero.jpg'))
        ->call('fotoHochladen', $step->id, true)
        ->assertSet('fehler', null);

    $foto = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $recipe->id)->first();
    expect((bool) $foto->is_result)->toBeTrue();
});

it('Cockpit-Upload: ohne gewählte Datei passiert nichts (empty-only, gesagt)', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $recipe = $this->makeRecipe($this->rootTeam, 'Upload-Leer', ['is_sales_recipe' => true, 'status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id, 'label' => 'Upload-Leer',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('fotoHochladen', $step->id, false)
        ->assertSet('fehler', 'Kein Foto gewählt.');

    expect(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $recipe->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Etappe 7 Teil 3b — Foto-Wiederverwendungs-Picker im Cockpit: ein vorhandenes
| Team-Foto (aus einem anderen Rezept) COPY-ON-REUSE auf den Draft übernehmen,
| statt neu hochzuladen. Kein KI-Call → überlebt den KI-Re-Trigger-Purge.
| Verdrahtet fotoUebernehmen → uebernimmVorhandenesFotoFuerStep (Teil 3a-Primitive).
|--------------------------------------------------------------------------
*/

it('Reuse-Picker: zeigt vorhandene Team-Fotos, schliesst die eigenen Rezept-Fotos aus', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $img = app(\Platform\FoodAlchemist\Services\RecipeImageService::class);
    // Quell-Rezept (woanders) mit einem wiederverwendbaren Foto …
    $quellRezept = $this->makeRecipe($this->rootTeam, 'Reuse-Quelle');
    $quelleFoto = $img->uebernimmManuellesFoto($this->rootTeam, $quellRezept, \Illuminate\Http\UploadedFile::fake()->image('quelle.jpg'));
    // … und das Ziel-Rezept mit einem EIGENEN Foto (darf NICHT als Kandidat auftauchen).
    $zielRezept = $this->makeRecipe($this->rootTeam, 'Reuse-Ziel', ['is_sales_recipe' => true, 'status' => 'draft']);
    $eigenesFoto = $img->uebernimmManuellesFoto($this->rootTeam, $zielRezept, \Illuminate\Http\UploadedFile::fake()->image('eigen.jpg'));
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $zielRezept->id, 'label' => 'Reuse-Ziel',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('fotoPickerOeffnen', $step->id)
        ->assertSet('fotoPickerStep', $step->id)
        ->assertViewHas('fotoPickerKandidaten', function ($kand) use ($quelleFoto, $eigenesFoto) {
            $ids = array_column($kand, 'id');

            return in_array((int) $quelleFoto->id, $ids, true)      // Fremd-Rezept-Foto = Kandidat
                && ! in_array((int) $eigenesFoto->id, $ids, true);  // eigenes Rezept-Foto ausgeschlossen
        })
        ->call('fotoPickerSchliessen')
        ->assertSet('fotoPickerStep', null);
});

it('Reuse-Picker: vorhandenes Foto als Pool-Foto übernehmen (Kopie, kein KI-Call, schliesst Picker)', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $img = app(\Platform\FoodAlchemist\Services\RecipeImageService::class);
    $quellRezept = $this->makeRecipe($this->rootTeam, 'Reuse-Q2');
    $quelleFoto = $img->uebernimmManuellesFoto($this->rootTeam, $quellRezept, \Illuminate\Http\UploadedFile::fake()->image('q2.jpg'));
    $zielRezept = $this->makeRecipe($this->rootTeam, 'Reuse-Z2', ['is_sales_recipe' => true, 'status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $zielRezept->id, 'label' => 'Reuse-Z2',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('fotoPickerOeffnen', $step->id)
        ->call('fotoUebernehmen', $step->id, $quelleFoto->id, false)
        ->assertSet('fehler', null)
        ->assertSet('fotoPickerStep', null);   // nach der Übernahme zu

    $kopie = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $zielRezept->id)->first();
    expect($kopie)->not->toBeNull()
        ->and((bool) $kopie->is_result)->toBeFalse()
        // COPY-ON-REUSE: frische ContextFile, NICHT der geteilte Quell-context_file_id.
        ->and((int) $kopie->context_file_id)->not->toBe((int) $quelleFoto->context_file_id)
        // Quelle unangetastet.
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::whereKey($quelleFoto->id)->exists())->toBeTrue();
    // Kein Kosten-Call-Log (kein KI-Call) → überlebt loescheKiFotos.
    expect(\Illuminate\Support\Facades\DB::table('foodalchemist_ai_call_log')
        ->where('target_table', 'foodalchemist_recipe_step_photos')
        ->where('target_id', $kopie->id)->exists())->toBeFalse();
});

it('Reuse-Picker: vorhandenes Foto als Ergebnis-/Hero-Foto übernehmen (is_result)', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $img = app(\Platform\FoodAlchemist\Services\RecipeImageService::class);
    $quellRezept = $this->makeRecipe($this->rootTeam, 'Reuse-Q3');
    $quelleFoto = $img->uebernimmManuellesFoto($this->rootTeam, $quellRezept, \Illuminate\Http\UploadedFile::fake()->image('q3.jpg'));
    $zielRezept = $this->makeRecipe($this->rootTeam, 'Reuse-Z3', ['is_sales_recipe' => true, 'status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $zielRezept->id, 'label' => 'Reuse-Z3',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('fotoUebernehmen', $step->id, $quelleFoto->id, true)
        ->assertSet('fehler', null);

    $kopie = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $zielRezept->id)->first();
    expect((bool) $kopie->is_result)->toBeTrue();
});

it('Reuse-Picker: fremd-Team-Quell-Foto wird nicht übernommen (fail-soft, kein Leak)', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $img = app(\Platform\FoodAlchemist\Services\RecipeImageService::class);
    // Ein eigenständiges Fremd-Team (nicht in der Ancestry von rootTeam) → visibleToTeam findet es nicht.
    $fremdTeam = \Platform\Core\Models\Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $fremdRezept = $this->makeRecipe($fremdTeam, 'Fremd-Rezept');
    $fremdFoto = $img->uebernimmManuellesFoto($fremdTeam, $fremdRezept, \Illuminate\Http\UploadedFile::fake()->image('fremd.jpg'));
    $zielRezept = $this->makeRecipe($this->rootTeam, 'Reuse-Z4', ['is_sales_recipe' => true, 'status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $zielRezept->id, 'label' => 'Reuse-Z4',
    ]);

    Livewire::test(PlanungIndex::class)
        ->call('fotoUebernehmen', $step->id, $fremdFoto->id, false)
        ->assertSet('fehler', fn ($f) => $f !== null);   // gesagt, nicht still verschluckt

    // Kein Leak: das Ziel-Rezept hat kein Foto bekommen.
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $zielRezept->id)->count())->toBe(0);
});

/*
 * Etappe 8 »Worker-Präsenz« Teil 2 — Health-Anzeige im Cockpit: die proaktive Ampel des
 * WorkerHealthService (Teil 1) VOR dem Go. Ohne lebenden `queue:work` bleibt jeder Go in der
 * Queue liegen → der Nutzer soll das SEHEN, statt nur einen Spinner. Ergänzt den reaktiven
 * Watchdog-`hinweis` (der erst nach ~90 s eines hängenden Laufs anschlägt).
 */
it('Worker-Präsenz Teil 2: kein Herzschlag (unbekannt) → proaktive Warnung im Go zeigen', function () {
    \Illuminate\Support\Facades\Cache::forget(\Platform\FoodAlchemist\Services\WorkerHealthService::HEARTBEAT_KEY);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'W', 'brief' => 'y']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertViewHas('workerState', 'unbekannt')       // nie ein Herzschlag gesehen
        ->assertSeeHtml('data-worker-health');        // Warn-Banner am Go
});

it('Worker-Präsenz Teil 2: frischer Herzschlag (gesund) → keine Warnung (Fläche unverändert)', function () {
    \Illuminate\Support\Facades\Cache::put(
        \Platform\FoodAlchemist\Services\WorkerHealthService::HEARTBEAT_KEY,
        now()->timestamp,
        \Platform\FoodAlchemist\Services\WorkerHealthService::HEARTBEAT_TTL_SEKUNDEN
    );
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'W', 'brief' => 'y']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertViewHas('workerState', 'gesund')
        ->assertViewHas('workerWarnung', null)
        ->assertDontSeeHtml('data-worker-health');
});

it('Worker-Präsenz Teil 2: alter Herzschlag (still) → proaktive Warnung im Go zeigen', function () {
    \Illuminate\Support\Facades\Cache::put(
        \Platform\FoodAlchemist\Services\WorkerHealthService::HEARTBEAT_KEY,
        now()->timestamp - (\Platform\FoodAlchemist\Services\WorkerHealthService::STILL_SEKUNDEN + 30),
        \Platform\FoodAlchemist\Services\WorkerHealthService::HEARTBEAT_TTL_SEKUNDEN
    );
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'W', 'brief' => 'y']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->assertViewHas('workerState', 'still')
        ->assertSeeHtml('data-worker-health');
});

// ── Multi-Tenancy Slice 2 (Etappe 8): Read-Audit der Livewire-Client-Actions ──────────
// Die id-tragenden Cockpit-Properties (`laufId`, `fotoPickerStep`) sind PUBLIC und ungelockt
// → der Client kann sie auf eine FREMDE (nicht team-sichtbare) id setzen. Slice 1 (`3ceaf6c`)
// pinnte die step-mutierenden SERVICE-Methoden gegen einen geerbten Fremd-Step (Writes isOwnedBy).
// Dieser Datensatz pinnt die READ-Seite der Fläche: jede lauf-/step-id-Action reicht die id an
// eine team-gescopte Service-Lese (`lauf()` = visibleToTeam) bzw. render() liest den Step mit
// `where team_id` → eine getürkte Fremd-id kann weder mutieren noch etwas enthüllen. childA ist
// aus Root-Sicht ein KIND → dessen eigene Läufe/Steps sind für rootTeam NICHT sichtbar (Parent
// sieht die Kinder nicht), also der saubere „fremd + unsichtbar"-Fall.

// Run-Ebene: eine auf einen fremden (unsichtbaren) Lauf getürkte `laufId` lässt jede Run-Action
// verpuffen — kein Job, keine Zustandsänderung am Fremd-Lauf (Service no-op über `lauf()`=null).
it('Slice 2 / D1-Read: getürkte laufId auf fremden Lauf → Run-Action verpufft (kein Cross-Tenant-Write)', function (Closure $action) {
    $fremdRezept = $this->makeRecipe($this->childA, 'Fremd-Gericht', ['status' => 'draft']);
    $fremdRun = FoodAlchemistCascadeRun::create(['team_id' => $this->childA->id, 'scope' => 'gericht', 'status' => 'review']);
    $done = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->childA->id, 'cascade_run_id' => $fremdRun->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $fremdRezept->id,
    ]);
    $failed = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->childA->id, 'cascade_run_id' => $fremdRun->id, 'kind' => 'rezept', 'status' => 'failed',
    ]);

    // Als rootTeam (beforeEach) den fremden Lauf als laufId setzen und die Action feuern.
    $action(Livewire::test(PlanungIndex::class)->set('laufId', (int) $fremdRun->id));

    // Nichts passiert: kein Generator-/Freigabe-Job, Fremd-Steps + Fremd-Rezept unangetastet.
    Queue::assertNothingPushed();
    expect($done->refresh()->status)->toBe('done')
        ->and($failed->refresh()->status)->toBe('failed')
        ->and($fremdRezept->refresh()->status->value)->toBe('draft');
})->with([
    'alleFrei' => [fn ($lw) => $lw->call('alleFrei')],
    'alleVerwerfen' => [fn ($lw) => $lw->call('alleVerwerfen')],
    'laufFortsetzen' => [fn ($lw) => $lw->call('laufFortsetzen')],
    'laufWiederAufnehmen' => [fn ($lw) => $lw->call('laufWiederAufnehmen')],
]);

// render(): eine getürkte Fremd-`laufId` lädt keinen Lauf ins Cockpit (visibleToTeam → null).
it('Slice 2 / D1-Read: getürkte laufId auf fremden Lauf → render lädt keinen Lauf', function () {
    $fremdRun = FoodAlchemistCascadeRun::create(['team_id' => $this->childA->id, 'scope' => 'gericht', 'status' => 'running']);

    Livewire::test(PlanungIndex::class)
        ->set('laufId', (int) $fremdRun->id)
        ->assertViewHas('lauf', null);
});

// Foto-Picker: der EINZIGE Roh-id-Read der Fläche (`fotoPickerOeffnen` setzt die id ungeprüft) —
// render() liest den Step aber mit `where team_id` → eine Fremd-Step-id enthüllt NICHTS, obwohl
// ein team-sichtbares Kandidaten-Foto existiert (Kontrast: derselbe Read auf einen EIGENEN Step
// listet dieses Foto sehr wohl).
it('Slice 2 / D1-Read: Foto-Picker auf EIGENEN Step listet team-sichtbare Kandidaten', function () {
    $zielRezept = $this->makeRecipe($this->rootTeam, 'Ziel-Draft', ['status' => 'draft']);
    $fotoRezept = $this->makeRecipe($this->rootTeam, 'Anderes mit Foto', ['status' => 'approved']);
    FoodAlchemistRecipeStepPhoto::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $fotoRezept->id, 'pfad' => 'a/b.jpg']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review']);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $zielRezept->id,
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('fotoPickerStep', (int) $step->id)
        ->assertViewHas('fotoPickerKandidaten', fn ($k) => count($k) === 1 && $k[0]['rezept'] === 'Anderes mit Foto');
});

it('Slice 2 / D1-Read: Foto-Picker auf FREMDEN Step → keine Kandidaten (Roh-id-Read team-gescopt)', function () {
    // Ein team-sichtbares Foto existiert — würde es am Step-Scope NICHT scheitern, käme es durch.
    $fotoRezept = $this->makeRecipe($this->rootTeam, 'Root-Foto', ['status' => 'approved']);
    FoodAlchemistRecipeStepPhoto::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $fotoRezept->id, 'pfad' => 'a/b.jpg']);
    // Fremder (unsichtbarer) Step aus childA.
    $fremdRezept = $this->makeRecipe($this->childA, 'Fremd', ['status' => 'draft']);
    $fremdRun = FoodAlchemistCascadeRun::create(['team_id' => $this->childA->id, 'scope' => 'gericht', 'status' => 'review']);
    $fremdStep = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->childA->id, 'cascade_run_id' => $fremdRun->id,
        'kind' => 'gericht', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $fremdRezept->id,
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('fotoPickerStep', (int) $fremdStep->id)
        ->assertViewHas('fotoPickerKandidaten', []);
});
