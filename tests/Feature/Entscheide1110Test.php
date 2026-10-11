<?php

use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Support\Kleinstmenge;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Entscheide Dominique 11.10. (nach Lauf 98): Kleinstmengen im Gericht direkt als GP, Hausstandard Fond/Jus blockiert,
 * Tiebreak approved vor tentative, Befunde „gleicher GP doppelt ohne Rolle“ und „Einheit ≠ GP-Form“, Hinweis bei
 * recipes.INSTANTIATE.
 */
beforeEach(function () {
    Queue::fake();
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->einheit = fn (string $slug, string $dim) => FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => $slug],
        ['display_de' => $slug, 'dimension' => $dim, 'default_in_g' => $dim === 'mass' ? 1 : null]);
    $this->gp = fn (string $name, array $attrs = []) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'e1110|' . bin2hex(random_bytes(4)), 'name' => $name,
        'status' => 'approved', 'is_platzhalter' => false, ...$attrs]);
    $this->befunde = fn (FoodAlchemistRecipe $r) => collect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $r->id));
});

// ── Kleinstmengen ──────────────────────────────────────────────────────────────────────────────

it('Kleinstmenge: Typ-Vorsilbe und Merkmale zählen nicht, Halbfabrikate treffen nicht', function () {
    $e = fn (string $t) => Kleinstmenge::erkenne($t)['eintrag'] ?? null;

    expect($e('Aroma: Butter'))->toBe('Butter')
        ->and(Kleinstmenge::erkenne('Aroma: Butter')['text'])->toBe('Butter')
        ->and($e('Pfeffer schwarz, frisch gemahlen'))->toBe('Pfeffer')
        ->and($e('Olivenöl extra vergine'))->toBe('Öl')
        ->and($e('Zitrone: Saft'))->toBe('Zitrone')
        ->and($e('Schnittlauch: frisch'))->toBe('Schnittlauch')
        ->and($e('Zitronenbutter'))->toBeNull()
        ->and($e('Butter: braun'))->toBeNull()
        ->and($e('Kräuteröl'))->toBeNull()
        ->and($e('Jus: Rind'))->toBeNull();

    FoodAlchemistRule::where('schluessel', Kleinstmenge::SCHLUESSEL)->update(['aktiv' => false]);
    \Platform\FoodAlchemist\Services\Regeln\RegelBuch::vergessen();
    expect($e('Butter'))->toBeNull();   // Regel aus = altes Verhalten
});

it('Generator (Gericht, from scratch): „Aroma: Butter“ wird GP-Zeile „Butter“, kein Rüst-Basisrezept', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    ($this->einheit)('g', 'mass');
    $butter = ($this->gp)('Butter: frisch', ['commodity_group_code' => '03']);

    $res = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', ['convenience' => 'from_scratch'], [
        'name' => '[HG] Rinderfilet | Butter',
        'zutaten' => [['text' => 'Aroma: Butter', 'quantity' => 2, 'unit' => 'g', 'role' => 'aroma_treiber']],
    ], vkModus: true);
    $z = $res['recipe']->ingredients()->first();

    expect((int) $z->gp_id)->toBe((int) $butter->id)
        ->and($z->display_name)->toBe('Butter')
        ->and($z->referenced_recipe_id)->toBeNull()
        ->and(collect($res['offene'])->where('primaer', 'basisrezept_anlegen'))->toHaveCount(0)
        ->and($res['statistik']['kleinstmengen'] ?? [])->toBe(['Butter']);
});

it('planChildren: eine offene Kleinstmengen-Zeile im Gericht bekommt keinen Kind-Step', function () {
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $step = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running', 'sort' => 1]);
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Rinderfilet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $this->makeIngredient($gericht, 'Aroma: Butter');

    app(RecipeDependencyWorkflowService::class)->afterGenerated($this->rootTeam, $step->id, auth()->id(), $gericht,
        [['index' => 0, 'text' => 'Aroma: Butter', 'primaer' => 'basisrezept_anlegen']],
        ['auto_dependencies' => true, 'bestand' => 'hybrid']);

    expect(FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('depth', 1)->count())->toBe(0);
});

// ── Hausstandard Fond/Jus ──────────────────────────────────────────────────────────────────────

it('Fond mit Verkaufs-Cut (Lauf 98/3880): harter Befund, heilbar, Direktive nennt die Ersatzrichtung; Zweck-Zutat frei', function () {
    $wg04 = fn (string $n) => ($this->gp)($n, ['commodity_group_code' => '04']);
    $fond = $this->makeRecipe($this->rootTeam, 'Fond: Rind', ['status' => 'draft']);
    $this->makeIngredient($fond, 'Rinderbeinscheiben: frisch', $wg04('Rinderbeinscheiben: frisch'), '3000', 1);
    $this->makeIngredient($fond, 'Rinderknochen: frisch', $wg04('Rinderknochen: frisch'), '5000', 2);
    $tafelspitz = $this->makeRecipe($this->rootTeam, 'Fond: Tafelspitz', ['status' => 'draft']);
    $this->makeIngredient($tafelspitz, 'Rindertafelspitz: frisch', $wg04('Rindertafelspitz: frisch'), '3000', 1);
    $regel = FoodAlchemistRule::where('schluessel', 'basisrezept.hausstandard.fond_knochen')->firstOrFail();

    $hart = ($this->befunde)($fond)->where('rule_id', $regel->id)->values()->all();
    $heilbar = app(RecipeConformanceAdapter::class)->heilbar($this->rootTeam, (int) $fond->id, $hart);
    $direktive = Closure::bind(fn ($b) => $this->heilDirektive($b), app(\Platform\FoodAlchemist\Services\ConformanceService::class),
        \Platform\FoodAlchemist\Services\ConformanceService::class)($heilbar);

    expect($regel->wirkung)->toBe('blockieren')
        ->and($hart)->toHaveCount(1)
        ->and($hart[0]['schweregrad'])->toBe('hart')
        ->and($heilbar)->toHaveCount(1)
        ->and($direktive)->toContain('Knochen, Karkassen und Abschnitten')->toContain('dagegen tauschen')->toContain('Fond: Tafelspitz')
        ->and(($this->befunde)($tafelspitz)->where('rule_id', $regel->id))->toHaveCount(0);
});

// ── Tiebreak ───────────────────────────────────────────────────────────────────────────────────

it('Matcher: bei gleicher Punktzahl gewinnt approved vor tentative (sonst die kleinere ID)', function () {
    ($this->gp)('Kalbsfond: konserviert', ['status' => 'tentative']);
    $approved = ($this->gp)('Kalbsfond: konserviert');

    $t = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Kalbsfond: konserviert', null, 'gp_first');

    expect($t['target'])->toBe('gp')->and((int) $t['gp_id'])->toBe((int) $approved->id);
});

// ── Befunde ────────────────────────────────────────────────────────────────────────────────────

it('Befund: gleicher GP doppelt ohne Rolle ist hart; mit eigener Notiz kein Befund', function () {
    $butter = ($this->gp)('Butter: frisch');
    $doppelt = $this->makeRecipe($this->rootTeam, 'Sauce: Beurre blanc', ['status' => 'draft']);
    $this->makeIngredient($doppelt, 'Butter', $butter, '100', 1);
    $this->makeIngredient($doppelt, 'Butter', $butter, '50', 2);
    $zweiAufgaben = $this->makeRecipe($this->rootTeam, 'Sauce: Beurre rouge', ['status' => 'draft']);
    $this->makeIngredient($zweiAufgaben, 'Butter', $butter, '20', 1)->update(['note' => 'zum Anschwitzen']);
    $this->makeIngredient($zweiAufgaben, 'Butter', $butter, '150', 2)->update(['note' => 'kalt zum Montieren']);

    $b = ($this->befunde)($doppelt)->where('paragraph', 'Zeilen')->values();
    expect($b)->toHaveCount(1)->and($b[0]['schweregrad'])->toBe('hart')->and($b[0]['feld'])->toBe('zutat:Butter: frisch')
        ->and(($this->befunde)($zweiAufgaben)->where('paragraph', 'Zeilen'))->toHaveCount(0);
});

it('Befund: Stück auf gemahlener Ware und Volumen auf ganzer Ware; passende Einheit kein Befund', function () {
    $stk = ($this->einheit)('stk', 'count');
    $ml = ($this->einheit)('ml', 'volume');
    $r = $this->makeRecipe($this->rootTeam, 'Sauce: Curry', ['status' => 'draft']);
    $this->makeIngredient($r, 'Kardamom', ($this->gp)('Kardamom: trocken, gemahlen'), '2', 1)->update(['unit_vocab_id' => $stk->id]);
    $this->makeIngredient($r, 'Zitrone', ($this->gp)('Zitrone: frisch, ganz'), '30', 2)->update(['unit_vocab_id' => $ml->id]);
    $this->makeIngredient($r, 'Limette', ($this->gp)('Limette: frisch, ganz'), '1', 3)->update(['unit_vocab_id' => $stk->id]);

    $b = ($this->befunde)($r)->where('paragraph', 'Einheit')->values();
    expect($b->pluck('feld')->all())->toBe(['zutat:Kardamom: trocken, gemahlen', 'zutat:Zitrone: frisch, ganz'])
        ->and($b->pluck('schweregrad')->unique()->all())->toBe(['hart'])
        ->and($b[0]['begruendung'])->toContain('ganze Form')->toContain('in g angeben')
        ->and($b[1]['begruendung'])->toContain('Saft')
        // hart heißt: geht in die Selbstheilung
        ->and(app(RecipeConformanceAdapter::class)->heilbar($this->rootTeam, (int) $r->id, $b->all()))->toHaveCount(2);
});

// ── recipes.INSTANTIATE ────────────────────────────────────────────────────────────────────────

it('recipes.INSTANTIATE bindet wie gewählt, meldet aber den Grund der Automatik als Hinweis', function () {
    $platzhalter = ($this->gp)('[Fond]', ['is_platzhalter' => true]);
    $tpl = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'tpl_' . bin2hex(random_bytes(4)),
        'name' => 'Grundsauce', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false, 'is_template' => true]);
    $slot = $this->makeIngredient($tpl, '[Fond]', $platzhalter, '500', 1);
    $entwurf = $this->makeRecipe($this->rootTeam, 'Fond: Kalb', ['status' => 'draft']);

    $res = app(ToolRegistry::class)->get('foodalchemist.recipes.INSTANTIATE')->execute(
        ['template_id' => $tpl->id, 'name' => 'Grundsauce: Kalb', 'bindings' => [['ingredient_id' => $slot->id, 'referenced_recipe_id' => $entwurf->id]]],
        new ToolContext($this->user, $this->rootTeam));

    expect($res->success)->toBeTrue()
        ->and($res->data['gebunden'])->toBe(1)
        ->and($res->data['hinweise'])->toHaveCount(1)
        ->and($res->data['hinweise'][0]['hinweis'])->toContain('noch nicht freigegeben')
        ->and(FoodAlchemistRecipe::find($res->data['id'])->ingredients()->first()->referenced_recipe_id)->toBe($entwurf->id);
});
