<?php

use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * §12.2 (Regelwerk Basisrezepte) — `role` ist der Sortier-Anker der Komponenten-Reihenfolge
 * eines Gerichts: Bodenebene (Sauce/Jus) → Hauptkomponente → Beilage → Garnitur.
 *
 * Der Generator-Task bittet seit Spec 41 B2 darum, aber gebeten war nicht durchgesetzt:
 * `position` war schlicht die Emissions-Reihenfolge des Modells ($i + 1 in syncIngredients).
 * §12.4 nennt genau das als Anti-Pattern („role erfasst, aber ungenutzt").
 *
 * Reflection wie in DedupGuardTest — die Sortierung ist eine reine Funktion und soll ohne
 * Provider/DB prüfbar bleiben.
 *
 * @param  list<array<string, mixed>>  $zeilen
 * @return array{0: list<array<string, mixed>>, 1: int}
 */
function w12sort(array $zeilen): array
{
    $m = new ReflectionMethod(RecipeGeneratorService::class, 'sortiereNachRolle');
    $m->setAccessible(true);

    return $m->invoke(app(RecipeGeneratorService::class), $zeilen);
}

/** @param list<array<string, mixed>> $zeilen */
function w12namen(array $zeilen): array
{
    return array_map(fn ($z) => $z['raw_text'], $zeilen);
}

it('sortiert Komponenten in die Plating-Reihenfolge nach role', function () {
    // Emission in Anteil-%-Reihenfolge — genau der beobachtete Fehler (Faelle D4/E1).
    [$neu, $verschoben] = w12sort([
        ['raw_text' => 'Rinderfilet', 'role' => 'komponente'],
        ['raw_text' => 'Kürbispüree', 'role' => 'beilage'],
        ['raw_text' => 'Rinderjus', 'role' => 'aroma_treiber'],
        ['raw_text' => 'Kresse', 'role' => 'garnitur'],
    ]);

    expect(w12namen($neu))->toBe(['Rinderjus', 'Rinderfilet', 'Kürbispüree', 'Kresse'])
        ->and($verschoben)->toBeGreaterThan(0);
});

it('laesst eine bereits korrekte Reihenfolge unberuehrt und meldet 0', function () {
    $zeilen = [
        ['raw_text' => 'Jus: Burgunder', 'role' => 'aroma_treiber'],
        ['raw_text' => 'Ochsenbacke', 'role' => 'komponente'],
        ['raw_text' => 'Selleriepüree', 'role' => 'beilage'],
        ['raw_text' => 'Perlzwiebeln', 'role' => 'beilage'],
        ['raw_text' => 'Crunch', 'role' => 'garnitur'],
    ];

    [$neu, $verschoben] = w12sort($zeilen);

    // Das ist der real gemessene Fall (Gerichte 3684/3685 auf demo): das Modell lag richtig.
    expect(w12namen($neu))->toBe(w12namen($zeilen))
        ->and($verschoben)->toBe(0);
});

it('haelt die Binnenordnung innerhalb einer Rolle — dort steckt kulinarisches Urteil', function () {
    // Drei Beilagen: welche zuerst auf den Teller kommt, weiss keine Rang-Tabelle.
    [$neu, $verschoben] = w12sort([
        ['raw_text' => 'Püree', 'role' => 'beilage'],
        ['raw_text' => 'Perlzwiebeln', 'role' => 'beilage'],
        ['raw_text' => 'Pfifferlinge', 'role' => 'beilage'],
        ['raw_text' => 'Jus', 'role' => 'aroma_treiber'],
    ]);

    expect(w12namen($neu))->toBe(['Jus', 'Püree', 'Perlzwiebeln', 'Pfifferlinge'])
        ->and($verschoben)->toBe(4);
});

it('stellt Zeilen ohne Rolle hinten an, ohne ihre Reihenfolge zu zerstoeren', function () {
    [$neu] = w12sort([
        ['raw_text' => 'Ohne A', 'role' => null],
        ['raw_text' => 'Jus', 'role' => 'aroma_treiber'],
        ['raw_text' => 'Ohne B', 'role' => ''],
        ['raw_text' => 'Filet', 'role' => 'komponente'],
    ]);

    expect(w12namen($neu))->toBe(['Jus', 'Filet', 'Ohne A', 'Ohne B']);
});

it('ignoriert unbekannte Rollen-Freitexte statt sie zu erfinden', function () {
    [$neu] = w12sort([
        ['raw_text' => 'Phantasie', 'role' => 'hauptdarsteller'],
        ['raw_text' => 'Jus', 'role' => 'aroma_treiber'],
    ]);

    // 'hauptdarsteller' ist nicht im Vokabular (SpeisenKlassenService::ROLLEN) → Rang 99.
    expect(w12namen($neu))->toBe(['Jus', 'Phantasie']);
});
