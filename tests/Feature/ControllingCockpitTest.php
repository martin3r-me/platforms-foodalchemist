<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Controlling\Cockpit;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 32 · C0/C1 — Controlling-Zentrum: die Naht.
 *
 * Geprüft wird, was bei einer Umhängung schiefgehen kann: verlorene Deep-Links, ein
 * KPI-Kopf mit anderen Zahlen als die Fläche daneben, und Tabs, die den falschen Inhalt
 * rendern (Server-Modus — der falsche Tab bedeutet hier eine falsche Query, nicht nur
 * ein verstecktes Panel).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('rendert das Cockpit und startet auf der Lage', function () {
    Livewire::test(Cockpit::class)
        ->assertOk()
        ->assertSet('tab', 'lage')
        ->assertSeeHtml('data-ctrl-lagebild');
});

it('schaltet nur auf bekannte Tabs', function () {
    $lw = Livewire::test(Cockpit::class)->call('setTab', 'wareneinsatz')->assertSet('tab', 'wareneinsatz');

    // Ein unbekannter Schlüssel darf den Zustand NICHT verändern — sonst rendert das Modal
    // eine leere Fläche und sieht aus wie ein Bug im Panel.
    $lw->call('setTab', 'gibtsnicht')->assertSet('tab', 'wareneinsatz');
});

it('nimmt eine Tab-Vorwahl aus der URL an und fällt bei Unsinn auf die Lage zurück', function () {
    Livewire::withUrlParams(['tab' => 'preise'])->test(Cockpit::class)->assertSet('tab', 'preise');
    Livewire::withUrlParams(['tab' => 'quatsch'])->test(Cockpit::class)->assertSet('tab', 'lage');
});

it('öffnet die Werkbank beim Aufruf und springt per Kachel in den Ziel-Tab', function () {
    Livewire::test(Cockpit::class)
        ->assertDispatched('modal.open')
        ->call('oeffnen', 'signale')
        ->assertSet('tab', 'signale')
        ->assertDispatched('modal.open');
});

it('rechnet den Break-even im KPI-Kopf mit derselben Formel wie der Kennzahlen-Tab', function () {
    // Fixkosten 3.000 €/Monat + Ziel-Wareneinsatz 30 % → Break-even = 3000 ÷ 0,7.
    // Exakt der Fall aus KalkulationUiTest — zwei Break-even-Zahlen im selben Modul wären
    // ein Widerspruch, den niemand auflösen kann.
    app(FixkostenService::class)->create($this->rootTeam, ['label' => 'Miete', 'amount' => 3000, 'block_key' => 'gemeinkosten']);
    app(TeamSettingsService::class)->update($this->rootTeam, ['target_food_cost_pct' => 30]);

    Livewire::test(Cockpit::class)
        ->assertViewHas('kpi', fn ($k) => abs($k['break_even'] - 3000 / 0.7) < 0.5
            && abs($k['ziel_we_pct'] - 30.0) < 0.01);
});

it('zählt im KPI-Kopf nur die geldrelevanten Signaltypen', function () {
    $mach = function (string $typ, string $key) {
        FoodAlchemistSignal::create([
            'team_id' => $this->rootTeam->id, 'type' => $typ, 'severity' => 'warnung',
            'status' => 'offen', 'title' => $typ, 'dedup_key' => $key, 'source' => 'test',
        ]);
    };

    $mach('marge_unter_ziel', 'a');
    $mach('preis_anomalie', 'b');
    $mach('rezept_ohne_zubereitung', 'c');   // Datenqualität — gehört auf die Signale-Seite
    $mach('foodbook_kapitel_leer', 'd');     // dito

    Livewire::test(Cockpit::class)
        ->assertViewHas('kpi', fn ($k) => $k['geld_signale'] === 2
            && $k['geld_signale_je_typ']['marge_unter_ziel'] === 1
            && $k['geld_signale_je_typ']['preis_anomalie'] === 1);
});

it('holt Benchmark und Verlauf nur im Lage-Tab', function () {
    // Beide kosten einen Lauf je Peer-Team bzw. über die Zeitreihe. Liefen sie in jedem Tab,
    // zahlte jeder Klick im Preisvergleich den Peer-Benchmark mit.
    Livewire::test(Cockpit::class)->assertViewHas('benchmark', fn ($b) => $b !== null);

    Livewire::test(Cockpit::class)
        ->call('setTab', 'preise')
        ->assertViewHas('benchmark', null)
        ->assertViewHas('verlauf', fn ($v) => $v['metriken'] === []);
});

it('leitet die abgelösten Einkaufs- und Kalkulations-Routen mitsamt Deep-Link weiter', function () {
    // Die Panels tragen unverändert `q`/`wg`/`sup`/`rv` — ein alter Deep-Link muss sein Ziel
    // behalten, sonst ist die Umhängung ein stiller Datenverlust für jeden gesetzten Link.
    $this->get(route('foodalchemist.einkauf.index', ['q' => 'Lachs', 'rv' => 1]))
        ->assertRedirect(route('foodalchemist.controlling.index', ['tab' => 'preise', 'q' => 'Lachs', 'rv' => 1]));

    $this->get(route('foodalchemist.einkauf.optimierung'))
        ->assertRedirect(route('foodalchemist.controlling.index', ['tab' => 'wareneinsatz']));

    $this->get(route('foodalchemist.kalkulation.index'))
        ->assertRedirect(route('foodalchemist.controlling.index', ['tab' => 'simulation']));

    // Präzedenz-Kette: /kalkulator zeigte schon vorher auf /kalkulation und darf nicht ins Leere laufen.
    $this->get(route('foodalchemist.kalkulator.index'))->assertRedirect();
});

it('bringt Lagebild und Werkbank in einer Fläche', function () {
    // Bewusst KEIN `$this->get(route(...))`: ein Full-Page-Aufruf rendert das Core-Layout, und
    // das greift auf `user_ui_preferences` zu — eine Tabelle, die die Modul-Test-DB nicht
    // migriert (der Harness ist layout-blind). Geprüft wird deshalb das Komponenten-Markup;
    // dass die Seite im Layout steht, ist Sache der Browser-Abnahme.
    Livewire::test(Cockpit::class)
        ->assertOk()
        ->assertSeeHtml('data-ctrl-lagebild')
        ->assertSeeHtml('data-modal="controlling-editor"')
        ->assertSeeHtml('data-ctrl-tabs');
});

it('rendert je Tab genau das zugehörige Panel', function () {
    Livewire::test(Cockpit::class)->call('setTab', 'preise')
        ->assertSeeHtml('data-ctrl-preisvergleich')->assertDontSeeHtml('data-ctrl-wareneinsatz');

    Livewire::test(Cockpit::class)->call('setTab', 'wareneinsatz')
        ->assertSeeHtml('data-ctrl-wareneinsatz')->assertDontSeeHtml('data-ctrl-preisvergleich');

    Livewire::test(Cockpit::class)->call('setTab', 'kennzahlen')
        ->assertSeeHtml('data-ctrl-kennzahlen');

    Livewire::test(Cockpit::class)->call('setTab', 'signale')
        ->assertSeeHtml('data-ctrl-geld-signale');

    // Die Erlösseite ist bis C3 ein ehrlicher Leerzustand — kein Null-Kachel-Theater.
    Livewire::test(Cockpit::class)->call('setTab', 'erfolg')
        ->assertSeeHtml('data-ctrl-erfolg-leer');
});
