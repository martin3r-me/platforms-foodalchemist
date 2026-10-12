<?php

use Illuminate\Support\Facades\Blade;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 / Paket C — der `$wire`-Aufruf von <x-foodalchemist::ki-action>.
 *
 * Warum dieser Test existiert (demo, 2026-10-12): 30 Knöpfe übergeben einen BLANKEN
 * Methodennamen (`action="kiProduktfoto"`). Die Komponente rief dann `$wire.kiProduktfoto`
 * OHNE Klammern — das ist nur die Funktion, kein Promise. `.then` warf einen TypeError, es
 * ging keine Anfrage raus, und der Knopf endete nach 45 s in „Keine Antwort — Seite prüfen".
 * Sichtbar war das nur in der Browser-Konsole; die Livewire-Tests prüfen `data-ki-action`
 * und `wire:target`, führen aber kein JavaScript aus. Dieser Test pinnt deshalb den
 * generierten JS-Ausdruck selbst.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('ki-action: blanker Methodenname wird als Aufruf mit Klammern an $wire gegeben', function () {
    $html = Blade::render('<x-foodalchemist::ki-action action="kiProduktfoto" label="Produktfoto erzeugen" />');

    expect($html)
        ->toContain('$wire.kiProduktfoto()')
        // Marker + Loading-Ziel bleiben der blanke Name — Livewire matcht Methoden-Namen ohne Argumente
        ->toContain('data-ki-action="kiProduktfoto"')
        ->toContain('wire:target="kiProduktfoto"')
        ->not->toContain('$wire.kiProduktfoto\n')
        ->not->toContain('kiProduktfoto()()');
});

it('ki-action: Ausdruck mit Argumenten bleibt unverändert', function () {
    $html = Blade::render('<x-foodalchemist::ki-action action="neuAnreichern(12, true)" label="Neu" />');

    expect($html)
        ->toContain('$wire.neuAnreichern(12, true)')
        ->toContain('wire:target="neuAnreichern(12, true)"')
        ->not->toContain('neuAnreichern(12, true)()');
});

it('ki-action: der $wire-Aufruf hängt an einem Promise, damit synchrone Fehler im catch landen', function () {
    $html = Blade::render('<x-foodalchemist::ki-action action="kiSchritte" label="Schritte" />');

    // Promise.resolve().then(() => $wire.x()) — ein TypeError im Ausdruck wird zur Rejection
    // und zeigt seine Meldung, statt 45 s später als „Keine Antwort" zu erscheinen.
    expect($html)->toContain('Promise.resolve().then(() => $wire.kiSchritte())');
});

/**
 * Hotfix 2026-10-12 (Dominique, Screenshot GP-Modal): nach #273 stand im Klick-Handler ein JS-Kommentar mit einem
 * geraden Anführungszeichen („Keine Antwort"). Der Handler liegt IN dem HTML-Attribut @click — das " beendete es,
 * der Rest des JavaScripts erschien als Text auf der Seite, jeder KI-Knopf war tot. Die Tests oben prüften den
 * Aufruf-String, nicht ob er im Attribut ankommt.
 */
it('der ganze Klick-Handler bleibt im @click-Attribut — kein gerades Anführungszeichen bricht es auf', function () {
    $html = Blade::render('<x-foodalchemist::ki-action action="allesAnreichern" label="Alles anreichern" />');

    expect(preg_match('/@click="([^"]*)"/', $html, $m))->toBe(1)
        ->and($m[1])->toContain('$wire.allesAnreichern()')
        ->and($m[1])->toContain('.catch((e) =>');                // Ende des Handlers liegt noch im Attribut
});
