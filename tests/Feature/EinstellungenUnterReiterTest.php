<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Einkauf;
use Platform\FoodAlchemist\Livewire\Settings\Index as Einstellungen;
use Platform\FoodAlchemist\Livewire\Settings\Ki;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einstellungen E-4 (Dominique 23.10.: „da sind alle Tab-Inhalte untereinander … eher schlimmer“): je Unter-Bereich EINE Ansicht.
 * Es ist immer genau ein Unter-Reiter im DOM, der aktive steht in der URL (?bereich=…, ohne: der erste). Große Blöcke einer Sektion
 * (Ausstattung, Fixkosten, Modellstufen …) sind eigene Unter-Reiter. Der Wechsel des Unter-Reiters hält die Sperre des Reiters.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->admin = $this->makeUser($this->rootTeam, 'Admin', 'owner');
    $this->actingAs($this->admin);
});

it('genau ein Unter-Reiter im DOM: Küche zeigt erst die Kennzahlen, Ausstattung erst nach dem Wechsel', function () {
    $c = Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kueche');
    expect($c->html())->toContain('data-settings-bereich="kennzahlen"')->toContain('data-teamprofil-kennzahlen')
        ->not->toContain('data-teamprofil-ausstattung')->not->toContain('data-settings-sektion="posten"')
        ->and(preg_match('/<button[^>]*aria-current="page"[^>]*data-settings-unter-reiter-knopf="([a-z-]+)"/', $c->html(), $m))->toBe(1)
        ->and($m[1])->toBe('kennzahlen');   // genau der aktive Unter-Reiter ist markiert

    $c->call('bereichWaehlen', 'ausstattung')->assertSet('bereich', 'ausstattung');
    expect($c->html())->toContain('data-teamprofil-ausstattung')->not->toContain('data-teamprofil-kennzahlen');

    $c->call('bereichWaehlen', 'fixkosten')->assertSet('bereich', 'ausstattung');   // Unter-Reiter eines anderen Reiters: ignoriert
});

it('URL: ?bereich=<abschnitt> öffnet ihn, ?bereich=<geteilte Sektion> den ersten Abschnitt, Unbekanntes den ersten Unter-Reiter', function () {
    $html = Livewire::withQueryParams(['tab' => 'kalkulation', 'bereich' => 'fixkosten'])->test(Einstellungen::class)
        ->assertSet('tab', 'kalkulation')->assertSet('bereich', 'fixkosten')->html();
    expect($html)->toContain('data-hk-fixkosten')->not->toContain('data-hk-schema')->not->toContain('data-kalk-garverlust');

    Livewire::withQueryParams(['bereich' => 'kalkulation'])->test(Einstellungen::class)
        ->assertSet('tab', 'kalkulation')->assertSet('bereich', 'verluste')
        ->assertSeeHtml('data-kalk-garverlust')->assertDontSeeHtml('data-kalk-mwst');

    Livewire::withQueryParams(['tab' => 'kalkulation', 'bereich' => 'quatsch'])->test(Einstellungen::class)
        ->assertSet('tab', 'kalkulation')->assertSet('bereich', null)->assertSeeHtml('data-kalk-garverlust');
});

it('Suche springt auf Reiter und Unter-Reiter', function () {
    $html = Livewire::test(Einstellungen::class)->html();

    expect($html)->toContain('href="' . e(route('foodalchemist.einstellungen', ['tab' => 'kalkulation', 'bereich' => 'fixkosten'])) . '"')
        ->toContain('href="' . e(route('foodalchemist.einstellungen', ['tab' => 'kueche', 'bereich' => 'behaelter'])) . '"');
});

it('Sperre: Bearbeiten gilt je Reiter, der Wechsel des Unter-Reiters gibt nichts frei', function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $svc = app(BearbeitungssperreService::class);
    $haelt = fn (string $b) => $svc->haelt('settings.' . $b, (int) $this->rootTeam->id, (int) $this->admin->id);

    $c = Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kalkulation')->call('reiterBearbeiten')
        ->call('bereichWaehlen', 'fixkosten')->call('bereichWaehlen', 'aufschlagsklassen');
    expect($haelt('kalkulation'))->toBeTrue()->and($haelt('herstellkosten'))->toBeTrue()->and($haelt('aufschlagsklassen'))->toBeTrue();
    expect($c->html())->toContain('data-reiter-in-bearbeitung');

    $c->call('tabWaehlen', 'einkauf');   // erst der Reiterwechsel gibt frei (E-3)
    expect($haelt('kalkulation'))->toBeFalse();
});

it('Abschnitt in der Komponente: Einkauf/Lagerorte ohne Strategie-Leiste, KI/Modellstufen ohne Notschalter und Sprachbefehl', function () {
    $html = Livewire::test(Einkauf::class, ['abschnitt' => 'lagerorte'])->html();
    expect($html)->toContain('data-lagerorte')->not->toContain('data-einkauf-strategie')->not->toContain('data-bestellversand')
        ->not->toContain('data-stamm-matrix')->not->toContain('Speichert die Strategie');

    expect(Livewire::test(Einkauf::class)->html())->toContain('data-einkauf-strategie')->toContain('data-lagerorte');   // ohne Abschnitt: alles

    Livewire::test(Ki::class, ['abschnitt' => 'modellstufen'])->assertSee('Modellstufen')
        ->assertDontSee('Schaltet alle KI-Funktionen dieses Teams')->assertDontSee('Der Agent hinter dem Sprachbefehl');
});

it('Abnahme: Leiste mit Kurznamen (volle Bezeichnung im Tooltip und auf der Karte), Einzel-Abschnitt ohne doppelten Titel', function () {
    expect(array_diff(array_keys(Einstellungen::KURZ), array_keys(Einstellungen::SEKTIONEN)))->toBe([]);

    $html = Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kueche')->call('bereichWaehlen', 'rollen')->html();
    expect($html)->toContain('data-settings-unter-reiter-knopf="rollen">Küchenrollen</button>')
        ->toContain('title="Küchenrollen &amp; Stundensätze: ')
        ->and(substr($html, strpos($html, 'data-settings-kopf="rollen"'), 1500))->toContain('Küchenrollen &amp; Stundensätze');

    // Der innere Titel eines alleinstehenden Abschnitts wird per CSS ausgeblendet (gemessen im Browser, Screenshot-Abnahme 24.10.)
    expect($html)->toContain('[&_section.fa-surface:only-of-type:not(section_section_section)>header_h3]:hidden');
});
