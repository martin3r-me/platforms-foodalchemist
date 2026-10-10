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
