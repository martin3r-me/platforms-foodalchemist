<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Ki;
use Platform\FoodAlchemist\Support\KiKlassen;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * KI-Klassen STARK/MITTEL/LEICHT (16.10.2026): ein Feld `klasse` je Prompt-Key bestimmt Modell + Reasoning; leer = Plattform-Default;
 * die Stufen A–D und der Non-Food-Schalter sind abgelöst. Wirkung auf den Gateway-Call → AiCallLogTest (Klassen-Modell, Override, leer).
 */
it('Klassen-Modell und Reasoning kommen aus foodalchemist.ai.klassen; leer = null (Plattform-Default)', function () {
    foreach (KiKlassen::ALLE as $k) {
        expect(KiKlassen::modell($k))->toBeNull()->and(KiKlassen::reasoning($k))->toBeNull();
    }

    config(['foodalchemist.ai.klassen.mittel.model' => ' gpt-x ', 'foodalchemist.ai.klassen.mittel.reasoning' => 'low']);

    expect(KiKlassen::modell('mittel'))->toBe('gpt-x')
        ->and(KiKlassen::reasoning('mittel'))->toBe('low')
        ->and(KiKlassen::modell('stark'))->toBeNull()          // eine Klasse zieht die anderen nicht mit
        ->and(KiKlassen::modell('leicht'))->toBeNull();
});

it('fehlende oder ungültige Klasse fällt auf STARK (nie still ein schwaches Modell); Log-Codes S/M/L', function () {
    expect(KiKlassen::von(['task' => 'x']))->toBe('stark')
        ->and(KiKlassen::von(['klasse' => 'billig']))->toBe('stark')
        ->and(KiKlassen::von(['klasse' => 'leicht']))->toBe('leicht')
        ->and(KiKlassen::von(null))->toBe('stark')
        ->and(array_map(KiKlassen::logCode(...), KiKlassen::ALLE))->toBe(['S', 'M', 'L'])
        ->and(KiKlassen::logLabel('L'))->toBe('Leicht')->and(KiKlassen::logLabel('D'))->toBe('Sprachbefehl');
});

it('die alten Stufen-Variablen sind abgelöst: ai.tiers und ai.nonfood_model gibt es nicht mehr, Warnung wenn sie noch gesetzt sind', function () {
    expect(config('foodalchemist.ai'))->not->toHaveKey('tiers')->and(config('foodalchemist.ai'))->not->toHaveKey('nonfood_model');
    expect(KiKlassen::abgeloesteStufenGesetzt())->toBe([]);

    config(['foodalchemist.ai.tiers_abgeloest.B' => 'altes-modell']);
    expect(KiKlassen::abgeloesteStufenGesetzt())->toBe(['FOODALCHEMIST_AI_TIER_B']);
});

it('Settings „Modellstufen" zeigt die drei Klassen mit ihren Schritten, dem Modell und der Reasoning-Stufe', function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.klassen.leicht.model' => 'leicht-modell-v1', 'foodalchemist.ai.klassen.stark.reasoning' => 'high']);

    Livewire::test(Ki::class)
        ->assertSeeHtml('data-ki-klassen')
        ->assertSee('Stark')->assertSee('Mittel')->assertSee('Leicht')
        ->assertSee('leicht-modell-v1')
        ->assertSee('Reasoning: high')
        ->assertDontSeeHtml('data-ki-abgeloest');

    config(['foodalchemist.ai.tiers_abgeloest.D' => 'altes-modell']);
    Livewire::test(Ki::class)->assertSeeHtml('data-ki-abgeloest')->assertSee('FOODALCHEMIST_AI_TIER_D');
});

it('die drei gpt-5.6-Modelle der Klassen sind im Kostenrechner gepreist (sol stark, terra mittel, luna leicht)', function () {
    $rechner = app(\Platform\FoodAlchemist\Services\Ai\AiCostCalculator::class);
    $zeile = fn (string $m) => (object) ['feature' => 'recipe.generator', 'model' => $m, 'tokens_in' => 1_000_000, 'tokens_cached' => 0, 'tokens_out' => 1_000_000, 'calls' => 1, 't_in' => 1_000_000, 't_cached' => 0, 't_out' => 1_000_000];

    expect($rechner->costUsd($zeile('gpt-5.6-sol')))->toBe(24.0)
        ->and($rechner->costUsd($zeile('gpt-5.6-terra')))->toBe(14.0)
        ->and($rechner->costUsd($zeile('gpt-5.6-luna')))->toBe(1.4)
        ->and($rechner->costUsd($zeile('gibt-es-nicht')))->toBeNull();   // Gegenprobe: unbekanntes Modell = Preis unbekannt, nie 0
});
