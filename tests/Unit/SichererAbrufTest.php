<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Support\SichererAbrufFehler;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * SSRF-Schutz für serverseitig geladene URLs (Fix vor Spec 93 A): interne Ziele werden vor jedem Abruf abgelehnt,
 * auch über DNS-Namen und Weiterleitungen. Öffentliche Adressen als IP-Literal, damit der Test kein DNS braucht.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    // Fester Auflöser: kein echtes DNS im Test
    $this->abruf = new SichererAbruf(fn (string $host) => match ($host) {
        'localhost' => ['127.0.0.1', '::1'],
        'intern.beispiel.test' => ['10.1.2.3'],
        'gemischt.beispiel.test' => ['93.184.216.34', '192.168.0.10'],
        'oeffentlich.beispiel.test' => ['93.184.216.34'],
        default => [],
    });
});

it('lehnt interne Ziele ab, bevor eine Anfrage rausgeht', function (string $url) {
    Http::fake();

    expect(fn () => $this->abruf->laden($url, 1024))->toThrow(SichererAbrufFehler::class);
    Http::assertNothingSent();
})->with([
    'localhost' => 'http://localhost/bild.png',
    '127.0.0.1' => 'http://127.0.0.1/bild.png',
    '127.x anderer Port' => 'http://127.8.9.1:8080/',
    '10.x' => 'http://10.0.0.5/',
    '172.16.x' => 'http://172.20.1.1/',
    '192.168.x' => 'https://192.168.1.1/',
    '169.254.x (Metadaten)' => 'http://169.254.169.254/latest/meta-data/',
    '0.0.0.0' => 'http://0.0.0.0/',
    'CGNAT 100.64.x' => 'http://100.64.0.1/',
    '::1' => 'http://[::1]/',
    'IPv6 ULA' => 'http://[fd00::1]/',
    'IPv6 Link-Local' => 'http://[fe80::1]/',
    'IPv4-mapped IPv6' => 'http://[::ffff:127.0.0.1]/',
    'NAT64 auf privat' => 'http://[64:ff9b::a00:1]/',
    '6to4 auf privat' => 'http://[2002:a00:1::]/',
    'DNS-Name auf 10.x' => 'http://intern.beispiel.test/',
    'DNS-Name mit einer privaten Adresse unter mehreren' => 'http://gemischt.beispiel.test/',
    'nicht auflösbar' => 'http://gibtsnicht.beispiel.test/',
    'falsches Schema' => 'file:///etc/passwd',
    'gopher' => 'gopher://93.184.216.34/',
    'Zugangsdaten in der URL' => 'http://nutzer:pw@93.184.216.34/',
]);

it('lehnt eine Weiterleitung auf eine private Adresse ab', function (string $ziel) {
    Http::fake([
        'https://93.184.216.34/*' => Http::response('', 302, ['Location' => $ziel]),
        '*' => Http::response('geheim', 200),
    ]);

    expect(fn () => $this->abruf->laden('https://93.184.216.34/bild.png', 1024))
        ->toThrow(SichererAbrufFehler::class, 'interne Netz');
    Http::assertSentCount(1);
})->with([
    'http://127.0.0.1/admin',
    'http://169.254.169.254/latest/meta-data/',
    'http://intern.beispiel.test/',
    'http://[::1]/',
]);

it('lädt öffentliche Adressen, folgt erlaubten Weiterleitungen und schickt den eigenen User-Agent', function () {
    Http::fake([
        'https://93.184.216.34/alt' => Http::response('', 301, ['Location' => '/neu']),
        'https://93.184.216.34/neu' => Http::response('inhalt', 200, ['Content-Type' => 'image/png']),
        'http://oeffentlich.beispiel.test/*' => Http::response('per dns', 200),
    ]);

    $a = $this->abruf->laden('https://93.184.216.34/alt', 1024);
    expect($a->body())->toBe('inhalt')->and($a->header('Content-Type'))->toBe('image/png');
    expect($this->abruf->laden('http://oeffentlich.beispiel.test/x', 1024)->body())->toBe('per dns');
    Http::assertSent(fn (Request $r) => $r->header('User-Agent')[0] === SichererAbruf::USER_AGENT);
});

it('bricht nach zu vielen Weiterleitungen und bei zu großem Inhalt ab', function () {
    Http::fake([
        'https://93.184.216.34/schleife' => Http::response('', 302, ['Location' => '/schleife']),
        'https://93.184.216.34/gross' => Http::response(str_repeat('x', 2048), 200),
    ]);

    expect(fn () => $this->abruf->laden('https://93.184.216.34/schleife', 1024))->toThrow(SichererAbrufFehler::class, 'Weiterleitungen');
    expect(fn () => $this->abruf->laden('https://93.184.216.34/gross', 1024))->toThrow(SichererAbrufFehler::class, 'größer');
});

it('adresseErlaubt: öffentliche Adressen ja, interne nein', function () {
    foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111', '2a00:1450:4001:80b::200e'] as $ok) {
        expect(SichererAbruf::adresseErlaubt($ok))->toBeTrue($ok);
    }
    foreach (['127.0.0.1', '10.255.255.255', '172.31.0.1', '192.168.255.1', '169.254.0.1', '100.127.0.1', '224.0.0.1',
        '255.255.255.255', '::', '::1', 'fc00::1', 'fe80::abcd', 'ff02::1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', 'kein-ip'] as $nein) {
        expect(SichererAbruf::adresseErlaubt($nein))->toBeFalse($nein);
    }
});
