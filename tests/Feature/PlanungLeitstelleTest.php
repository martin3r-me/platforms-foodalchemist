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
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
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

/**
 * Etappe 4, Teil 1 — Trend-Anbindung: eine aus einem Trend eröffnete Session
 * (source_knowledge_document_id gesetzt) muss ihren Brief/Titel ins Go-Briefing je Tab
 * vorbefüllen, sonst erreicht das Trendradar-Signal die Generierung nie (Blank-Briefing-Bug).
 */
it('Trend-Anbindung: Trend-Session-Open befüllt alle Tab-Briefings + Titel', function () {
    // source_knowledge_document_id ist ein loser Zeiger — der Wert muss nicht auf ein echtes
    // Trend-Doc zeigen, damit der Prefill (rein sessionbasiert) greift.
    $session = app(PlanningSessionService::class)->create($this->rootTeam, [
        'title' => 'Fermentierte Chili-Pasten',
        'brief' => 'Aus diesem Food-Trend ein Konzept/Gericht/Basisrezept entwickeln: Fermentierte Chili-Pasten.',
        'source_knowledge_document_id' => 4242,
        'created_via' => 'trend',
    ]);

    $comp = Livewire::test(PlanungIndex::class)->call('oeffne', $session->id);

    foreach (['rezept', 'gericht', 'concept'] as $scope) {
        $comp->assertSet("eingabe.$scope.brief", 'Aus diesem Food-Trend ein Konzept/Gericht/Basisrezept entwickeln: Fermentierte Chili-Pasten.')
            ->assertSet("eingabe.$scope.titel", 'Fermentierte Chili-Pasten');
    }
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
