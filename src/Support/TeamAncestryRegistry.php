<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Registry der Model-Klassen, die eine Team-Ancestry-Kette gecacht haben (22·H1 / V-048).
 *
 * Warum es diese Klasse gibt: `BelongsToTeamHierarchy::$teamAncestryCache` ist eine
 * Trait-Statik und existiert damit **pro nutzender Model-Klasse** (Bestand: 77 Models).
 * Wer den Cache leeren will, muss also jede Klasse einzeln anfassen — bis hierher tat das
 * eine handgepflegte Liste im Test-Harness, die 14 Klassen kannte. Der Fehlerfall einer
 * unvollständigen Liste ist nicht „Test rot", sondern **„D1-Test grün aus dem falschen
 * Grund"**: ein Leak-Test mit stale Kette prüft eine Sichtbarkeit, die es zur Laufzeit
 * nicht gibt — und D1 (Team-Isolation) ist die Regel, die die Suite tragen soll.
 *
 * Statt aufzuzählen wird registriert: jede Klasse trägt sich ein, sobald sie eine Kette
 * cacht. Damit ist die Menge der zu leerenden Klassen per Konstruktion genau die Menge
 * der Klassen, die überhaupt etwas zu leeren haben.
 *
 * Eine Statik in einem Trait hilft hier nicht (sie wäre wieder pro Klasse), und
 * `BelongsToTeamHierarchy::flushAll()` wäre ein direkter statischer Trait-Aufruf — seit
 * PHP 8.1 deprecated. Darum eine eigene, gemeinsame Klasse: sie ist der einzige Ort mit
 * genau EINER Statik.
 */
final class TeamAncestryRegistry
{
    /** @var array<class-string, true> */
    private static array $klassen = [];

    /** Wird vom Trait beim ersten Cache-Schreiben je Klasse gerufen. */
    public static function register(string $klasse): void
    {
        self::$klassen[$klasse] = true;
    }

    /**
     * Alle bekannten Ancestry-Caches leeren.
     *
     * Pflicht in Test-Setups, die Teams neu seeden (der Cache lebt im Test pro Prozess,
     * nicht pro Test) — gerufen aus {@see \Platform\FoodAlchemist\Tests\TestCase::setUp}.
     */
    public static function flushAll(): void
    {
        foreach (array_keys(self::$klassen) as $klasse) {
            $klasse::flushTeamAncestryCache();
        }
    }

    /**
     * Diagnose: welche Klassen haben in diesem Prozess eine Kette gecacht?
     *
     * @return array<int, class-string>
     */
    public static function registered(): array
    {
        return array_keys(self::$klassen);
    }
}
