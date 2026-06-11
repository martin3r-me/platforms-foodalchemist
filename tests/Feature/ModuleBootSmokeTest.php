<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * M0-05-Beispieltest (Harness-Smoke, Ebene b: bootet die Host-App).
 * Beweist, dass der ServiceProvider im Test-Kernel läuft:
 * mergeConfigFrom() in register() muss die Modul-Config bereitstellen.
 */
it('bootet die Host-App mit geladener Modul-Config', function () {
    expect(config('foodalchemist.routing.prefix'))->toBe('foodalchemist')
        ->and(config('foodalchemist.navigation'))->toBeArray()
        ->and(config('foodalchemist.guard'))->toBe('web');
});
