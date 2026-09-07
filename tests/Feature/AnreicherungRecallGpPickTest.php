<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Befund-Runde 2026-09-07 (Lauf 65 / Session 119 auf demo, „Crème-Suppe: Tomate-Speck").
 *
 * Drei unabhängige Ursachen, drei Beweisziele:
 *
 *  A) Das Reuse-Gate prüfte weder Status noch Vollständigkeit — übernommen wurde ein `draft`
 *     mit 0 Schritten, einer ungemappten Zutat und ohne EK, und der Lauf meldete
 *     „abgeschlossen". Die Regel des Nutzers dazu ist: „Übernommene Rezepte sind hoffentlich
 *     schon angereichert … nur die neuen." Hier wird sie prüfbar gemacht.
 *  B) „Voll anreichern" stand auf Default AUS, das Badge zeigte trotzdem einen Abschluss.
 *  D) `Tomaten: TK, getrocknet` mit 1.600 g (57,9 % einer 2,764-kg-Suppe) passierte jede
 *     Stufe, den Konformitäts-Critic eingeschlossen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();

    $this->gramm = fn () => FoodAlchemistVocabEinheit::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'slug' => 'g'],
        ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
    );
    $this->stueck = fn () => FoodAlchemistVocabEinheit::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'slug' => 'stk'],
        ['display_de' => 'Stück', 'dimension' => 'count'],
    );

    /** Zutat an ein Rezept hängen (GP optional, Einheit optional). */
    $this->zutat = function ($recipe, ?int $gpId, float $menge, $einheit, string $text, int $pos = 1): int {
        DB::table('foodalchemist_recipe_ingredients')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id, 'gp_id' => $gpId,
            'raw_text' => $text, 'display_name' => $text, 'quantity' => $menge,
            'unit_vocab_id' => $einheit?->id, 'position' => $pos,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };

    /** Schritt an ein Rezept hängen — macht es (zusammen mit EK + gemappten Zutaten) reif. */
    $this->schritt = function ($recipe, string $text): void {
        DB::table('foodalchemist_recipe_steps')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $this->rootTeam->id, 'recipe_id' => $recipe->id,
            'position' => 1, 'text' => $text, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

// ── A) Reuse-Gate: „übernommen" heißt nur dann fertig, wenn es fertig ist ────────────────

it('A1: reifegrad benennt genau die Lücken des demo-Falls (0 Schritte · ungemappte Zutat · unbepreist)', function () {
    // Rezept 3400 „Basisrezept: Gemüsebrühe" im Ist-Zustand: draft, keine Schritte,
    // „Petersilienstiele" ohne gp_id, ek_total_eur leer.
    $bruehe = $this->makeRecipe($this->rootTeam, 'Basisrezept: Gemüsebrühe', ['status' => 'draft']);
    ($this->zutat)($bruehe, null, 20, ($this->gramm)(), 'Petersilienstiele');

    $reife = app(RecipeService::class)->reifegrad($bruehe->fresh());

    expect($reife['reif'])->toBeFalse()
        ->and($reife['luecken'])->toContain('keine Schritte')
        ->and($reife['luecken'])->toContain('1 Zutat(en) ohne Verknüpfung')
        ->and($reife['luecken'])->toContain('unbepreist');
});

it('A2: ein produktionsreifes Rezept hat keine Lücken', function () {
    $gp = $this->makeGp($this->rootTeam, 'Karotten');
    $fertig = $this->makeRecipe($this->rootTeam, 'Basisrezept: Fond', ['status' => 'approved', 'ek_total_eur' => 4.12]);
    ($this->zutat)($fertig, $gp->id, 150, ($this->gramm)(), 'Karotten');
    ($this->schritt)($fertig, 'Gemüse ansetzen und ziehen lassen.');

    expect(app(RecipeService::class)->reifegrad($fertig->fresh())['reif'])->toBeTrue();
});

it('A3: findByTokenSet gibt NIE einen stub als Bestand zurück (leere Hülle ist kein Rezept)', function () {
    $this->makeRecipe($this->rootTeam, 'Basisrezept: Gemüsebrühe', ['status' => 'stub']);

    expect(app(RecipeService::class)->findByTokenSet($this->rootTeam, 'Basisrezept: Gemüsebrühe'))->toBeNull();
});

it('A4: bei Namens-Gleichstand gewinnt das REIFE Rezept, nicht die kleinere id', function () {
    // Reihenfolge bewusst so: der unreife Entwurf wird ZUERST angelegt und hätte per
    // `orderBy('id')` gewonnen — genau der Mechanismus, der auf demo Rezept 3400 zog.
    $unreif = $this->makeRecipe($this->rootTeam, 'Basisrezept: Gemüsebrühe', ['status' => 'draft']);
    $gp = $this->makeGp($this->rootTeam, 'Lauch');
    $reif = $this->makeRecipe($this->rootTeam, 'Basisrezept: Gemüsebrühe', ['status' => 'approved', 'ek_total_eur' => 3.10]);
    ($this->zutat)($reif, $gp->id, 100, ($this->gramm)(), 'Lauch');
    ($this->schritt)($reif, 'Ansetzen.');

    $treffer = app(RecipeService::class)->findByTokenSetMitReife($this->rootTeam, 'Basisrezept: Gemüsebrühe');

    expect((int) $treffer['recipe']->id)->toBe((int) $reif->id)
        ->and((int) $treffer['recipe']->id)->not->toBe((int) $unreif->id)
        ->and($treffer['reif'])->toBeTrue()
        ->and($treffer['eigen'])->toBeTrue();
});

it('A5: Freigabe reichert eine UNREIFE EIGENE Übernahme mit an — und der Lauf meldet nicht „done"', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Tomatensuppe', 'brief' => 'Cremige Tomatensuppe mit Speck.']);
    $suppe = $this->makeRecipe($this->rootTeam, 'Crème-Suppe: Tomate-Speck', ['status' => 'draft']);
    $bruehe = $this->makeRecipe($this->rootTeam, 'Basisrezept: Gemüsebrühe', ['status' => 'draft']);

    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id,
        'scope' => 'rezept', 'status' => 'review', 'staged' => true,
        'params' => ['complete_coverage' => true],
    ]);
    $eltern = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $suppe->id, 'depth' => 0,
    ]);
    $kind = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'skipped', 'ref_type' => 'recipe', 'ref_id' => $bruehe->id, 'depth' => 1,
        'parent_step_id' => $eltern->id, 'dedupe_key' => 'reuse:'.$bruehe->id,
        'deferred' => ['reuse' => ['reif' => false, 'eigen' => true, 'luecken' => ['keine Schritte'], 'status' => 'draft']],
    ]);

    app(PlanningCascadeService::class)->gibStepFrei($this->rootTeam, (int) $eltern->id);

    // Beide Rezepte werden angereichert — vorher lief NUR das freigegebene.
    Queue::assertPushed(EnrichRecipeJob::class, 2);
    Queue::assertPushed(EnrichRecipeJob::class, fn ($job) => $job->recipeId === (int) $bruehe->id);
    expect($kind->fresh()->deferred['enrich']['status'] ?? null)->toBe('queued');

    // Und der Lauf lügt nicht mehr: eine unreife Übernahme ist kein Abschluss.
    expect($run->fresh()->status)->toBe('review');
});

it('A6: eine FREMDE unreife Übernahme wird NICHT automatisch angereichert (fremdes, lebendes Gut)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'T', 'brief' => 'x']);
    $suppe = $this->makeRecipe($this->rootTeam, 'Crème-Suppe: Tomate-Speck', ['status' => 'draft']);
    $fremd = $this->makeRecipe($this->rootTeam, 'Basisrezept: Gemüsebrühe', ['status' => 'approved']);

    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id,
        'scope' => 'rezept', 'status' => 'review', 'staged' => true, 'params' => [],
    ]);
    $eltern = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $suppe->id, 'depth' => 0,
    ]);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'skipped', 'ref_type' => 'recipe', 'ref_id' => $fremd->id, 'depth' => 1,
        'parent_step_id' => $eltern->id, 'dedupe_key' => 'reuse:'.$fremd->id,
        // eigen=false → Entscheidung gehört dem Menschen, nicht der Kaskade
        'deferred' => ['reuse' => ['reif' => false, 'eigen' => false, 'luecken' => ['keine Schritte'], 'status' => 'approved']],
    ]);

    app(PlanningCascadeService::class)->gibStepFrei($this->rootTeam, (int) $eltern->id);

    Queue::assertPushed(EnrichRecipeJob::class, 1);
    Queue::assertNotPushed(EnrichRecipeJob::class, fn ($job) => $job->recipeId === (int) $fremd->id);
});

it('A7: laufStatus macht den Reifegrad headless sichtbar (MCP-Lockstep)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'T', 'brief' => 'x']);
    $bruehe = $this->makeRecipe($this->rootTeam, 'Basisrezept: Gemüsebrühe', ['status' => 'draft']);
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id,
        'scope' => 'rezept', 'status' => 'review', 'staged' => true,
    ]);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'skipped', 'ref_type' => 'recipe', 'ref_id' => $bruehe->id, 'depth' => 1,
        'label' => 'Basisrezept: Gemüsebrühe',
        'deferred' => ['reuse' => ['reif' => false, 'eigen' => true, 'luecken' => ['keine Schritte', 'unbepreist'], 'status' => 'draft']],
    ]);

    $status = app(PlanningCascadeService::class)->laufStatus($this->rootTeam, (int) $run->id);

    expect($status['schritte'][0]['uebernahme_reif'])->toBeFalse()
        ->and($status['schritte'][0]['uebernahme_luecken'])->toContain('unbepreist')
        // Der Handlungs-Satz darf nicht „Entwürfe warten auf Freigabe" sagen — eine
        // `skipped`-Zeile ist nicht freigebbar, man würde eine Aktion suchen, die es nicht gibt.
        ->and($status['hinweis'])->toContain('nicht produktionsreif');
});

// ── B) Anreicherungs-TIEFE ehrlich ──────────────────────────────────────────────────────

it('B1: der Job schreibt die Tiefe an den Step — „leicht" ist von „voll" unterscheidbar', function () {
    $recipe = $this->makeRecipe($this->rootTeam, 'Crème-Suppe: Tomate-Speck', ['status' => 'approved']);
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'review',
    ]);
    $step = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'freigegeben', 'ref_type' => 'recipe', 'ref_id' => $recipe->id,
    ]);

    // Leichter Pass (complete_coverage=false) — der Fall aus Lauf 65.
    (new EnrichRecipeJob($this->rootTeam->id, (int) auth()->id(), (int) $recipe->id, null, false, (int) $step->id, false, false, false))
        ->handle(app(\Platform\FoodAlchemist\Services\RecipeOneShotService::class));

    expect($step->fresh()->deferred['enrich']['tief'] ?? null)->toBeFalse();
});

// ── D) Mengen-Plausibilität deterministisch ─────────────────────────────────────────────

it('D1: 1.600 g Trockentomate in 2,76 kg Suppe ergeben einen HARTEN §6-Befund', function () {
    $suppe = $this->makeRecipe($this->rootTeam, 'Crème-Suppe: Tomate-Speck', ['status' => 'draft']);
    $g = ($this->gramm)();

    // Der Ist-Zustand von GP 13757: „TK" im Namen, condition NULL, getrocknet in der Verarbeitung.
    $trocken = $this->makeGp($this->rootTeam, 'Tomaten: TK, getrocknet');
    DB::table('foodalchemist_gps')->where('id', $trocken->id)->update(['processing' => 'getrocknet']);
    $speck = $this->makeGp($this->rootTeam, 'Bacon / Fruehstuecksspeck: frisch, geschnitten');
    $sahne = $this->makeGp($this->rootTeam, 'Sahne: konserviert, 30 % Fett');

    ($this->zutat)($suppe, $trocken->id, 1600, $g, 'Tomaten', 1);
    ($this->zutat)($suppe, $speck->id, 120, $g, 'Bacon', 2);
    ($this->zutat)($suppe, $sahne->id, 200, $g, 'Sahne', 3);

    $befunde = app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $suppe->id);

    expect($befunde)->toHaveCount(1)
        ->and($befunde[0]['schweregrad'])->toBe('hart')
        ->and($befunde[0]['paragraph'])->toBe('§6')
        ->and($befunde[0]['konfidenz'])->toBe(1.0)
        ->and($befunde[0]['begruendung'])->toContain('getrocknet')
        ->and($befunde[0]['begruendung'])->toContain('83,3 %');
});

it('D2: Tomatenmark als Röstbasis (60 g von 1.980 g) ist KEIN Befund — die Regel darf Saucen nicht flaggen', function () {
    $suppe = $this->makeRecipe($this->rootTeam, 'Sauce: Tomate', ['status' => 'draft']);
    $g = ($this->gramm)();

    $mark = $this->makeGp($this->rootTeam, 'Tomatenmark: konserviert, konzentriert');
    DB::table('foodalchemist_gps')->where('id', $mark->id)->update(['processing' => 'konzentrat']);
    $pelati = $this->makeGp($this->rootTeam, 'Tomaten / Pelati: konserviert, ganz');

    ($this->zutat)($suppe, $mark->id, 60, $g, 'Tomatenmark', 1);
    ($this->zutat)($suppe, $pelati->id, 1920, $g, 'Pelati', 2);

    expect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $suppe->id))->toBe([]);
});

it('D3: bei nicht bestimmbarer Masse schweigt die Regel (Stück-Zeile darf den Anteil nicht hochrechnen)', function () {
    $suppe = $this->makeRecipe($this->rootTeam, 'Suppe: gemischt', ['status' => 'draft']);
    $trocken = $this->makeGp($this->rootTeam, 'Tomaten: TK, getrocknet');
    DB::table('foodalchemist_gps')->where('id', $trocken->id)->update(['processing' => 'getrocknet']);

    ($this->zutat)($suppe, $trocken->id, 1600, ($this->gramm)(), 'Tomaten', 1);
    // Eine Stück-Zeile trägt 0 g in die Summe und würde den Anteil künstlich auf 100 % treiben —
    // dieselbe Falle, die im Yield-Check des DataQualityService dokumentiert ist.
    ($this->zutat)($suppe, $this->makeGp($this->rootTeam, 'Lorbeerblatt')->id, 2, ($this->stueck)(), 'Lorbeerblatt', 2);

    expect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $suppe->id))->toBe([]);
});
