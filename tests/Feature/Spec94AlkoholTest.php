<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94c · Stufe 8a: „enthält Alkohol“ hochgereicht wie die Spec-Flags — Lieferantenartikel (is_alcohol) → GP
 * (GpAggregateService::enthaeltAlkohol, Warn-Regel) → Rezept (spec_contains_alcohol im Recompute, auch über Sub-Rezepte)
 * → Codes und Legende („Alk.“) überall, wo ConcepterAggregateService::gerichtCodes rechnet (Konzept-Detail, Foodbook, Karte).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Getränke Hof']);
    $this->artikel = function ($gp, ?bool $alkohol, string $nr) {
        $item = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->lieferant->id,
            'designation' => 'Artikel ' . $nr, 'article_number' => $nr, 'qty' => 1, 'unit_code' => 'l', 'is_alcohol' => $alkohol]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $gp->id]);
    };
});

it('GP: ja, sobald ein Artikel Alkohol enthält; nein, wenn alle ihn ausschließen; offen ohne Angabe', function () {
    $agg = app(GpAggregateService::class);
    $wein = $this->makeGp($this->rootTeam, 'Weißwein');
    ($this->artikel)($wein, false, 'W0');
    ($this->artikel)($wein, true, 'W1');
    $saft = $this->makeGp($this->rootTeam, 'Apfelsaft');
    ($this->artikel)($saft, false, 'S1');
    $offen = $this->makeGp($this->rootTeam, 'Tonic');
    ($this->artikel)($offen, null, 'T1');

    expect($agg->enthaeltAlkohol($wein))->toBeTrue()
        ->and($agg->enthaeltAlkohol($saft))->toBeFalse()
        ->and($agg->enthaeltAlkohol($offen))->toBeNull()
        ->and($agg->enthaeltAlkohol($this->makeGp($this->rootTeam, 'Ohne Artikel')))->toBeNull();
});

it('Rezept: Recompute reicht Alkohol über GP und Sub-Rezept hoch; ohne Alkohol nein; Code „Alk.“ mit Legende', function () {
    $wein = $this->makeGp($this->rootTeam, 'Weißwein');
    ($this->artikel)($wein, true, 'W1');
    $saft = $this->makeGp($this->rootTeam, 'Apfelsaft');
    ($this->artikel)($saft, false, 'S1');

    $sosse = $this->makeRecipe($this->rootTeam, 'Weißweinsoße');
    $this->makeIngredient($sosse, 'Weißwein', $wein);
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Zander|Weißweinsoße', ['is_sales_recipe' => true]);
    FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'referenced_recipe_id' => $sosse->id,
        'raw_text' => 'Weißweinsoße', 'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);
    $schorle = $this->makeRecipe($this->rootTeam, 'Apfelschorle', ['is_sales_recipe' => true]);
    $this->makeIngredient($schorle, 'Apfelsaft', $saft);

    $svc = app(RecipeRecomputeService::class);
    $svc->recomputePipeline($sosse->id, false);
    $svc->recomputePipeline($gericht->id, false);
    $svc->recomputePipeline($schorle->id, false);
    expect($sosse->fresh()->spec_contains_alcohol)->toBeTrue()
        ->and($gericht->fresh()->spec_contains_alcohol)->toBeTrue()   // über das Sub-Rezept
        ->and($schorle->fresh()->spec_contains_alcohol)->toBeFalse();

    $agg = app(ConcepterAggregateService::class);
    $usedAlg = [];
    $usedZus = [];
    expect($agg->gerichtCodes($gericht->fresh(), $usedAlg, $usedZus))->toContain('Alk.')
        ->and($agg->gerichtCodes($schorle->fresh(), $usedAlg, $usedZus))->not->toContain('Alk.')
        ->and(collect($agg->kennzeichnungLegende($usedAlg, $usedZus)['zusatzstoffe'])->pluck('label')->all())->toContain('enthält Alkohol');
});
