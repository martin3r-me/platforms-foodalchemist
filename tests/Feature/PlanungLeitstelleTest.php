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
