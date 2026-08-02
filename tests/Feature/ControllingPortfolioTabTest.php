<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Controlling\Cockpit;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Portfolio;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 33 · P4 — die Mehrbetriebs-Sicht im Controlling.
 *
 * Eine Matrix, zwei Brillen, ein Stichtag-Regler. Geprüft wird vor allem, dass die Fläche das
 * zeigt, wofür sie gebaut ist: nicht die Liste, sondern die Lücken, die Parallelläufe und die
 * Ausgaben, die in keine Brille passen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $this->nord = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord']);
    $this->sued = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Bistro Süd']);
});

it('hängt als Tab im Controlling-Editor', function () {
    Livewire::test(Cockpit::class)->call('setTab', 'portfolio')
        ->assertSet('tab', 'portfolio')
        ->assertSeeHtml('data-ctrl-portfolio');
});

it('holt Benchmark und Verlauf nur in ihren eigenen Tabs', function () {
    // Spec 33 P7: der Verlauf ist aus der Lage heraus — sie war überladen. Und beide sind
    // teuer, also darf keiner im falschen Tab mitlaufen.
    Livewire::test(Cockpit::class)
        ->assertViewHas('benchmark', fn ($b) => $b !== null)
        ->assertViewHas('verlauf', fn ($v) => $v['metriken'] === []);

    // Ohne Detektor-Lauf gibt es keine Messreihe — die Fläche sagt das, statt eine leere
    // Tabelle zu zeigen. Geprüft wird deshalb der Abruf, nicht ein datenabhängiger Marker.
    Livewire::test(Cockpit::class)->call('setTab', 'verlauf')
        ->assertViewHas('benchmark', null)
        ->assertSee('Signal-Verlauf')
        ->assertSee('Noch keine Messreihe');
});

it('zeigt je Betrieb, was dort läuft', function () {
    app(SpeisekarteService::class)->create($this->rootTeam, [
        'name' => 'Nordkarte', 'status' => 'aktiv', 'outlet_id' => $this->nord->id,
    ]);

    Livewire::test(Portfolio::class)
        ->assertSeeHtml('data-ctrl-portfolio-matrix')
        ->assertSee('Kantine Nord')->assertSee('Nordkarte')
        // Ein Betrieb ohne laufende Ausgabe fehlt NICHT — die leere Zeile ist die Aussage.
        ->assertSee('Bistro Süd');
});

it('wechselt mit der Brille die Zeilenachse, nicht die Fläche', function () {
    app(SpeisekarteService::class)->create($this->rootTeam, [
        'name' => 'Kundenkarte', 'status' => 'aktiv', 'customer' => 'Klinikum West',
    ]);

    // Geprüft wird die MATRIX, nicht die Seite: die Gesamtliste unten zeigt Betrieb und Kunde
    // ohnehin nebeneinander und ist brillen-unabhängig — das ist gewollt.
    $lw = Livewire::test(Portfolio::class);

    // In der Betriebsbrille hat die Karte keine Matrix-Zeile (sie hängt an keinem Standort).
    $lw->assertViewHas('matrix', fn ($m) => collect($m)->pluck('label')->doesntContain('Klinikum West'));

    // In der Kundenbrille schon.
    $lw->call('brilleSetzen', 'kunde')
        ->assertSet('brille', 'kunde')
        ->assertViewHas('matrix', fn ($m) => collect($m)->pluck('label')->contains('Klinikum West'))
        ->assertSee('Kundenkarte');
});

it('nimmt keine unbekannte Brille an', function () {
    Livewire::test(Portfolio::class)->call('brilleSetzen', 'quatsch')->assertSet('brille', 'betrieb');
});

it('rechnet zum gewählten Stichtag, nicht nur für heute', function () {
    app(SpeisekarteService::class)->create($this->rootTeam, [
        'name' => 'Sommerkarte', 'status' => 'aktiv', 'outlet_id' => $this->nord->id,
        'gueltig_von' => '2026-06-01', 'gueltig_bis' => '2026-08-31',
    ]);

    Livewire::test(Portfolio::class)->set('stichtag', '2026-07-15')->assertSee('Sommerkarte');

    // Außerhalb des Fensters ist die Zelle leer — der Regler beantwortet auch die Planungsfrage.
    Livewire::test(Portfolio::class)->set('stichtag', '2026-10-01')
        ->assertSee('Kantine Nord')
        // Die Zeile bleibt, die Zelle ist leer — genau das ist die Lücken-Aussage.
        ->assertViewHas('matrix', fn ($m) => ($m[$this->nord->id]['zellen'] ?? []) === []);
});

it('weist Parallelläufe aus', function () {
    foreach (['Sommerkarte', 'Sonderkarte'] as $n) {
        app(SpeisekarteService::class)->create($this->rootTeam, [
            'name' => $n, 'status' => 'aktiv', 'outlet_id' => $this->nord->id,
        ]);
    }

    Livewire::test(Portfolio::class)
        ->assertSeeHtml('data-ctrl-portfolio-konflikte')
        ->assertSee('2 parallel');
});

it('führt Ausgaben ohne jede Zuordnung in einem eigenen Block', function () {
    app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Freistehend', 'status' => 'aktiv']);

    Livewire::test(Portfolio::class)
        ->assertSeeHtml('data-ctrl-portfolio-ohne')
        ->assertSee('Freistehend');
});

it('nennt den Grund, warum etwas nicht läuft', function () {
    app(SpeisekarteService::class)->create($this->rootTeam, [
        'name' => 'Vorbei', 'status' => 'aktiv', 'outlet_id' => $this->nord->id,
        'gueltig_bis' => '2026-06-30',
    ]);

    Livewire::test(Portfolio::class)->set('stichtag', '2026-08-15')
        ->assertSee('Vorbei')
        ->assertSee('noch nicht archiviert');
});

it('führt zur Pflege, wenn kein Betrieb angelegt ist', function () {
    FoodAlchemistOutlet::query()->delete();

    Livewire::test(Portfolio::class)
        ->assertSee('Noch kein Betrieb angelegt')
        ->assertSee('in den Einstellungen anlegen');
});

it('zeigt keine fremden Ausgaben', function () {
    app(SpeisekarteService::class)->create($this->childB, ['name' => 'Fremde Karte', 'status' => 'aktiv']);

    Livewire::test(Portfolio::class)->assertDontSee('Fremde Karte');
});
