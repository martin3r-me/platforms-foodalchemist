<?php

use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Plan 98 B (g), Entscheid Dominique 23.11.2026 — Gericht mit festem Ertrag: „Gegart (Ertrag)“. Die angezeigte Grammatur ist der
 * Ertrag je Portion, Kosten und Produktion rechnen weiter auf den rohen Mengen der Bestandteile.
 *
 * Schmorbraten, eine Portion: 250 g Rinderschulter roh à 14 €/kg = 3,50 €, fester Ertrag 0,2 kg (gegart) ⇒ 17,50 €/kg.
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $this->svc = app(DarreichungService::class);
    $schulter = ($this->gp)('Rinderschulter (Portion)', 14.00);
    [$this->r, $z] = ($this->rezept)('schmorbraten-portion', 'Schmorbraten Portion', [[$schulter, 250]]);
    $this->zutat = $z['Rinderschulter (Portion)'];
    $this->r->update(['yield_kg_manual' => 0.2]);
    app(RecipeRecomputeService::class)->recomputePipeline($this->r->id);
    // Altbestand: freie Grammatur 200 g gegart
    $this->std = ($this->darreichung)($this->r, 'teller', ['is_standard' => true, 'menge_vom_standard' => false, 'quantity_per_unit_g' => 200]);
    $this->svc->recomputeFuerRezept($this->r->id);
    $this->messen = fn () => [
        'ek' => (float) $this->std->fresh()->ek_portion,
        'je_zutat' => $this->svc->produktionsAnteil($this->r->fresh(), $this->std->fresh(), 1, $this->rootTeam)['je_zutat']
            ?? [$this->zutat->id => $this->svc->produktionsAnteil($this->r->fresh(), $this->std->fresh(), 1, $this->rootTeam)['ansaetze']],
        'ansaetze' => round($this->svc->produktionsAnteil($this->r->fresh(), $this->std->fresh(), 1, $this->rootTeam)['ansaetze'], 6),
    ];
});

it('Standard automatisch bei festem Ertrag: Grammatur gegart (Ertrag), EK je Portion und Produktion unverändert, Kopf unberührt', function () {
    $vorher = ($this->messen)();
    $kopfVorher = $this->r->fresh()->getAttributes()['sales_quantity_per_unit_g'] ?? null;
    expect($vorher['ek'])->toBe(3.5)->and($vorher['ansaetze'])->toBe(1.0);

    $this->svc->setzeStandardmenge($this->rootTeam, $this->std->id, true);   // automatisch: Summe der Bestandteile, gegart

    $s = $this->std->fresh();
    expect((bool) $s->menge_vom_standard)->toBeTrue()
        ->and($this->svc->grammJeEinheit($s, $this->r->fresh()))->toBe(200.0)        // gegart, nicht 250 g roh
        ->and((float) $s->quantity_per_unit_g)->toBe(200.0)
        ->and(($this->messen)())->toEqual($vorher)                                   // EK 3,50 €, 1 Ansatz, 250 g Schulter
        ->and($this->r->fresh()->getAttributes()['sales_quantity_per_unit_g'] ?? null)->toBe($kopfVorher);   // 98 · 3.5 6b: kein Spiegel
});

it('Variante mit Anteil: der Anteil wirkt auf die Bestandteile, die Grammatur skaliert mit dem Ertrag (200 g → 50 % = 100 g)', function () {
    $this->svc->setzeStandardmenge($this->rootTeam, $this->std->id, true);
    $v = $this->svc->anlegen($this->rootTeam, $this->r->id, ($this->form)('klein')->id);
    $v = $this->svc->setzeAnteil($this->rootTeam, $v->id, 50);

    expect((float) $v->deltas()->first()->quantity_override_g)->toBe(125.0)        // roh
        ->and((float) $v->quantity_per_unit_g)->toBe(100.0)                         // gegart
        ->and((float) $v->ek_portion)->toBe(1.75)
        ->and($this->svc->produktionsAnteil($this->r->fresh(), $v, 1, $this->rootTeam)['je_zutat'][$this->zutat->id])->toBe(0.5);
});

it('ohne festen Ertrag bleibt die Grammatur die rohe Summe der Bestandteile', function () {
    $this->r->update(['yield_kg_manual' => null]);
    app(RecipeRecomputeService::class)->recomputePipeline($this->r->id);
    $this->svc->setzeStandardmenge($this->rootTeam, $this->std->id, true);

    expect((float) $this->std->fresh()->quantity_per_unit_g)->toBe(250.0)
        ->and($this->svc->garfaktor($this->r->fresh()))->toBe(1.0);
});
