<?php

use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

// Die Rangfolge kommt seit 11.10. aus der Regel gericht.teller_rolle (eine Quelle, kein Rückfall im Code) — darum mit
// migrierter DB statt als reine Funktion; ohne Regel gäbe es zu Recht keine Anrichte-Reihenfolge.
beforeEach(function () {
    $this->seedTeamHierarchy();
});

/**
 * §12.2 (Regelwerk Basisrezepte) — `role` ist der Sortier-Anker der Komponenten-Reihenfolge
 * eines Gerichts — seit 11.10. die Teller-Rollen in Anrichte-Reihenfolge (Regel gericht.teller_rolle):
 * basis → protein → beilage → sauce → crunch → saeure → aroma → garnitur.
 *
 * Der Generator-Task bittet seit Spec 41 B2 darum, aber gebeten war nicht durchgesetzt:
 * `position` war schlicht die Emissions-Reihenfolge des Modells ($i + 1 in syncIngredients).
 * §12.4 nennt genau das als Anti-Pattern („role erfasst, aber ungenutzt").
 *
 * Reflection wie in DedupGuardTest; die Rangfolge liest die Sortierung aus der Regel (DB nötig).
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

it('sortiert Komponenten in die Anrichte-Reihenfolge nach Teller-Rolle (Dominique 11.10.)', function () {
    // Emission in Anteil-%-Reihenfolge — genau der beobachtete Fehler (Faelle D4/E1).
    [$neu, $verschoben] = w12sort([
        ['raw_text' => 'Rinderfilet', 'role' => 'protein'],
        ['raw_text' => 'Kürbispüree', 'role' => 'basis'],
        ['raw_text' => 'Rinderjus', 'role' => 'sauce'],
        ['raw_text' => 'Kresse', 'role' => 'garnitur'],
    ]);

    // basis → protein → beilage → sauce → crunch → saeure → aroma → garnitur
    expect(w12namen($neu))->toBe(['Kürbispüree', 'Rinderfilet', 'Rinderjus', 'Kresse'])
        ->and($verschoben)->toBeGreaterThan(0);
});

it('laesst eine bereits korrekte Reihenfolge unberuehrt und meldet 0', function () {
    $zeilen = [
        ['raw_text' => 'Selleriepüree', 'role' => 'basis'],
        ['raw_text' => 'Ochsenbacke', 'role' => 'protein'],
        ['raw_text' => 'Perlzwiebeln', 'role' => 'beilage'],
        ['raw_text' => 'Jus: Burgunder', 'role' => 'sauce'],
        ['raw_text' => 'Crunch: Haselnuss', 'role' => 'crunch'],
        ['raw_text' => 'Kresse', 'role' => 'garnitur'],
    ];

    [$neu, $verschoben] = w12sort($zeilen);

    expect(w12namen($neu))->toBe(w12namen($zeilen))
        ->and($verschoben)->toBe(0);
});

it('haelt die Binnenordnung innerhalb einer Rolle — dort steckt kulinarisches Urteil', function () {
    // Drei Beilagen: welche zuerst auf den Teller kommt, weiss keine Rang-Tabelle.
    [$neu, $verschoben] = w12sort([
        ['raw_text' => 'Jus', 'role' => 'sauce'],
        ['raw_text' => 'Pfifferlinge', 'role' => 'beilage'],
        ['raw_text' => 'Perlzwiebeln', 'role' => 'beilage'],
        ['raw_text' => 'Püree', 'role' => 'basis'],
    ]);

    // Jus (0→3) und Püree (3→0) wandern; die Beilagen bleiben auf 1/2 und in ihrer Binnenordnung.
    expect(w12namen($neu))->toBe(['Püree', 'Pfifferlinge', 'Perlzwiebeln', 'Jus'])
        ->and($verschoben)->toBe(2);
});

it('stellt Zeilen ohne Rolle hinten an, ohne ihre Reihenfolge zu zerstoeren', function () {
    [$neu] = w12sort([
        ['raw_text' => 'Ohne A', 'role' => null],
        ['raw_text' => 'Jus', 'role' => 'sauce'],
        ['raw_text' => 'Ohne B', 'role' => ''],
        ['raw_text' => 'Filet', 'role' => 'protein'],
    ]);

    expect(w12namen($neu))->toBe(['Filet', 'Jus', 'Ohne A', 'Ohne B']);
});

it('ignoriert unbekannte Rollen-Freitexte statt sie zu erfinden', function () {
    [$neu] = w12sort([
        ['raw_text' => 'Phantasie', 'role' => 'hauptdarsteller'],
        ['raw_text' => 'Altwert', 'role' => 'aroma_treiber'],   // V-21 ist abgelöst → wie unbekannt
        ['raw_text' => 'Jus', 'role' => 'sauce'],
    ]);

    // nicht im Teller-Vokabular (Regel gericht.teller_rolle) → Rang 99, Reihenfolge bleibt.
    expect(w12namen($neu))->toBe(['Jus', 'Phantasie', 'Altwert']);
});
