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
        // Spec 81: Plattform-Admin-Sektionen (Regeln) fehlen für alle anderen bewusst.
        if (in_array($key, Einstellungen::NUR_PLATTFORM_ADMIN, true)) {
            expect($html)->not->toContain('data-settings-link="' . $key . '"');

            continue;
        }
        // Spec 82 §13 C1: das Mandanten-Stammblatt sehen nur Plattform-Admin und Master-Admin.
        // Neuordnung 23.10.: Plattform-Wissen (Wissens-Steuerung) sehen nur Master-Team und Plattform-Admin.
        if (in_array($key, Einstellungen::NUR_VERWALTUNG, true) || in_array($key, Einstellungen::NUR_MASTER, true)) {
            expect($html)->not->toContain('data-settings-link="' . $key . '"');

            continue;
        }
        // E-4: geteilte Sektionen stehen mit ihren Unter-Reitern in der Suche
        foreach (array_keys(Einstellungen::ABSCHNITTE[$key] ?? [$key => true]) as $link) {
            expect($html)->toContain('data-settings-link="' . $link . '"');
        }
    }

    $admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower((string) $admin->email)]]);
    $this->actingAs($admin);
    $html = Livewire::test(Einstellungen::class)->html();
    expect($html)->toContain('data-settings-link="regeln"')->toContain('data-settings-link="mandanten"')
        ->toContain('data-settings-link="wissenssteuerung"');
});

it('trägt das clientseitige Filter-Feld über der Bereiche-Liste', function () {
    // 23 Sektionen als flache Liste — statt strittiger Gruppen ein Live-Filter (Label + Hint).
    $html = Livewire::test(Einstellungen::class)->html();

    expect($html)->toContain('data-settings-filter')->toContain('x-model="q"');
});

it('Reiter folgen den Gruppen (jede Sektion in genau einer), Leiste waagrecht scrollbar, erster Reiter ist aktiv', function () {
    // Neuordnung 23.10. (Dominique: Reiter statt Seitenleiste)
    $eingeordnet = collect(Einstellungen::GRUPPEN)->pluck('sektionen')->flatten();
    expect($eingeordnet->sort()->values()->all())->toBe(collect(array_keys(Einstellungen::SEKTIONEN))->sort()->values()->all())
        ->and($eingeordnet->duplicates()->all())->toBe([]);

    $c = Livewire::test(Einstellungen::class);
    $html = $c->html();
    $reiter = [];
    preg_match_all('/data-settings-tab="([a-z-]+)"/', $html, $reiter);
    expect($reiter[1])->toBe(array_keys(Einstellungen::sichtbareGruppen()))
        ->and($html)->toContain('overflow-x-auto')->not->toContain('data-settings-gruppe=');   // keine Seitenleiste mehr
    $c->assertSet('tab', array_key_first(Einstellungen::sichtbareGruppen()));
});

it('alter Link /einstellungen/{sektion} öffnet Reiter und Unter-Reiter der Sektion; Reiterwechsel per Klick, unbekannter Reiter → erster', function () {
    Livewire::test(Einstellungen::class, ['sektion' => 'aufschlagsklassen'])
        ->assertSet('tab', 'kalkulation')->assertSet('bereich', 'aufschlagsklassen')
        ->assertSeeHtml('data-settings-sektion="aufschlagsklassen"')->assertSeeHtml('data-settings-reiter="kalkulation"')
        ->call('tabWaehlen', 'kategorien')->assertSet('tab', 'kategorien')->assertSet('bereich', null)
        ->assertSeeHtml('data-settings-sektion="taxonomie"')->assertDontSeeHtml('data-settings-sektion="aufschlagsklassen"')
        ->call('tabWaehlen', 'gibt-es-nicht')->assertSet('tab', array_key_first(Einstellungen::sichtbareGruppen()));

    // E-4: ein erweiterter Bereich ist ein eigener Unter-Reiter hinter dem Trenner, allein im DOM
    expect(Livewire::test(Einstellungen::class, ['sektion' => 'behaelter'])->html())
        ->toContain('data-settings-erweitert-trenner')->toContain('data-settings-sektion="behaelter"')
        ->not->toContain('data-settings-sektion="posten"');
});

it('E-4: Unter-Reiter Grund vor dem Trenner, Erweitert dahinter; Suche kennt Stichworte über alle Reiter', function () {
    expect(array_diff(Einstellungen::GRUND, array_keys(Einstellungen::SEKTIONEN)))->toBe([])
        ->and(array_diff(array_keys(Einstellungen::STICHWORTE), array_keys(Einstellungen::SEKTIONEN)))->toBe([])
        ->and(array_diff(array_keys(Einstellungen::ABSCHNITTE), array_keys(Einstellungen::SEKTIONEN)))->toBe([])
        ->and(array_intersect(collect(Einstellungen::ABSCHNITTE)->flatMap(fn ($a) => array_keys($a))->all(), array_keys(Einstellungen::SEKTIONEN)))->toBe([]);

    $html = Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kueche')->html();
    $trenner = strpos($html, 'data-settings-erweitert-trenner');
    expect($html)->toContain('data-settings-unter-reiter')
        ->and(strpos($html, 'data-settings-unter-reiter-knopf="ausstattung"'))->toBeLessThan($trenner)   // Grund: vor dem Trenner
        ->and(strpos($html, 'data-settings-unter-reiter-knopf="behaelter"'))->toBeGreaterThan($trenner)  // erweitert: dahinter
        ->and($html)->toContain('mwst mehrwertsteuer')                                                    // Stichwort der Kalkulation (anderer Reiter)
        ->toContain('data-settings-link="mwst-rundung"');
});

it('Plattform-Gruppe: Wissens-Steuerung nur für Master-Team und Plattform-Admin; Mandant bekommt 404', function () {
    $this->get(route('foodalchemist.einstellungen', ['sektion' => 'wissenssteuerung']))->assertNotFound();

    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    expect(Livewire::test(Einstellungen::class)->html())->toContain('data-settings-link="wissenssteuerung"')
        ->toContain('data-settings-tab="plattform"');
});

it('Mitglied ohne Admin-Recht sieht alles lesend mit Hinweis; Admin ohne Hinweis', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Mitglied', 'member'));
    expect(Livewire::test(Einstellungen::class)->html())->toContain('data-settings-nur-lesen');

    $this->actingAs($this->makeUser($this->rootTeam, 'Admin', 'owner'));
    expect(Livewire::test(Einstellungen::class)->html())->not->toContain('data-settings-nur-lesen');
});

it('markiert die aktive Sektion und zeigt ihren Kopf genau einmal', function () {
    $html = Livewire::test(Einstellungen::class, ['sektion' => 'herstellkosten'])->html();

    expect($html)->toContain('data-settings-sektion="herstellkosten"')->toContain('data-settings-kopf');

    // Der Kopf kommt aus DERSELBEN Quelle wie die Navigation — sonst driftet die Beschriftung
    // zwischen Liste und Überschrift auseinander. E-4: geteilte Sektion → Kopf des ersten Unter-Reiters.
    $meta = Einstellungen::ABSCHNITTE['herstellkosten']['zuschlaege'];
    $kopf = substr($html, strpos($html, 'data-settings-kopf="zuschlaege"'), 1500);   // E-3: Symbol im Kopf vor dem Namen
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

/**
 * Live-Befund Dominique (2026-09-18): die KI-Kachel nannte den Sprachbefehl mit keinem Wort
 * (nur „Provider · Tiering (V-01) · Nutzung · Kill-Switch (M7-08)") — Agenten-Modus/dauerhaft
 * aktiv/Vorlesen liegen zwar seit Spec 53/F Stufe 1–4 alle unter dieser Sektion, waren über die
 * Übersicht aber nicht auffindbar.
 */
it('die KI-Kachel nennt den Sprachbefehl (Agenten-Modus/dauerhaft aktiv/Vorlesen) im Hint', function () {
    expect(Einstellungen::SEKTIONEN['ki']['hint'])->toContain('Sprachbefehl');

    // E-4: der Sprachbefehl ist ein eigener Unter-Reiter der KI; die Suche findet ihn mit Agenten-Modus/dauerhaft aktiv/Vorlesen
    $html = Livewire::test(Einstellungen::class)->html();
    expect($html)->toContain('data-settings-link="sprachbefehl"')->toContain('Agenten-Modus, dauerhaft aktiv, Vorlesen');
});
