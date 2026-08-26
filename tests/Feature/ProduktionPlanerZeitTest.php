<?php

use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation as Station;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe as Recipe;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Stufe 3 P3.2 — nicht-lineare AKTIVE Belegzeit (Rüst + Marginal je Koch-Vorgang unter Topf-Deckel)
 * + passive Standzeit/Durchlaufzeit + P3.1 Kapazität aus der Rollen-Besetzung. Reine Modell-Logik,
 * ohne DB-Schreiben.
 *
 * 2026-08-21: Die frühere „1 Ertrags-Ansatz = 1 Koch-Vorgang"-Annahme (ceil der Ansätze) zählte
 * z. B. 4,69 kg als zehn 469-g-Töpfe und ver-10-fachte so die Arbeitszeit (200 statt ~20 min).
 * Ersetzt durch den globalen Default-Kessel (Recipe::DEFAULT_BATCH_MAX_KG) — physisch, nicht erfunden.
 */

function recipe(array $attrs): Recipe
{
    $r = new Recipe();
    $r->forceFill(array_merge(['yield_kg' => 4.0, 'is_sales_recipe' => false], $attrs));

    return $r;
}

it('faltet Mengen unter dem globalen Default-Kessel in EINEN Koch-Vorgang (kein „1 Ansatz = 1 Topf" mehr)', function () {
    // yield 4 kg/Ansatz, Default-Kessel 20 kg, kein eigener Deckel:
    // roh 2 = 8 kg → 1 Vorgang; roh 5 = 20 kg → 1 Vorgang (voll, aber ein Topf).
    $r = recipe(['work_time_min' => 30, 'yield_kg' => 4.0]);

    expect($r->kochBatches(2.0))->toBe(1)
        ->and($r->kochBatches(5.0))->toBe(1)
        ->and($r->arbeitszeitMin(2.0, false))->toBe(30)   // früher 60 (2× ceil-Ansätze) — das war der 200-min-Bug
        ->and($r->arbeitszeitMin(5.0, false))->toBe(30);
});

it('erzwingt weitere Koch-Vorgänge erst über dem Default-Kessel (stufig, nicht ceil-linear)', function () {
    $r = recipe(['work_time_min' => 30, 'yield_kg' => 4.0]);   // Default 20 kg

    // roh 6 = 24 kg → 2 Vorgänge; roh 11 = 44 kg → 3 Vorgänge.
    expect($r->kochBatches(6.0))->toBe(2)
        ->and($r->kochBatches(11.0))->toBe(3)
        ->and($r->arbeitszeitMin(6.0, false))->toBe(60)
        ->and($r->arbeitszeitMin(11.0, false))->toBe(90);
});

it('zählt die Rüstzeit nur EINMAL je Lauf — mehr Töpfe ≠ proportional mehr Zeit', function () {
    // Expliziter 8-kg-Deckel (Default-unabhängig): yield 4 → roh 1 = 1 Topf, roh 3 = 12 kg = 2 Töpfe.
    $r = recipe(['work_time_min' => 30, 'setup_time_min' => 20, 'yield_kg' => 4.0, 'batch_max_kg' => 8.0]);

    expect($r->arbeitszeitMin(1.0, false))->toBe(50)       // 20 + 30×1
        ->and($r->arbeitszeitMin(3.0, false))->toBe(80);   // 20 + 30×2 (nicht 20+90) — sub-linear
});

it('nimmt den kleineren Deckel aus Rezept und Posten (Minimum gilt)', function () {
    $r = recipe(['work_time_min' => 30, 'yield_kg' => 4.0, 'batch_max_kg' => 8.0]);

    // Posten-Deckel 4 (kleiner) gewinnt → 8 kg = 2 Koch-Vorgänge.
    expect($r->kochBatches(2.0, stationDeckel: 4.0))->toBe(2)
        ->and($r->kochBatches(2.0, stationDeckel: 20.0))->toBe(1);  // Posten größer → Rezept-8 gilt
});

it('verwendet für VK- und Basisrezepte dieselbe physische Batchlogik', function () {
    $r = recipe(['work_time_min' => 30, 'yield_kg' => 4.0, 'is_sales_recipe' => true]);

    // Die Rezeptart ändert den Produktionsprozess nicht: 8 kg und 2 kg passen jeweils
    // in einen Vorgang unter dem 20-kg-Team-/System-Standard.
    expect($r->arbeitszeitMin(2.0, true))->toBe(30)
        ->and($r->arbeitszeitMin(0.5, true))->toBe(30);
});

it('gibt die passive Standzeit mengenunabhängig zurück (1× je Lauf)', function () {
    expect(recipe(['standzeit_min' => 45])->standzeitMin())->toBe(45)
        ->and(recipe(['standzeit_min' => 0])->standzeitMin())->toBeNull()
        ->and(recipe(['standzeit_min' => null])->standzeitMin())->toBeNull();
});

it('summiert Durchlaufzeit = aktive Belegzeit + Standzeit (Standzeit NICHT in der Belegzeit)', function () {
    // yield 4, Default-Kessel: roh 2 = 8 kg = 1 Vorgang → aktiv 30; + 45 Standzeit = 75.
    $r = recipe(['work_time_min' => 30, 'standzeit_min' => 45, 'yield_kg' => 4.0]);

    expect($r->durchlaufzeitMin(2.0, false))->toBe(75)
        ->and($r->arbeitszeitMin(2.0, false))->toBe(30);
});

it('gibt null, wenn gar keine Zeit hinterlegt ist', function () {
    $leer = ['work_time_min' => null, 'setup_time_min' => null, 'standzeit_min' => null];

    expect(recipe($leer)->arbeitszeitMin(3.0, false))->toBeNull()
        ->and(recipe($leer)->durchlaufzeitMin(3.0, false))->toBeNull();
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
