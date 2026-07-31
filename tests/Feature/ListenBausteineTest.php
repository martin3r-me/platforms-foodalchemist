<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 28 / Weg A: die geteilten Listen-Bausteine `filter-row`, `filter-ast`, `table-row`.
 *
 * Warum sie existieren: der „aktiv"-Zustand stand 30× handgeschrieben in 18 Dateien. Jetzt
 * entscheidet eine Datei, wie aktiv aussieht — für 12 Filter-Sidebars und 7 Tabellen.
 *
 * Der Test hält vor allem das KONTRAST-MODELL fest, weil genau das der Grund für den Umbau war:
 * Balken = offener Zweig, Füllung = Auswahl. Wären beide gleichzeitig gefüllt, sähe man nicht,
 * auf welcher Ebene man steht — der Zustand vor Spec 28.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('filter-row: Eltern-Auswahl trägt Balken UND Füllung, solange kein Kind gewählt ist', function () {
    $html = Blade::render('<x-foodalchemist::filter-row :active="true" :child-active="false" :count="7">01 Gemuese</x-foodalchemist::filter-row>');

    expect($html)->toContain('border-violet-500')
        ->toContain('from-violet-500/10')          // Füllung: dieser Knoten IST die Auswahl
        ->toContain('font-medium')
        ->toContain('tabular-nums')
        ->toContain('01 Gemuese');
});

it('filter-row: sobald ein Kind gewählt ist, tritt das Elternteil auf den Balken zurück', function () {
    $html = Blade::render('<x-foodalchemist::filter-row :active="true" :child-active="true" :count="7">01 Gemuese</x-foodalchemist::filter-row>');

    // Das ist der Kern: Balken ja, Füllung NEIN — sonst wären zwei Ebenen gleichzeitig aktiv
    expect($html)->toContain('border-violet-500');
    expect($html)->not->toContain('from-violet-500/10');
});

it('filter-row: Kind-Zeilen tragen die Füllung, aber keinen eigenen Balken', function () {
    $aktiv = Blade::render('<x-foodalchemist::filter-row level="child" :active="true" :count="4">01.1 Fruchtgemuese</x-foodalchemist::filter-row>');
    expect($aktiv)->toContain('bg-violet-500/10')->toContain('font-medium');
    // Die Ebene ist durch die Führungslinie des Astes verankert, nicht durch einen Balken
    expect($aktiv)->not->toContain('border-l-2');

    $inaktiv = Blade::render('<x-foodalchemist::filter-row level="child" :active="false" :count="4">01.2 Wurzel</x-foodalchemist::filter-row>');
    expect($inaktiv)->toContain('text-gray-700');       // 11px brauchen mehr Kontrast als gray-600
});

it('filter-row: inaktive Eltern halten die Balkenbreite, damit nichts springt', function () {
    $html = Blade::render('<x-foodalchemist::filter-row :active="false" :count="3">02 Obst</x-foodalchemist::filter-row>');

    expect($html)->toContain('border-l-2')->toContain('border-transparent');
    expect($html)->not->toContain('border-violet-500');
});

it('filter-row: ein Zähler von 0 wird gedämpft, bleibt aber klickbar', function () {
    $leer = Blade::render('<x-foodalchemist::filter-row :active="false" :count="0">03 Kraeuter</x-foodalchemist::filter-row>');
    $voll = Blade::render('<x-foodalchemist::filter-row :active="false" :count="12">03 Kraeuter</x-foodalchemist::filter-row>');

    expect($leer)->toContain('text-gray-400');
    expect($voll)->toContain('text-gray-500');
    expect($leer)->toContain('<button');            // nicht disabled — Filtern auf leer ist erlaubt
});

it('filter-row + table-row reichen wire:click und data-Marker durch', function () {
    $fr = Blade::render('<x-foodalchemist::filter-row wire:click="waehleWg(\'01\')" data-test-marker :count="1">X</x-foodalchemist::filter-row>');
    expect($fr)->toContain('wire:click="waehleWg(\'01\')"')->toContain('data-test-marker');

    $tr = Blade::render('<x-foodalchemist::table-row wire:key="gp-5" data-gp-zeile="5" :active="false"><td>A</td></x-foodalchemist::table-row>');
    expect($tr)->toContain('wire:key="gp-5"')->toContain('data-gp-zeile="5"');
});

it('table-row: Auswahl = Füllung + Balken, sonst transparenter Balken gleicher Breite', function () {
    $aktiv = Blade::render('<x-foodalchemist::table-row :active="true"><td>A</td></x-foodalchemist::table-row>');
    $ruhig = Blade::render('<x-foodalchemist::table-row :active="false"><td>A</td></x-foodalchemist::table-row>');

    expect($aktiv)->toContain('from-violet-500/10')->toContain('border-violet-500');
    expect($ruhig)->toContain('border-transparent')->not->toContain('border-violet-500');

    // Die Basis-Zeilenoptik ($tr) bleibt erhalten — Trennlinie + Hover
    foreach ([$aktiv, $ruhig] as $h) {
        expect($h)->toContain('border-t')->toContain('cursor-pointer');
    }
});

it('filter-ast zeichnet die Führungslinie der Kind-Ebene', function () {
    $html = Blade::render('<x-foodalchemist::filter-ast data-sub-liste>x</x-foodalchemist::filter-ast>');
    expect($html)->toContain('border-l')->toContain('border-black/10')->toContain('data-sub-liste');
});

it('der GP-Browser behält nach dem Umbau alle Marker', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet: frisch');

    $html = Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\Browser::class)->html();

    // Diese Marker standen vor dem Umbau literal in der Datei und kommen jetzt aus den
    // Bausteinen bzw. den durchgereichten Attributen — grep über die Datei findet sie nicht mehr.
    foreach (['data-wg-liste', 'data-gp-zeile', 'data-fa-table-row'] as $marker) {
        expect($html)->toContain($marker);
    }
    expect($html)->toContain($gp->name);

    // `data-fa-filter-row` ist hier NICHT prüfbar: das Fixture seedet kein Warengruppen-
    // Vokabular, der @foreach über den Baum läuft also null Mal. Der Baustein selbst ist
    // oben per Blade::render abgedeckt — hier wäre die Zusicherung eine Fixture-Aussage,
    // keine Code-Aussage.
});
