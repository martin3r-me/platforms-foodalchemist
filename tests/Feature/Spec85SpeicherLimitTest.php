<?php

use Platform\FoodAlchemist\Support\Speicher;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * io 19.10.: `foodalchemist:recompute` brach sofort ab — PHP 8.5 wirft bei ini_set('memory_limit') über `max_memory_limit` (io CLI 512 MB).
 * Speicher::anheben hebt nur an, kappt am Server-Höchstwert und liefert dann einen Hinweis statt abzubrechen. Lokal (PHP < 8.5) gibt es
 * `max_memory_limit` nicht — der Höchstwert wird im Test ausdrücklich übergeben.
 */
beforeEach(fn () => $this->vorher = ini_get('memory_limit'));
afterEach(fn () => ini_set('memory_limit', $this->vorher));

it('über dem Server-Höchstwert: auf den Höchstwert gekappt, Hinweis, keine Exception', function () {
    ini_set('memory_limit', '256M');

    $hinweis = Speicher::anheben('1024M', '512M');

    expect(ini_get('memory_limit'))->toBe((string) (512 * 1024 ** 2))
        ->and($hinweis)->toContain('Server-Höchstwert')->toContain('512 MB');
});

it('ohne Höchstwert (PHP < 8.5) oder darunter: wie gewünscht, kein Hinweis; nie senken; unbegrenzt bleibt unbegrenzt', function () {
    ini_set('memory_limit', '256M');
    expect(Speicher::anheben('1024M', false))->toBeNull()->and(ini_get('memory_limit'))->toBe((string) (1024 * 1024 ** 2));

    ini_set('memory_limit', '2048M');
    expect(Speicher::anheben('1024M', '512M'))->toBeNull()->and(ini_get('memory_limit'))->toBe('2048M');

    ini_set('memory_limit', '-1');
    expect(Speicher::anheben('1024M', '512M'))->toBeNull()->and(ini_get('memory_limit'))->toBe('-1');
});

it('schon am Höchstwert: bleibt, Hinweis', function () {
    ini_set('memory_limit', '512M');

    expect(Speicher::anheben('1024M', '512M'))->toContain('bleibt bei 512 MB')->and(ini_get('memory_limit'))->toBe('512M');
});

it('Bytes-Umrechnung', function () {
    expect(Speicher::bytes('512M'))->toBe(536870912)->and(Speicher::bytes('1G'))->toBe(1073741824)
        ->and(Speicher::bytes('-1'))->toBe(-1)->and(Speicher::bytes('1073741824'))->toBe(1073741824);
});

it('kein Kommando setzt memory_limit mehr direkt (alle über Speicher::anheben)', function () {
    $direkt = collect(glob(__DIR__ . '/../../src/Console/*.php'))
        ->filter(fn ($f) => preg_match("/ini_set\\(\\s*'memory_limit'/", file_get_contents($f)) === 1)->map(fn ($f) => basename($f))->values()->all();

    expect($direkt)->toBe([]);
});
