<?php

namespace Platform\FoodAlchemist\Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;

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
     * Untergrenze für das Speicher-Limit der Suite (22·H1 / V-001).
     *
     * Die Suite rendert echte Blades und baut im Fehlerfall Whoops-Frames auf; im
     * PHP-Default 128M bricht sie mit „Allowed memory size … exhausted" ab. Der Crash
     * SIEHT wie ein Code-Fehler aus, ist aber Konfiguration — jeder (Mensch oder
     * Routine), der `vendor/bin/pest` nackt startet, liest sonst ein falsches Ergebnis
     * und sucht am falschen Ende.
     */
    private const MIN_MEMORY_LIMIT_BYTES = 1073741824; // 1G

    private static bool $memoryLimitGeprueft = false;

    protected function setUp(): void
    {
        self::hebeSpeicherLimit();

        parent::setUp();

        // Ancestry-Ketten aus früheren Tests desselben Prozesses verwerfen (V-048).
        // Bewusst hier und nicht als Handliste im Harness: der Cache ist eine Trait-Statik
        // PRO nutzender Model-Klasse (77 Models) und lebt im Test pro Prozess. Eine
        // aufgezählte Liste ist genau so vollständig, wie jemand sie zuletzt gepflegt hat —
        // und der Fehlerfall ist nicht „Test rot", sondern „D1-Test grün aus dem falschen
        // Grund" (stale Kette prüft eine Sichtbarkeit, die es zur Laufzeit nicht gibt).
        TeamAncestryRegistry::flushAll();
    }

    /**
     * Speicher-Limit einmal je Prozess auf die Untergrenze heben (V-001).
     *
     * Verankert das Limit dort, wo es mitreist: die Host-`phpunit.xml` (Sandbox) kennt es
     * ebenfalls, aber sie ist Wegwerf und liegt in einem anderen Repo — ein neuer Host
     * (oder ein `--configuration`-freier Aufruf) hätte es sonst wieder nicht. `-1`
     * (unbegrenzt) und ein bereits höheres Limit bleiben unangetastet.
     */
    private static function hebeSpeicherLimit(): void
    {
        if (self::$memoryLimitGeprueft) {
            return;
        }
        self::$memoryLimitGeprueft = true;

        $aktuell = (string) ini_get('memory_limit');
        if (trim($aktuell) === '-1') {
            return;
        }

        if (self::inBytes($aktuell) >= self::MIN_MEMORY_LIMIT_BYTES) {
            return;
        }

        @ini_set('memory_limit', '1G');
    }

    /** PHP-Shorthand („128M", „1G", „134217728") in Bytes. */
    private static function inBytes(string $wert): int
    {
        $wert = trim($wert);
        if ($wert === '') {
            return 0;
        }

        $zahl = (int) $wert;
        return match (strtolower(substr($wert, -1))) {
            'g' => $zahl * 1024 * 1024 * 1024,
            'm' => $zahl * 1024 * 1024,
            'k' => $zahl * 1024,
            default => $zahl,
        };
    }
}
