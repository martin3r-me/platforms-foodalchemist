<?php

use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dominique 10.10.: ein Gericht ist IMMER genau eine Portion (Ertrag = ziel_portion_g), die Produktion multipliziert mit
 * pax. Referenz demo Lauf 91/92 (0,35 kg), Gegenbeispiel Lauf 94 (28 kg / 12 kg Filet für 80 Pax).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->unitG($this->rootTeam);
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->gericht = fn (array $zutaten, array $parameter = [], array $mehr = []) => app(RecipeGeneratorService::class)
        ->generiere($this->rootTeam, 'Test', $parameter, ['name' => '[HG] Rinderfilet | Kürbis | Kartoffel', 'zutaten' => $zutaten] + $mehr, vkModus: true);
});

it('Chargen-Antwort der KI (28 kg für 80 Pax) → alle Zeilen auf eine Portion skaliert', function () {
    $res = ($this->gericht)([
        ['text' => 'Rinderfilet', 'quantity' => 12000, 'unit' => 'g'],
        ['text' => 'Kürbis', 'quantity' => 9600, 'unit' => 'g'],
        ['text' => 'Kartoffel', 'quantity' => 6400, 'unit' => 'g'],
    ], ['ziel_portion_g' => 350, 'pax' => 80]);
    $r = $res['recipe']->fresh();
    $menge = fn (string $t) => (float) $r->ingredients()->where('raw_text', $t)->value('quantity');

    expect($res['statistik']['portion']['skaliert'])->toBeTrue()
        ->and($menge('Rinderfilet'))->toBe(150.0)
        ->and(abs((float) $r->yield_kg - 0.35))->toBeLessThan(0.01)
        ->and((float) $r->sales_quantity_per_unit_g)->toBe(350.0)
        ->and((int) $r->sales_unit_count)->toBe(1);
});

it('Gericht mit einer Portion (innerhalb der Toleranz) → Zeilen unverändert', function () {
    $res = ($this->gericht)([
        ['text' => 'Rinderfilet', 'quantity' => 160, 'unit' => 'g'],
        ['text' => 'Kürbis', 'quantity' => 120, 'unit' => 'g'],
        ['text' => 'Kartoffel', 'quantity' => 100, 'unit' => 'g'],
    ], ['ziel_portion_g' => 350]);

    expect($res['statistik']['portion']['skaliert'])->toBeFalse()
        ->and((float) $res['recipe']->ingredients()->where('raw_text', 'Rinderfilet')->value('quantity'))->toBe(160.0);
});

it('ohne ziel_portion_g zählt die Portion aus dem Vorschlag (portion_g)', function () {
    $res = ($this->gericht)([
        ['text' => 'Rinderfilet', 'quantity' => 12000, 'unit' => 'g'],
        ['text' => 'Kürbis', 'quantity' => 12000, 'unit' => 'g'],
    ], [], ['portion_g' => 300]);

    expect($res['statistik']['portion']['skaliert'])->toBeTrue()
        ->and((float) $res['recipe']->ingredients()->where('raw_text', 'Rinderfilet')->value('quantity'))->toBe(150.0);
});

it('Kind-Bedarf unter einem Gericht = Zeile × pax, unter einem Basisrezept bleibt die Zeile', function () {
    $g = $this->unitG($this->rootTeam)->id;
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'running', 'params' => ['pax' => 80]]);
    $vorgaben = function (bool $gericht) use ($run, $g) {
        $r = $this->makeRecipe($this->rootTeam, $gericht ? '[HG] Rind | Jus' : 'Jus: Rind', ['status' => 'draft', 'is_sales_recipe' => $gericht]);
        $zeile = $this->makeIngredient($r, 'Jus: Rind', null, '50', 1);
        $zeile->update(['unit_vocab_id' => $g]);
        $eltern = FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id,
            'kind' => $gericht ? 'gericht' : 'rezept', 'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => $r->id]);
        $m = new ReflectionMethod(RecipeDependencyWorkflowService::class, 'kindVorgaben');
        $m->setAccessible(true);

        return $m->invoke(app(RecipeDependencyWorkflowService::class), $eltern->fresh(), (int) $zeile->id, 'Jus: Rind');
    };

    expect($vorgaben(true)['bedarf_menge'] ?? null)->toBe(4000.0)
        ->and($vorgaben(false)['bedarf_menge'] ?? null)->toBe(50.0);
});
