<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Controlling\Cockpit;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Portfolio;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Promotion;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
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

// ── P6 · Promotion-Überwachung ────────────────────────────────────────────────

it('hängt die Promotion-Überwachung vor das Menu-Engineering im Erfolg-Tab', function () {
    Livewire::test(Cockpit::class)->call('setTab', 'erfolg')
        ->assertSee('Was bringen die laufenden Ausgaben')
        ->assertSeeHtml('data-ctrl-promotion');
});

it('nennt die Doppelzählung dort, wo die Zahlen stehen — nicht als Fußnote irgendwo', function () {
    // Die Warnung darf nicht erst erscheinen, wenn der Fall eintritt: wer die Spalte liest,
    // muss beim Lesen wissen, dass sich Ausgaben überlappen können.
    $karte = app(SpeisekarteService::class)->create($this->rootTeam, [
        'name' => 'Nordkarte', 'status' => 'aktiv', 'outlet_id' => $this->nord->id,
    ]);
    $rubrik = app(SpeisekarteService::class)->addRubrik($this->rootTeam, (int) $karte->id, ['name' => 'Speisen']);
    $gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'schnitzel', 'name' => 'Schnitzel',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_unit_count' => 1,
    ]);
    DB::table('foodalchemist_menu_card_items')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => $this->rootTeam->id, 'section_id' => $rubrik->id,
        'type' => 'gericht_ref', 'sales_recipe_id' => $gericht->id, 'position' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    FoodAlchemistSalesFact::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'raw_label' => 'Schnitzel',
        'qty_sold' => 10, 'revenue_net' => 120.00, 'sold_at' => now()->subDay()->toDateString(),
        'source' => 'csv_import', 'source_hash' => 'p6',
    ]);

    Livewire::test(Promotion::class)
        ->assertSee('Nordkarte')
        ->assertSee('davon exklusiv')
        ->assertSee('sein Umsatz zählt dann');
});

it('sagt im Leerzustand, dass Verkaufs-Ist fehlt, statt Nullen zu zeigen', function () {
    // Kritischer Punkt 3 der Spec: im Dev-Bestand gibt es kein Verkaufsjournal. Eine Tabelle
    // voller 0,00 € wäre eine Aussage, die die Daten nicht hergeben.
    Livewire::test(Promotion::class)
        ->assertSeeHtml('data-ctrl-promo-hinweis')
        ->assertSee('Kein Verkaufs-Ist');
});

it('rechnet zum gewählten Stichtag und findet über Heute zurück', function () {
    Livewire::test(Promotion::class)
        ->set('stichtag', '2026-01-15')
        ->assertSee('15.01.2026')
        ->call('heute')
        ->assertSet('stichtag', '')
        ->assertSee(now()->format('d.m.Y'));
});
