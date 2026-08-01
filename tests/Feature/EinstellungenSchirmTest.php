<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Index as Einstellungen;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 28 / E16: Einstellungs-Schirm.
 *
 * Die Sektions-Navigation lag als w-72-Karte IM Inhaltsbereich und nahm der Sektion 288px weg —
 * jetzt steht sie in der Plattform-Sidebar wie in allen anderen Schirmen. Und der Sektions-Kopf
 * stand doppelt: einmal hier, einmal in der Sektion selbst.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('führt jede registrierte Sektion in der Navigation', function () {
    $html = Livewire::test(Einstellungen::class)->html();

    expect($html)->toContain('data-settings-nav');
    foreach (array_keys(Einstellungen::SEKTIONEN) as $key) {
        expect($html)->toContain('data-settings-link="' . $key . '"');
    }
});

it('markiert die aktive Sektion und zeigt ihren Kopf genau einmal', function () {
    $html = Livewire::test(Einstellungen::class, ['sektion' => 'herstellkosten'])->html();

    expect($html)->toContain('data-settings-sektion="herstellkosten"')->toContain('data-settings-kopf');

    // Der Kopf kommt aus DERSELBEN Quelle wie die Navigation — sonst driftet die Beschriftung
    // zwischen Liste und Überschrift auseinander.
    $meta = Einstellungen::SEKTIONEN['herstellkosten'];
    $kopf = substr($html, strpos($html, 'data-settings-kopf'), 400);
    // e() nicht vergessen: „Herstellkosten & Zuschläge" steht als `&amp;` in der Seite.
    expect($kopf)->toContain(e($meta['label']));
});

it('eine unbekannte Sektion endet in 404, nicht in einem leeren Schirm', function () {
    // `konzept-taxonomie` ist genau dieser Fall: das Blade liegt noch da, die Sektion ist aber
    // aus der Navigation genommen — der Aufruf muss sauber 404en statt halb zu rendern.
    expect(Einstellungen::SEKTIONEN)->not->toHaveKey('konzept-taxonomie');

    // Über die Route, nicht über Livewire::test — der Test-Harness fängt das abort() aus mount()
    // ab, die echte Anfrage nicht.
    $this->get(route('foodalchemist.einstellungen', ['sektion' => 'konzept-taxonomie']))->assertNotFound();
});

it('die Formular-Sektionen tragen die Speicher-Leiste oben und keinen Knopf mehr unten', function (string $sektion) {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/livewire/settings/' . $sektion . '.blade.php');

    expect($blade)->toContain('<x-foodalchemist::save-bar');
    // Der alte Knopf am Dateiende ist die Stelle, an der die Leiste sonst doppelt steht.
    expect($blade)->not->toContain('wire:click="speichern"');

    // Die Leiste steht VOR dem Inhalt — sonst klebt sie zwar, aber unter der halben Sektion.
    $leiste = strpos($blade, '<x-foodalchemist::save-bar');
    $ersteKarte = strpos($blade, '{{ $card }}');
    expect($ersteKarte === false || $leiste < $ersteKarte)->toBeTrue();
})->with(['einkauf', 'kalkulation', 'herstellkosten', 'kueche']);

it('die Einstellungs-Views tragen keine Emoji-Marker mehr in Bedienelementen', function () {
    // Statisch, weil genau das beim Icon-Durchgang liegen blieb: Pfeile, Blitz, Kreuze als
    // Button-Beschriftung. Rechenzeichen (× ÷ →) im Fließtext sind Typografie und bleiben.
    $verboten = '/>[^<>]*[\x{2713}-\x{2718}\x{26A0}\x{26A1}\x{21BB}\x{2191}\x{2193}\x{23FB}-\x{23FE}\x{1F300}-\x{1FAFF}][^<>]*<\/button>/u';

    $treffer = [];
    foreach (glob(__DIR__ . '/../../resources/views/livewire/settings/*.blade.php') as $datei) {
        if (preg_match($verboten, file_get_contents($datei))) {
            $treffer[] = basename($datei);
        }
    }

    expect($treffer)->toBe([]);
});
