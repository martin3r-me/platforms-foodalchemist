<?php

use Platform\FoodAlchemist\Support\Speicher;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * io 19.10.: `foodalchemist:recompute` brach sofort ab — PHP 8.5 wirft bei ini_set('memory_limit') über `max_memory_limit` (io CLI 512 MB).
 * Speicher::anheben hebt nur an, kappt am Server-Höchstwert und liefert dann einen Hinweis statt abzubrechen. Lokal (PHP < 8.5) gibt es
 * `max_memory_limit` nicht — der Höchstwert wird im Test ausdrücklich übergeben.
 */
// Grenzen RELATIV zum aktuellen Verbrauch: im vollen Testlauf belegt der Prozess schon > 450 MB, ein festes ini_set('256M')
// lehnt PHP dann ab (Befund Vollsuite 23.10.). $b = erste ganze MB-Zahl 64 MB über dem Verbrauch.
beforeEach(function () {
    $this->vorher = ini_get('memory_limit');
    $this->b = (int) ceil(memory_get_usage(true) / 1024 ** 2) + 64;
    $this->mb = fn (int $n) => (string) ($n * 1024 ** 2);
});
afterEach(fn () => @ini_set('memory_limit', $this->vorher));

it('über dem Server-Höchstwert: auf den Höchstwert gekappt, Hinweis, keine Exception', function () {
    ini_set('memory_limit', $this->b . 'M');

    $hinweis = Speicher::anheben(($this->b + 768) . 'M', ($this->b + 256) . 'M');

    expect(ini_get('memory_limit'))->toBe(($this->mb)($this->b + 256))
        ->and($hinweis)->toContain('Server-Höchstwert')->toContain(($this->b + 256) . ' MB');
});

it('ohne Höchstwert (PHP < 8.5) oder darunter: wie gewünscht, kein Hinweis; nie senken; unbegrenzt bleibt unbegrenzt', function () {
    ini_set('memory_limit', $this->b . 'M');
    expect(Speicher::anheben(($this->b + 768) . 'M', false))->toBeNull()->and(ini_get('memory_limit'))->toBe(($this->mb)($this->b + 768));

    ini_set('memory_limit', ($this->b + 2048) . 'M');
    expect(Speicher::anheben(($this->b + 768) . 'M', ($this->b + 256) . 'M'))->toBeNull()->and(ini_get('memory_limit'))->toBe(($this->b + 2048) . 'M');

    ini_set('memory_limit', '-1');
    expect(Speicher::anheben(($this->b + 768) . 'M', ($this->b + 256) . 'M'))->toBeNull()->and(ini_get('memory_limit'))->toBe('-1');
});

it('schon am Höchstwert: bleibt, Hinweis', function () {
    ini_set('memory_limit', ($this->b + 256) . 'M');

    expect(Speicher::anheben(($this->b + 768) . 'M', ($this->b + 256) . 'M'))->toContain('bleibt bei ' . ($this->b + 256) . ' MB')
        ->and(ini_get('memory_limit'))->toBe(($this->b + 256) . 'M');
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
