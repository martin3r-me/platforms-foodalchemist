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

        // Erste Schicht VOR dem App-Boot: `parent::setUp()` baut die App und fragt dabei
        // schon die DB (Modul-Registrierung des Hosts). Eine Prüfung danach käme zu spät,
        // um eine Verbindung zur falschen DB zu verhindern. (A-05 / V-056)
        $this->assertFreshTestDatabaseEnv();

        parent::setUp();

        // Zweite Schicht: die effektiv gebootete Config — greift auch, wenn die falsche DB
        // nicht über DB_*-Variablen, sondern über eine Host-`.env` oder einen abweichenden
        // Default-Connection-Namen hereinkommt.
        $this->assertFreshTestDatabase();

        // Ancestry-Ketten aus früheren Tests desselben Prozesses verwerfen (V-048).
        // Bewusst hier und nicht als Handliste im Harness: der Cache ist eine Trait-Statik
        // PRO nutzender Model-Klasse (77 Models) und lebt im Test pro Prozess. Eine
        // aufgezählte Liste ist genau so vollständig, wie jemand sie zuletzt gepflegt hat —
        // und der Fehlerfall ist nicht „Test rot", sondern „D1-Test grün aus dem falschen
        // Grund" (stale Kette prüft eine Sichtbarkeit, die es zur Laufzeit nicht gibt).
        TeamAncestryRegistry::flushAll();
    }

    /**
     * Harter Guard: diese Suite bindet bewusst KEIN RefreshDatabase/DatabaseTransactions
     * (Konvention, siehe _SANDBOX_NOTES.md „Test-DB-Isolation"). Die Isolation entsteht
     * allein daraus, dass die Test-DB SQLite `:memory:` ist und Laravel sie pro Test frisch
     * aufbaut — die Tests migrieren und seeden selbst (z.B. via `SeedsTeamHierarchy`).
     *
     * Läuft die Suite versehentlich gegen eine PERSISTENTE DB, bleibt State zwischen Tests
     * liegen → reihenfolgen-abhängige Flakiness (Root-Ursache der KnowledgeBind-Flakiness,
     * 2026-07-24). Der Weg dorthin ist unauffällig: Laravels immutables Dotenv-Repository
     * ignoriert PHPUnits `<env force="true">`, eine gesetzte Shell-Variable
     * `DB_CONNECTION=mysql` überstimmt also die `phpunit.xml`-Defaults und trifft die
     * Dev-MySQL `foodalchemist` — dieselbe DB, die `fa_seed.sh` befüllt. Hier hart und
     * erklärt stoppen statt still zu pollieren oder gar fremde Daten zu migrieren.
     *
     * Bewusst gegen eine persistente DB testen (selten, z.B. MySQL-spezifisches Verhalten):
     * `FA_ALLOW_PERSISTENT_TEST_DB=1` setzen.
     */
    private function assertFreshTestDatabaseEnv(): void
    {
        if (self::rohEnv('FA_ALLOW_PERSISTENT_TEST_DB') !== null) {
            return;
        }

        $verstoss = self::verstossGegenTestDb(
            self::rohEnv('DB_CONNECTION'),
            self::rohEnv('DB_DATABASE'),
            self::rohEnv('DB_URL'),
        );

        if ($verstoss !== null) {
            $this->fail(
                "Abbruch VOR dem App-Boot: die Umgebung zeigt auf eine persistente DB ({$verstoss}). "
                . "Diese Suite läuft ohne DB-Isolation und erwartet SQLite :memory: — sonst würden "
                . "Migrationen und Writes echte Daten treffen (die Dev-MySQL `foodalchemist` ist "
                . "dieselbe, die fa_seed.sh befüllt). Fix: `unset DB_CONNECTION DB_DATABASE DB_URL`. "
                . "Bewusst so gewollt? FA_ALLOW_PERSISTENT_TEST_DB=1 setzen."
            );
        }
    }

    /**
     * Die Entscheidung als reine Funktion — bewusst öffentlich und ohne Laravel/Env, damit
     * `TestDbGuardVertragTest` sie direkt prüfen kann. Ein Guard, der nur im Ernstfall
     * ausgeführt wird, ist ein Guard, dessen Ausfall niemand merkt (Lehre aus V-056: der
     * Guard war zwei Dokumente lang „die harte Absicherung" und existierte im Code nicht).
     *
     * Nur widersprechen, wenn wirklich ein Wert gesetzt ist: ist nichts gesetzt, entscheidet
     * die gebootete Config (zweite Schicht). PHPUnit schreibt seine `<env>`-Defaults ohne
     * `force` NICHT über eine bestehende Shell-Variable — genau dieser Fall wird hier sichtbar.
     *
     * @return string|null Der benannte Verstoß, oder null wenn die Umgebung sauber ist.
     */
    public static function verstossGegenTestDb(?string $connection, ?string $database, ?string $url): ?string
    {
        return match (true) {
            $connection !== null && $connection !== 'sqlite' => "DB_CONNECTION={$connection}",
            $database !== null && $database !== ':memory:' => "DB_DATABASE={$database}",
            $url !== null && trim($url) !== '' => "DB_URL={$url}",
            default => null,
        };
    }

    /**
     * Rohe Umgebungsvariable ohne Laravel — vor `parent::setUp()` existiert weder `config()`
     * noch das Env-Repository. Liefert `null`, wenn der Wert nicht gesetzt ist.
     */
    private static function rohEnv(string $schluessel): ?string
    {
        foreach ([$_SERVER, $_ENV] as $ablage) {
            if (array_key_exists($schluessel, $ablage) && $ablage[$schluessel] !== false) {
                return (string) $ablage[$schluessel];
            }
        }

        $wert = getenv($schluessel);

        return $wert === false ? null : (string) $wert;
    }

    private function assertFreshTestDatabase(): void
    {
        if (env('FA_ALLOW_PERSISTENT_TEST_DB')) {
            return;
        }

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        $this->fail(
            "Modul-Tests laufen ohne DB-Isolation (kein RefreshDatabase/DatabaseTransactions) und "
            . "erwarten eine pro Test frische DB — SQLite :memory:. Aktiv ist aber '{$connection}' "
            . "(driver={$driver}, database={$database}). Gegen eine persistente DB akkumuliert State "
            . "zwischen Tests → Pollution/Flakiness, und Migrationen träfen echte Dev-Daten. Ursache "
            . "meist eine gesetzte DB_*-Shell-Variable, die die phpunit.xml-Defaults überstimmt "
            . "(`unset DB_CONNECTION DB_DATABASE DB_URL`). Bewusst so gewollt? "
            . "FA_ALLOW_PERSISTENT_TEST_DB=1 setzen. Details → _SANDBOX_NOTES.md."
        );
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
