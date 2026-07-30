<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * A-05 / V-056 (Regressionsschutz): Die Modulsuite bindet bewusst KEIN RefreshDatabase —
 * ihre Isolation IST die Annahme „SQLite :memory:, pro Test frisch". Läuft sie gegen eine
 * persistente DB, akkumuliert State (Root-Ursache der KnowledgeBind-Flakiness 2026-07-24)
 * und Migrationen träfen die Dev-MySQL `foodalchemist` — dieselbe, die `fa_seed.sh` befüllt.
 *
 * Der Guard existierte bis 2026-07-30 nur in `phpunit.xml` und `_SANDBOX_NOTES.md` als
 * BEHAUPTUNG; im Code stand er nicht. Genau deshalb prüft dieser Vertrag beides: die
 * Entscheidungslogik inhaltlich und die Verdrahtung vor dem App-Boot.
 */
it('erkennt eine persistente DB in der Umgebung', function () {
    // Der reale Unfall: Shell-Variable überstimmt die phpunit.xml-Defaults.
    expect(TestCase::verstossGegenTestDb('mysql', ':memory:', null))->toBe('DB_CONNECTION=mysql')
        ->and(TestCase::verstossGegenTestDb('sqlite', 'foodalchemist', null))->toBe('DB_DATABASE=foodalchemist')
        ->and(TestCase::verstossGegenTestDb('sqlite', ':memory:', 'mysql://fa:fa@127.0.0.1/foodalchemist'))
        ->toBe('DB_URL=mysql://fa:fa@127.0.0.1/foodalchemist')
        // Eine Datei-SQLite ist ebenfalls persistent — der Treiber allein genügt nicht.
        ->and(TestCase::verstossGegenTestDb('sqlite', database_path('database.sqlite'), null))->not->toBeNull();
});

it('lässt die isolierte Testumgebung durch', function () {
    expect(TestCase::verstossGegenTestDb('sqlite', ':memory:', null))->toBeNull()
        // phpunit.xml setzt DB_URL bewusst auf einen Leerstring — kein Verstoß.
        ->and(TestCase::verstossGegenTestDb('sqlite', ':memory:', ''))->toBeNull()
        // Nichts gesetzt → hier keine Aussage; die gebootete Config entscheidet (zweite Schicht).
        ->and(TestCase::verstossGegenTestDb(null, null, null))->toBeNull();
});

it('prüft die Umgebung VOR dem App-Boot', function () {
    // Reihenfolge ist die halbe Absicherung: `parent::setUp()` baut die App und fragt dabei
    // schon die DB ab (Modul-Registrierung des Hosts). Eine Prüfung danach verhindert keine
    // Verbindung mehr — sie hätte den Unfall nur protokolliert.
    // Kommentare zuerst entfernen: die Erklärtexte im TestCase nennen dieselben Bezeichner
    // wörtlich, ein reiner strpos über den Rohtext misst also die Prosa statt den Code.
    $code = implode('', array_map(
        fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
        token_get_all(file_get_contents((new ReflectionClass(TestCase::class))->getFileName()))
    ));

    $vorBoot = strpos($code, 'assertFreshTestDatabaseEnv()');
    $boot = strpos($code, 'parent::setUp()');
    $nachBoot = strpos($code, 'assertFreshTestDatabase()');

    expect($vorBoot)->not->toBeFalse('Vor-Boot-Guard fehlt')
        ->and($nachBoot)->not->toBeFalse('Nach-Boot-Guard fehlt')
        ->and($vorBoot)->toBeLessThan($boot)
        ->and($nachBoot)->toBeGreaterThan($boot);
});

it('läuft im echten Lauf tatsächlich auf SQLite :memory:', function () {
    // Selbstauskunft der laufenden Suite — schlägt fehl, wenn der Guard je aufgeweicht wird.
    $connection = config('database.default');

    expect(config("database.connections.{$connection}.driver"))->toBe('sqlite')
        ->and(config("database.connections.{$connection}.database"))->toBe(':memory:');
});
