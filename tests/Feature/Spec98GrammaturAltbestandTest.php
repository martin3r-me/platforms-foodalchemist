<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Plan 98 B (Kurator 23.11.): Darreichungen in Altbestand-Form — fest eingetragenes quantity_per_unit_g, eigene Menge, keine
 * Deltas — rechnen nach recomputePreise denselben EK je Portion wie vor der Umstellung (EK je kg × Grammatur × Anzahl).
 * Genau diese Zeilen liegen auf io, bis der Grammatur-Apply gelaufen ist; Sperre und Kopf-Spiegelung dürfen sie nicht verschieben.
 *
 * A · Bratkartoffeln: 1000 g Kartoffeln à 1,20 €/kg + 200 g Speck à 9,00 €/kg = 3,00 € auf 1,2 kg Ertrag ⇒ 2,50 €/kg.
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $this->svc = app(DarreichungService::class);
    $this->dar = fn (string $form) => FoodAlchemistRecipeDarreichung::where('recipe_id', $this->a->id)
        ->whereHas('servingForm', fn ($q) => $q->where('code', $form))->firstOrFail();
});

it('Standard mit fester Grammatur: EK je Portion = EK je kg × Grammatur, Grammatur und Kopf bleiben', function () {
    $teller = ($this->dar)('teller');
    expect((bool) $teller->menge_vom_standard)->toBeFalse()->and($teller->deltas()->count())->toBe(0);

    $kopfVorher = $this->a->fresh()->getAttributes()['sales_quantity_per_unit_g'] ?? null;
    $this->svc->recomputePreise($teller->fresh());

    $t = $teller->fresh();
    expect((float) $this->a->fresh()->ek_per_kg_eur)->toBe(2.5)
        ->and((float) $t->quantity_per_unit_g)->toBe(300.0)
        ->and((float) $t->ek_portion)->toBe(0.75)                                  // 2,50 €/kg × 300 g
        ->and($this->a->fresh()->getAttributes()['sales_quantity_per_unit_g'] ?? null)->toBe($kopfVorher);  // 98 · 3.5 6b: kein Spiegel
});

it('Variante mit fester Grammatur und Anzahl: EK je Portion proportional, unverändert', function () {
    $this->svc->recomputePreise(($this->dar)('kinder')->fresh());
    $this->svc->recomputePreise(($this->dar)('doppel')->fresh());

    expect((float) ($this->dar)('kinder')->ek_portion)->toBe(0.375)     // 150 g
        ->and((float) ($this->dar)('doppel')->ek_portion)->toBe(0.75)   // 2 × 150 g
        ->and((float) ($this->dar)('kinder')->quantity_per_unit_g)->toBe(150.0);
});

it('Standard ohne eingetragene Grammatur: der Ertrag trägt den EK (98 · 3.5 6b; Rezept-Kopf seit 6c entfernt)', function () {
    $teller = ($this->dar)('teller');
    $teller->forceFill(['quantity_per_unit_g' => null])->save();

    $this->svc->recomputePreise($teller->fresh());

    $a = $this->a->fresh();
    expect((float) ($this->dar)('teller')->ek_portion)->toEqualWithDelta((float) $a->ek_per_kg_eur * (float) $a->yield_kg, 0.001);   // EK je kg × Ertrag
});
