<?php

use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation as Station;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe as Recipe;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Stufe 3 P3.2 — nicht-lineare Arbeitszeit (Rüst + Marginal + Topf-Deckel) und P3.1 —
 * Kapazität aus der Rollen-Besetzung. Reine Modell-Logik, ohne DB-Schreiben.
 */

function recipe(array $attrs): Recipe
{
    $r = new Recipe();
    $r->forceFill(array_merge(['yield_kg' => 4.0, 'is_sales_recipe' => false], $attrs));

    return $r;
}

it('reproduziert das heutige lineare Verhalten (Defaults: setup=0, kein Deckel)', function () {
    $r = recipe(['work_time_min' => 30, 'yield_kg' => 4.0]);

    // roh 1.0 → 1 Koch-Batch → 30; roh 2.0 → 2 → 60; roh 1.2 → ceil = 2 → 60 (wie ceil-Ansätze heute)
    expect($r->arbeitszeitMin(1.0, false))->toBe(30)
        ->and($r->arbeitszeitMin(2.0, false))->toBe(60)
        ->and($r->arbeitszeitMin(1.2, false))->toBe(60);
});

it('zählt die Rüstzeit nur EINMAL je Lauf — 2× Menge ≠ 2× Zeit', function () {
    $r = recipe(['work_time_min' => 30, 'setup_time_min' => 20, 'yield_kg' => 4.0]);

    // roh 1 → 20 + 30 = 50; roh 2 → 20 + 60 = 80 (nicht 100). Sub-linear: das ist der Kern.
    expect($r->arbeitszeitMin(1.0, false))->toBe(50)
        ->and($r->arbeitszeitMin(2.0, false))->toBe(80);
});

it('fasst mit größerem Topf-Deckel mehr Menge in EINEN Koch-Vorgang', function () {
    // yield 4 kg/Ansatz, aber 8-kg-Topf: 8 kg Bedarf = 1 Koch-Vorgang statt 2.
    $r = recipe(['work_time_min' => 30, 'setup_time_min' => 20, 'yield_kg' => 4.0, 'batch_max_kg' => 8.0]);

    expect($r->kochBatches(2.0))->toBe(1)                 // 2 Ertrags-Ansätze, aber 1 Topf
        ->and($r->arbeitszeitMin(2.0, false))->toBe(50);  // 20 + 30 (nur ein Koch-Vorgang)
});

it('nimmt den kleineren Deckel aus Rezept und Posten (Minimum gilt)', function () {
    $r = recipe(['work_time_min' => 30, 'yield_kg' => 4.0, 'batch_max_kg' => 8.0]);

    // Posten-Deckel 4 (kleiner) gewinnt → 8 kg = 2 Koch-Vorgänge.
    expect($r->kochBatches(2.0, stationDeckel: 4.0))->toBe(2)
        ->and($r->kochBatches(2.0, stationDeckel: 20.0))->toBe(1);  // Posten größer → Rezept-8 gilt
});

it('gibt null, wenn gar keine Zeit hinterlegt ist', function () {
    expect(recipe(['work_time_min' => null, 'setup_time_min' => null])->arbeitszeitMin(3.0, false))->toBeNull();
});

it('leitet die Posten-Kapazität aus Köpfen × Schicht ab, Besetzung gewinnt', function () {
    $s = new Station();
    $s->forceFill(['besetzung' => ['1' => 2, '2' => 1], 'schicht_minuten' => 480]);   // 3 Köpfe × 480
    expect($s->koepfe())->toBe(3)
        ->and($s->abgeleiteteKapazitaet())->toBe(1440)
        ->and($s->kapazitaetAm(new DateTime('2026-08-05')))->toBe(1440);

    // Besetzung überschreibt den manuellen Tageswert (Spec 35 K1)
    $s->kapazitaet_min_pro_tag = 900;
    expect($s->kapazitaetAm(new DateTime('2026-08-05')))->toBe(1440);

    // Wochentag-Override gewinnt über alles
    $s->kapazitaet_wochentag = ['3' => 300];   // Mi
    expect($s->kapazitaetAm(new DateTime('2026-08-05')))->toBe(300);  // 2026-08-05 ist ein Mittwoch
});
