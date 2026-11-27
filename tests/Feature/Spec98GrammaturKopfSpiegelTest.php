<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Plan 98 B, Schritt 4 (Kurator 23.11.) → 98 · 3.5 6b: Die Grammatur steht nur an der Standard-Darreichung. Früher spiegelte
 * `DarreichungService::kopfSpiegeln` sie in den Rezept-Kopf `sales_quantity_per_unit_g`; seit 6b wird der Kopf NICHT mehr
 * beschrieben (die Spalte entfällt mit 6c). Die Darreichung rechnet bei Mengenänderungen weiter mit.
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $this->standard = fn ($r) => FoodAlchemistRecipeDarreichung::where('recipe_id', $r->id)->where('is_standard', true)->firstOrFail();
    $this->gramm = fn ($r) => app(DarreichungService::class)->grammJeEinheit(($this->standard)($r), $r->fresh());
    $this->kopf = fn ($r) => $r->fresh()->getAttributes()['sales_quantity_per_unit_g'] ?? null;
});

it('Zutatenmenge ändern, Neuberechnung: die Standard-Darreichung rechnet mit, der Rezept-Kopf wird nicht beschrieben', function () {
    // B · Roulade: Standard aus den Komponenten (180 g Rind + 80 g Jus), eine Portion
    $kopfVorher = ($this->kopf)($this->b);
    expect(($this->gramm)($this->b))->toBe(260.0);

    $this->b->ingredients()->where('raw_text', 'Rinderoberschale')->firstOrFail()->update(['quantity' => 200]);
    app(RecipeRecomputeService::class)->recomputePipeline($this->b->id);

    expect(($this->gramm)($this->b))->toBe(280.0)
        ->and(($this->kopf)($this->b))->toBe($kopfVorher);
});

it('eine Neuberechnung zieht keinen abweichenden Kopf mehr nach (kein Spiegel)', function () {
    // A · Bratkartoffeln: Standard-Teller 300 g, Kinder-Variante 150 g
    $this->a->update(['sales_quantity_per_unit_g' => 999]);
    app(DarreichungService::class)->recomputeFuerRezept($this->a->id);

    expect((float) ($this->kopf)($this->a))->toBe(999.0)
        ->and(($this->gramm)($this->a))->toBe(300.0);
});
