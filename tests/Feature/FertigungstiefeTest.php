<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipePlanJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Support\BestandsPassung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Fertigungstiefe (from_scratch | teilfertig | convenience) steuert die Kaskade (Dominique 10.10.): Zukauf trägt
 * „(Zukauf)“ im Namen UND convenience im Feld, der Regler des Laufs steuert den Bestand, TK-Gemüse wird teilfertig
 * kurz gebaut, und die KI-Anreicherung überschreibt keinen Wert, den die Kaskade gesetzt hat.
 */
beforeEach(function () {
    Queue::fake();
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gp = fn (string $name, string $wg, ?string $cond = null) => tap($this->makeGp($this->rootTeam, $name))
        ->update(['commodity_group_code' => $wg, 'condition' => $cond]);
});

it('Regler from_scratch lehnt einen Zukauf-Bestand ab — außer die Zeile ist selbst Kaufware', function () {
    ($this->gp)('Kuerbiskernoel: trocken', '11');
    $ws = fn (string $z, ?string $tiefe, ?string $regler) => BestandsPassung::fertigungGrund($this->rootTeam, $z, $tiefe, $regler);

    expect($ws('Jus: Rind', 'convenience', 'from_scratch'))->toContain('from scratch')
        ->and($ws('Jus: Rind', 'convenience', 'teil_convenience'))->toBeNull()
        ->and($ws('Jus: Rind', 'convenience', 'voll_convenience'))->toBeNull()
        ->and($ws('Jus: Rind', 'from_scratch', 'from_scratch'))->toBeNull()
        ->and($ws('Kürbiskernöl', 'convenience', 'from_scratch'))->toBeNull();   // Kernöl macht die Küche nie selbst
});

it('Name und Feld müssen zusammenpassen — Hinweis aus dem Code', function () {
    $passt = $this->makeRecipe($this->rootTeam, 'Jus: Rind (Zukauf)', ['status' => 'draft', 'production_depth' => 'convenience']);
    $ohneFeld = $this->makeRecipe($this->rootTeam, 'Jus: Braten (Zukauf)', ['status' => 'draft', 'production_depth' => 'from_scratch']);
    $ohneName = $this->makeRecipe($this->rootTeam, 'Jus: Kalb', ['status' => 'draft', 'production_depth' => 'convenience']);
    $befunde = fn ($r) => collect(app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, (int) $r->id))
        ->where('paragraph', '§1.5')->values();

    expect($befunde($passt))->toHaveCount(0)
        ->and($befunde($ohneFeld)[0]['feld'] ?? null)->toBe('production_depth')
        ->and($befunde($ohneName)[0]['vorschlag'] ?? null)->toBe('Jus: Kalb (Zukauf)')
        ->and($befunde($ohneName)[0]['schweregrad'])->toBe('weich');
});

it('die KI-Anreicherung überschreibt keine Fertigungstiefe, die Kaskade oder Mensch gesetzt haben', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Jus: Rind (Zukauf)', ['status' => 'draft']);
    $r->forceFill(['production_depth' => 'convenience', 'production_depth_source' => 'kaskade'])->save();

    $erg = Closure::bind(fn ($x) => $this->fertigungsGlied($x), app(RecipeOneShotService::class), RecipeOneShotService::class)($r->fresh());

    expect($erg['status'])->toBe('gesetzt')->and($r->fresh()->production_depth)->toBe('convenience');
});

it('Teilfertig: sicher TK-Gemüse als Hauptzeile → kurz bauen, kein Plan, Fertigungstiefe teilfertig', function () {
    ($this->gp)('Erbsen: TK', '01', 'TK');
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done']);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'kind' => 'rezept', 'status' => 'geplant', 'label' => 'Beilage: Erbsen', 'depth' => 1]);

    app(RecipeDependencyWorkflowService::class)->starteKind($this->rootTeam, $kind, (int) auth()->id(), 'Beilage: Erbsen', [], false);

    Queue::assertNotPushed(GenerateRecipePlanJob::class);
    Queue::assertPushed(GenerateRecipeJob::class, fn ($j) => ($j->parameter['production_depth_vorgabe'] ?? null) === 'teilfertig'
        && ($j->parameter['teilfertig_ware'] ?? null) === 'Erbsen: TK');
});

it('Teilfertig: der Generator übernimmt die Vorgabe und markiert sie als von der Kaskade gesetzt', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    config(['foodalchemist.ai.provider' => 'fake']);

    $r = app(\Platform\FoodAlchemist\Services\RecipeGeneratorService::class)->generiere($this->rootTeam, 'Beilage: Erbsen',
        ['convenience' => 'from_scratch', 'production_depth_vorgabe' => 'teilfertig'],
        ['name' => 'Beilage: Erbsen', 'zutaten' => [['text' => 'Erbsen: TK', 'quantity' => 1000, 'unit' => 'g']]])['recipe'];

    expect($r->production_depth)->toBe('teilfertig')->and($r->production_depth_source)->toBe('kaskade');
});

it('Zukauf über die Elternzeile: Plan nennt das Kind anders, die Zeile ist Fertigware → Zukauf-Bau (demo Lauf 92)', function () {
    tap($this->makeGp($this->rootTeam, 'Gemuesebruehe Achenbach Delikatessen: konzentriert, TK'))
        ->update(['commodity_group_code' => '13', 'sub_category' => '13.3 Saucen & Fonds', 'condition' => 'TK']);
    $elternRezept = $this->makeRecipe($this->rootTeam, 'Brühe: Gemüse', ['status' => 'draft']);
    $zeile = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $elternRezept->id,
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1, 'raw_text' => 'Gemuesebruehe Achenbach Delikatessen: konzentriert, TK', 'quantity' => 300]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done']);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'kind' => 'rezept', 'status' => 'geplant', 'label' => 'Brühe: Gemüse (HF)', 'depth' => 2]);
    \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency::create(['team_id' => $this->rootTeam->id,
        'cascade_run_id' => $run->id, 'parent_step_id' => $eltern->id, 'ingredient_id' => $zeile->id, 'child_step_id' => $kind->id]);

    app(RecipeDependencyWorkflowService::class)->starteKind($this->rootTeam, $kind, (int) auth()->id(), 'Brühe: Gemüse (HF)', [], false);

    Queue::assertPushed(\Platform\FoodAlchemist\Jobs\BuildZukaufRecipeJob::class, fn ($j) => $j->stepId === (int) $kind->id
        && $j->ware['typ'] === 'Brühe' && $j->ware['bezeichnung'] === 'Gemüse'
        && $j->ware['gp_name'] === 'Gemuesebruehe Achenbach Delikatessen: konzentriert, TK');
    Queue::assertNotPushed(GenerateRecipePlanJob::class);
});

it('Zukauf über die Elternzeile: Rohware in der Zeile bleibt eine Zubereitung', function () {
    ($this->gp)('Zwiebeln: frisch, ganz', '01');
    $elternRezept = $this->makeRecipe($this->rootTeam, 'Brühe: Gemüse', ['status' => 'draft']);
    $zeile = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $elternRezept->id,
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1, 'raw_text' => 'Zwiebeln: frisch, ganz', 'quantity' => 300]);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running']);
    $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done']);
    $kind = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
        'parent_step_id' => $eltern->id, 'kind' => 'rezept', 'status' => 'geplant', 'label' => 'Beilage: Zwiebeln geschmort', 'depth' => 2]);
    \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency::create(['team_id' => $this->rootTeam->id,
        'cascade_run_id' => $run->id, 'parent_step_id' => $eltern->id, 'ingredient_id' => $zeile->id, 'child_step_id' => $kind->id]);

    app(RecipeDependencyWorkflowService::class)->starteKind($this->rootTeam, $kind, (int) auth()->id(), 'Beilage: Zwiebeln geschmort', [], false);

    Queue::assertNotPushed(\Platform\FoodAlchemist\Jobs\BuildZukaufRecipeJob::class);
});

it('Fertigungstiefe aus dem Inhalt, nicht aus dem Regler: Rohware → from_scratch, TK-Gemüse-Zeile → teilfertig (demo Lauf 92)', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    config(['foodalchemist.ai.provider' => 'fake']);
    ($this->gp)('Karotten: frisch, ganz', '01');
    ($this->gp)('Erbsen: TK', '01', 'TK');
    $gen = app(\Platform\FoodAlchemist\Services\RecipeGeneratorService::class);
    $kaskade = ['convenience' => 'teil_convenience', 'cascade_step_id' => 1];

    $roh = $gen->generiere($this->rootTeam, 'Brühe: Karotte', $kaskade,
        ['name' => 'Brühe: Karotte', 'zutaten' => [['text' => 'Karotten: frisch, ganz', 'quantity' => 1000, 'unit' => 'g']]])['recipe'];
    $conv = $gen->generiere($this->rootTeam, 'Beilage: Erbsen', $kaskade,
        ['name' => 'Beilage: Erbsen', 'zutaten' => [['text' => 'Erbsen: TK', 'quantity' => 1000, 'unit' => 'g']]])['recipe'];
    $ohneKaskade = $gen->generiere($this->rootTeam, 'Brühe: Karotte hell', ['convenience' => 'voll_convenience'],
        ['name' => 'Brühe: Karotte hell', 'zutaten' => [['text' => 'Karotten: frisch, ganz', 'quantity' => 800, 'unit' => 'g']]])['recipe'];

    expect($roh->production_depth)->toBe('from_scratch')->and($roh->production_depth_source)->toBe('kaskade')
        ->and($conv->production_depth)->toBe('teilfertig')
        ->and($ohneKaskade->production_depth)->toBe('from_scratch')->and($ohneKaskade->production_depth_source)->toBeNull();
});
