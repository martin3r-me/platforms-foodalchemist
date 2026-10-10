<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Nachher-Messung demo Lauf 87 (Berater-Session, 10.10.): Befunde A, D, E vor „Basis steht“. */
beforeEach(function () {
    Queue::fake();
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
});

it('E: der Generator legt die Schritte gleich beim Anlegen an, nicht erst in der Heilung', function () {
    FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $r = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [], [
        'name' => 'Beilage: Herbsttrompeten',
        'zutaten' => [['text' => 'Herbsttrompeten', 'quantity' => 2000, 'unit' => 'g']],
        'preparation' => "1. Pilze putzen und trocken abreiben.\n2. In Butter scharf anbraten.\n3. Mit Salz abschmecken.",
    ])['recipe'];

    expect($r->steps()->count())->toBe(3);
});

it('A: gleiches Unterrezept in anderer Schreibweise wird EIN Kind-Step', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running']);
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Ginger Beer', ['status' => 'draft']);
    $this->makeIngredient($jus, 'Reduktion: Ginger Beer-Ingwer', null, '600', 1);
    $this->makeIngredient($jus, 'Reduktion: Ginger Beer Ingwer', null, '600', 2);

    app(RecipeDependencyWorkflowService::class)->afterGenerated($this->rootTeam, (int) $step->id, (int) auth()->id(), $jus, [
        ['index' => 0, 'text' => 'Reduktion: Ginger Beer-Ingwer', 'primaer' => 'basisrezept_anlegen'],
        ['index' => 1, 'text' => 'Reduktion: Ginger Beer Ingwer', 'primaer' => 'basisrezept_anlegen'],
    ], ['auto_dependencies' => true]);

    expect(FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)->count())->toBe(1)
        ->and(FoodAlchemistCascadeRecipeDependency::where('parent_step_id', $step->id)->count())->toBe(2);
});

it('D: der Prüfer sieht eine Zeile, deren Unterrezept gerade gebaut wird, nicht als Loch', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done']);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $eltern->id, 'kind' => 'rezept', 'status' => 'running']);
    $jus = $this->makeRecipe($this->rootTeam, 'Jus: Ginger Beer', ['status' => 'draft']);
    $zeile = $this->makeIngredient($jus, 'Reduktion: Ginger Beer-Ingwer', null, '600', 1);
    $offen = $this->makeIngredient($jus, 'Fond: Kalb', null, '400', 2);
    FoodAlchemistCascadeRecipeDependency::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'child_step_id' => $kind->id, 'ingredient_id' => $zeile->id]);

    $zutaten = collect(app(RecipeConformanceAdapter::class)->pruefauftrag($this->rootTeam, $jus->id)['kontext']['zutaten'] ?? []);

    expect($zutaten->firstWhere('text', 'Reduktion: Ginger Beer-Ingwer'))->toMatchArray(['ist_sub_rezept' => true])
        ->toHaveKey('unterrezept_in_arbeit')
        ->and($zutaten->firstWhere('text', 'Fond: Kalb'))->toMatchArray(['ist_sub_rezept' => false])
        ->not->toHaveKey('unterrezept_in_arbeit');
});

it('G: Kaufware wird ein Rüst-Basisrezept mit genau dieser Ware — kein Plan, keine Eigenherstellung', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done']);
    $mk = fn (string $label) => FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'kind' => 'rezept', 'status' => 'geplant', 'label' => $label, 'depth' => 1]);
    $oel = $mk('Kürbiskernöl');
    $jus = $mk('Jus: Thymian');
    $fond = $mk('Geflügelfond');
    $wf = app(RecipeDependencyWorkflowService::class);

    foreach ([$oel, $jus, $fond] as $s) {
        $wf->starteKind($this->rootTeam, $s, (int) auth()->id(), (string) $s->label, [], false);
    }

    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipeJob::class, fn ($j) => (int) $j->parameter['cascade_step_id'] === (int) $oel->id
        && ($j->parameter['ruest_ware'] ?? null) === 'Kürbiskernöl');
    Queue::assertNotPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::class, fn ($j) => $j->stepId === (int) $oel->id);
    // Basisrezept-Typ und Halbfabrikat bleiben echte Zubereitungen und planen.
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::class, fn ($j) => $j->stepId === (int) $jus->id && ! isset($j->params['ruest_ware']));
    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::class, fn ($j) => $j->stepId === (int) $fond->id && ! isset($j->params['ruest_ware']));

    $prompt = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class)->build($this->rootTeam, 'Kürbiskernöl', ['ruest_ware' => 'Kürbiskernöl'], false)['prompt'];
    expect($prompt['ruest_basisrezept']['ware'])->toBe('Kürbiskernöl')
        ->and($prompt['ruest_basisrezept']['hinweis'])->toContain('Keine Eigenherstellung');
});

it('Zukauf erkennt Fertigware, nicht Rohware — mit Typ aus der Zeile oder aus der Warengruppe', function () {
    $gp = fn (string $name, string $wg, string $sub) => tap($this->makeGp($this->rootTeam, $name))->update(['commodity_group_code' => $wg, 'sub_category' => $sub]);
    $gp('Roestzwiebeln: trocken, frittiert', '10', '10.4 Gewuerzmischungen');
    $gp('Kuerbiskernoel: trocken', '11', '11.1 Oel');
    $gp('Kuerbis Hokkaido: frisch, ganz', '01', '01.1 Fruchtgemuese');
    $z = app(\Platform\FoodAlchemist\Services\ZukaufBasisrezeptService::class);

    expect($z->erkenne($this->rootTeam, 'Crunch: Röstzwiebeln'))->toMatchArray(['typ' => 'Crunch', 'bezeichnung' => 'Röstzwiebeln'])
        ->and($z->erkenne($this->rootTeam, 'Püree: Kürbis Hokkaido'))->toBeNull()   // Rohware = Zubereitung
        ->and($z->erkenne($this->rootTeam, 'Kürbiskernöl'))->toBeNull();             // ohne Typ und Regel aus → planen

    $regel = \Platform\FoodAlchemist\Models\FoodAlchemistRule::where('schluessel', 'basisrezept.zukauf.typ')->firstOrFail();
    expect($regel->aktiv)->toBeFalse();                                                // Einschalten ist Kuration
    app(\Platform\FoodAlchemist\Services\Regeln\RegelService::class)->setzeAktiv($regel->id, true);

    expect($z->erkenne($this->rootTeam, 'Kürbiskernöl'))->toMatchArray(['typ' => 'Öl', 'bezeichnung' => 'Kürbiskernöl']);
});

it('Zukauf: Weiche in starteKind — eigener Bau ohne Plan und ohne großen Generator', function () {
    tap($this->makeGp($this->rootTeam, 'Roestzwiebeln: trocken, frittiert'))->update(['commodity_group_code' => '10', 'sub_category' => '10.4 Gewuerzmischungen']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done']);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'kind' => 'rezept', 'status' => 'geplant', 'label' => 'Crunch: Röstzwiebeln', 'depth' => 1]);

    app(RecipeDependencyWorkflowService::class)->starteKind($this->rootTeam, $kind, (int) auth()->id(), 'Crunch: Röstzwiebeln', ['ziel_menge' => 400.0, 'ziel_einheit' => 'g'], false);

    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\BuildZukaufRecipeJob::class, fn ($j) => $j->stepId === (int) $kind->id && $j->ware['typ'] === 'Crunch');
    Queue::assertNotPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob::class);
    Queue::assertNotPushed(\Platform\FoodAlchemist\Jobs\GenerateRecipeJob::class);
});

it('Zukauf-Bau: „Typ: Ware (Zukauf)“, die Ware als Zeile, Hilfsstoffe nur als kleine Zugabe, Schritte, gebunden', function () {
    $oel = $this->makeGp($this->rootTeam, 'Kuerbiskernoel: trocken');
    $this->makeGp($this->rootTeam, 'Salz: trocken');
    $this->makeGp($this->rootTeam, 'Traubenkernoel: fluessig');
    $ml = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'ml'],
        ['display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_g' => 1]);
    $this->unitG($this->rootTeam);
    app()->bind(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, fn () => new class extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => [
                'schritte' => ['Kernöl in Quetschflaschen abfüllen.', 'Bis zum Service kühl und dunkel lagern.'],
                'hilfsstoffe' => [['text' => 'Salz', 'menge' => 2, 'einheit' => 'g'], ['text' => 'Traubenkernöl', 'menge' => 300, 'einheit' => 'ml']],
            ], 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'fake', 'tool_calls' => null];
        }
    });
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Rinderfilet', ['status' => 'draft']);
    $zeile = $this->makeIngredient($gericht, 'Kürbiskernöl', null, '400', 1);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done', 'label' => 'Rinderfilet']);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'kind' => 'rezept', 'status' => 'running', 'label' => 'Kürbiskernöl', 'depth' => 1]);
    FoodAlchemistCascadeRecipeDependency::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'child_step_id' => $kind->id, 'ingredient_id' => $zeile->id]);

    (new \Platform\FoodAlchemist\Jobs\BuildZukaufRecipeJob($this->rootTeam->id, (int) auth()->id(), (int) $kind->id, 'Kürbiskernöl',
        ['gp_id' => (int) $oel->id, 'gp_name' => 'Kuerbiskernoel: trocken', 'typ' => 'Öl', 'bezeichnung' => 'Kürbiskernöl'],
        ['bedarf_menge' => 400.0, 'bedarf_einheit' => 'ml']))->handle(
        app(\Platform\FoodAlchemist\Services\ZukaufBasisrezeptService::class), app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class), app(RecipeDependencyWorkflowService::class));

    $kind->refresh();
    expect($kind->error)->toBeNull();
    $r = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::findOrFail($kind->ref_id);
    $zeilen = $r->ingredients()->with('gp')->get();
    expect($kind->status)->toBe('done')
        ->and($r->name)->toBe('Öl: Kürbiskernöl (Zukauf)')
        ->and($zeilen->pluck('gp.name')->all())->toContain('Kuerbiskernoel: trocken')->toContain('Salz: trocken')
        ->not->toContain('Traubenkernoel: fluessig')                        // 300 ml auf 400 ml ist kein Hilfsstoff
        ->and((float) $zeilen->firstWhere('gp_id', $oel->id)->quantity)->toBe(400.0)
        ->and((int) $zeilen->firstWhere('gp_id', $oel->id)->unit_vocab_id)->toBe((int) $ml->id)
        ->and($r->steps()->count())->toBe(2)
        ->and((int) $zeile->fresh()->referenced_recipe_id)->toBe((int) $r->id);
});

it('H: Unterrezept bekommt den Bedarf des Gerichts nur als Information — die Charge wählt die KI zum Sektor', function () {
    $ctx = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class);

    $kind = $ctx->build($this->rootTeam, 'Fond: Kalb', ['bedarf_menge' => 150.0, 'bedarf_einheit' => 'ml', 'sektor' => 'restaurant'], false)['prompt'];
    expect($kind['bedarf_im_gericht']['menge'])->toBe('150 ml')
        ->and($kind['bedarf_im_gericht']['hinweis'])->toContain('NICHT der Ansatz')
        ->and($kind['parameter'] ?? [])->not->toHaveKey('ziel_menge');

    // Ein allein gestartetes Basisrezept behält seinen Ansatz aus der Planung.
    $wurzel = $ctx->build($this->rootTeam, 'Fond: Kalb', ['ziel_menge' => 10.0, 'ziel_einheit' => 'l', 'bedarf_menge' => 150.0], false)['prompt'];
    expect($wurzel)->not->toHaveKey('bedarf_im_gericht')
        ->and($wurzel['parameter']['ziel_menge'] ?? null)->toBe(10.0);
});
