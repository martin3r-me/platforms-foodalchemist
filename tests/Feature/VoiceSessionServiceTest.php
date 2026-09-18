<?php

use Illuminate\Support\Facades\Cache;
use Platform\FoodAlchemist\Services\VoiceSessionService;
use Platform\FoodAlchemist\Support\VoiceReferenzResolver;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 53 / Paket F (4): Gesprächsgedächtnis — reine Logik-Bausteine (Cache-Struktur,
 * Kappung, Referenz-Erkennung), ohne Livewire/Team-Kontext. Die Verdrahtung ins Modal
 * (Referenz führt zur Ausführung, Sitzung überlebt eine Seiten-Navigation) steht in
 * VoiceInterfaceTest.php.
 */
it('lade() liefert eine leere Struktur, wenn nichts gecacht ist', function () {
    $svc = new VoiceSessionService();
    $sitzung = $svc->lade(1, 1, 'abc');

    expect($sitzung)->toBe(['zuege' => [], 'offene_vorschlaege' => [], 'geoeffnetes_objekt' => null]);
});

it('speichere()/lade() Roundtrip — TTL wird gesetzt (Cache::put, nicht forever)', function () {
    Cache::spy();
    $svc = new VoiceSessionService();
    $sitzung = $svc->zugHinzufuegen($svc->leer(), 'user', 'Suche BBQ Sauce');

    $svc->speichere(1, 2, 'sess-a', $sitzung);

    Cache::shouldHaveReceived('put')->once()->withArgs(function ($key, $value, $ttl) {
        return $key === 'voice_session:1:2:sess-a' && $value['zuege'][0]['text'] === 'Suche BBQ Sauce';
    });
});

it('vergessen() entfernt den Cache-Eintrag komplett', function () {
    $svc = new VoiceSessionService();
    $svc->speichere(1, 1, 'sess-b', $svc->zugHinzufuegen($svc->leer(), 'user', 'x'));
    expect($svc->lade(1, 1, 'sess-b')['zuege'])->not->toBe([]);

    $svc->vergessen(1, 1, 'sess-b');

    expect($svc->lade(1, 1, 'sess-b'))->toBe($svc->leer());
});

it('zugHinzufuegen() kappt auf die letzten 6 Züge und ignoriert leeren Text', function () {
    $svc = new VoiceSessionService();
    $sitzung = $svc->leer();
    foreach (range(1, 8) as $n) {
        $sitzung = $svc->zugHinzufuegen($sitzung, 'user', "Zug {$n}");
    }
    $sitzung = $svc->zugHinzufuegen($sitzung, 'user', '   ');   // leer/whitespace — wird ignoriert

    expect($sitzung['zuege'])->toHaveCount(6)
        ->and($sitzung['zuege'][0]['text'])->toBe('Zug 3')       // die ersten beiden sind rausgefallen
        ->and($sitzung['zuege'][5]['text'])->toBe('Zug 8');
});

it('zugHinzufuegen() kürzt sehr lange Züge (kein Roh-Dump im Prompt)', function () {
    $svc = new VoiceSessionService();
    $sitzung = $svc->zugHinzufuegen($svc->leer(), 'agent', str_repeat('a', 1000));

    expect(mb_strlen($sitzung['zuege'][0]['text']))->toBeLessThan(410);
});

it('promptKontext() ist null ohne Züge/Objekt, sonst ein kurzer Verlaufstext', function () {
    $svc = new VoiceSessionService();
    expect($svc->promptKontext($svc->leer()))->toBeNull();

    $sitzung = $svc->zugHinzufuegen($svc->leer(), 'user', 'Suche BBQ Sauce');
    $sitzung = $svc->zugHinzufuegen($sitzung, 'agent', '1 Treffer: Sauce: BBQ.');
    $sitzung['geoeffnetes_objekt'] = ['type' => 'recipe', 'id' => 42, 'name' => 'Sauce: BBQ'];

    $kontext = $svc->promptKontext($sitzung);
    expect($kontext)->toContain('Nutzer: Suche BBQ Sauce')
        ->and($kontext)->toContain('Agent: 1 Treffer: Sauce: BBQ.')
        ->and($kontext)->toContain('Zuletzt geöffnet: recipe #42 „Sauce: BBQ"');
});

/*
 * VoiceReferenzResolver — Bestätigung/Referenz OHNE Tool-Loop.
 */

it('erkennt reine Zustimmung NUR bei genau einem offenen Vorschlag', function () {
    expect(VoiceReferenzResolver::erkenne('ja', 1))->toBe(0)
        ->and(VoiceReferenzResolver::erkenne('genau', 1))->toBe(0)
        ->and(VoiceReferenzResolver::erkenne('mach das', 1))->toBe(0)
        ->and(VoiceReferenzResolver::erkenne('ja', 2))->toBeNull()      // mehrdeutig bei mehreren
        ->and(VoiceReferenzResolver::erkenne('ja', 0))->toBeNull();     // nichts offen
});

it('erkennt Ordinalwörter unabhängig von Groß-/Kleinschreibung und Satzzeichen', function () {
    expect(VoiceReferenzResolver::erkenne('Das zweite', 3))->toBe(1)
        ->and(VoiceReferenzResolver::erkenne('die erste bitte', 3))->toBe(0)
        ->and(VoiceReferenzResolver::erkenne('das dritte!', 3))->toBe(2)
        ->and(VoiceReferenzResolver::erkenne('das fünfte', 3))->toBeNull();  // Position > Anzahl offen
});

it('erkennt Ziffern-Referenzen ("nummer 2", "vorschlag 2", bloß "2")', function () {
    expect(VoiceReferenzResolver::erkenne('nummer 2', 3))->toBe(1)
        ->and(VoiceReferenzResolver::erkenne('vorschlag 2', 3))->toBe(1)
        ->and(VoiceReferenzResolver::erkenne('2', 3))->toBe(1)
        ->and(VoiceReferenzResolver::erkenne('2.', 3))->toBe(1)
        ->and(VoiceReferenzResolver::erkenne('nummer 9', 3))->toBeNull();   // ausserhalb des Bereichs
});

it('ein normaler Befehl ist KEINE Referenz — auch wenn zufällig eine Zahl drinsteht', function () {
    expect(VoiceReferenzResolver::erkenne('Suche BBQ Sauce', 2))->toBeNull()
        ->and(VoiceReferenzResolver::erkenne('füge 200 Gramm Butter hinzu', 2))->toBeNull()
        ->and(VoiceReferenzResolver::erkenne('lösche das zweite Rezept aus der ganzen Speisekarte', 2))->toBeNull();
});
