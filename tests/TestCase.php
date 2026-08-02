<?php

namespace Platform\FoodAlchemist\Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Basis-TestCase für alle Modul-Tests (M0-05).
 *
 * Bootet die Host-App, in deren vendor/ das Modul installiert ist
 * (lokal: sandbox-food-alchemist, später: office.bhg-Host-App).
 * Laravel ermittelt den App-Pfad selbst über den Composer-Autoloader —
 * deshalb hier bewusst kein createApplication()-Override.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Diese Suite bindet bewusst KEIN RefreshDatabase/DatabaseTransactions (Konvention,
     * siehe _SANDBOX_NOTES.md „Test-DB-Isolation"): die Isolation entsteht daraus, dass die
     * Test-DB SQLite :memory: ist und Laravel sie pro Test frisch aufbaut (die Tests
     * migrieren+seeden selbst, z.B. via SeedsTeamHierarchy). Läuft die Suite versehentlich
     * gegen eine PERSISTENTE DB (etwa weil eine Shell-Variable DB_CONNECTION=mysql die
     * phpunit.xml-Defaults überstimmt und die Dev-MySQL `foodalchemist` trifft), bleibt State
     * zwischen Tests liegen → reihenfolgen-abhängige Flakiness (Root-Ursache der
     * KnowledgeBind-Flakiness, 2026-07-24). Hier hart und erklärt stoppen statt still pollieren.
     *
     * Bewusst gegen eine persistente DB testen (selten, z.B. MySQL-spezifisches Verhalten):
     * FA_ALLOW_PERSISTENT_TEST_DB=1 setzen.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertFreshTestDatabase();
    }

    private function assertFreshTestDatabase(): void
    {
        if (env('FA_ALLOW_PERSISTENT_TEST_DB')) {
            return;
        }

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        $isFreshPerTest = $driver === 'sqlite' && $database === ':memory:';

        if (! $isFreshPerTest) {
            $this->fail(
                "Modul-Tests laufen ohne DB-Isolation (kein RefreshDatabase/DatabaseTransactions) und "
                . "erwarten eine pro Test frische DB — SQLite :memory:. Aktiv ist aber '{$connection}' "
                . "(driver={$driver}, database={$database}). Gegen eine persistente DB akkumuliert State "
                . "zwischen Tests → Pollution/Flakiness. Ursache meist eine gesetzte DB_*-Shell-Variable, "
                . "die die phpunit.xml-Defaults überstimmt (`unset DB_CONNECTION DB_DATABASE DB_URL`). "
                . "Bewusst so gewollt? FA_ALLOW_PERSISTENT_TEST_DB=1 setzen. Details → _SANDBOX_NOTES.md."
            );
        }
    }
}
