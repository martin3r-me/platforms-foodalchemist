<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Browser;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 30 E4/E5 — Produktions-Browser.
 *
 * Zu schützen:
 *  · gefiltert und paginiert wird in SQL, nicht im Speicher (Audit-Befund MVP-033)
 *  · EIN Filtersatz für Liste UND Zähler — sonst zeigen Facetten Treffer, die die Liste
 *    nicht liefert (dieselbe Falle wie MVP-048 im VK-Browser)
 *  · der Filter-Zustand steht in der URL und überlebt einen Reload
 *  · das Detail-Panel ist wieder verdrahtet
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Brauner Fond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 60,
    ]);

    $this->anlegen = fn (string $name, string $datum) => $this->svc->saveNew(
        $this->rootTeam, $datum, $name,
        [['source_ref' => 'r:' . $name, 'recipe_id' => $this->rezept->id, 'amount_kg' => 2.0]],
    );
});

it('filtert und paginiert serverseitig statt die Vollmenge zu laden (MVP-033)', function () {
    foreach (range(1, 12) as $i) {
        ($this->anlegen)('Auftrag ' . $i, '2026-09-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }

    $lw = Livewire::test(Browser::class)->set('perPage', 10);
    $paginator = $lw->viewData('auftraege');

    expect($paginator)->toBeInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(12)
        ->and($paginator->count())->toBe(10)          // nur eine Seite im Speicher
        ->and($paginator->hasPages())->toBeTrue();
});

it('Status-Zähler kennen die übrigen Filter — sonst zeigen sie Treffer, die die Liste nicht hat', function () {
    $a = ($this->anlegen)('Passt', '2026-09-01');
    $b = ($this->anlegen)('Passt nicht', '2026-09-20');
    $this->svc->setStatus($this->rootTeam, $b->id, ProductionOrderStatus::InProgress);

    // Zeitfenster schneidet den zweiten Auftrag weg
    $lw = Livewire::test(Browser::class)->set('von', '2026-09-01')->set('bis', '2026-09-05');

    expect($lw->viewData('gesamtCount'))->toBe(1)
        ->and($lw->viewData('statusCounts')['planned'] ?? 0)->toBe(1)
        ->and($lw->viewData('statusCounts')['in_progress'] ?? 0)->toBe(0);
});

it('sucht in Name und Anlass', function () {
    $this->svc->saveNew($this->rootTeam, '2026-09-01', 'Hochzeit Meyer', [], 'Sommerfest');
    ($this->anlegen)('Tagung Schmitt', '2026-09-02');

    expect(Livewire::test(Browser::class)->set('suche', 'Meyer')->viewData('auftraege')->total())->toBe(1)
        ->and(Livewire::test(Browser::class)->set('suche', 'Sommerfest')->viewData('auftraege')->total())->toBe(1)
        ->and(Livewire::test(Browser::class)->set('suche', 'Schmitt')->viewData('auftraege')->total())->toBe(1);
});

it('Zeitraum-Presets setzen von/bis und lassen sich abwählen', function () {
    ($this->anlegen)('Heute', now()->toDateString());
    ($this->anlegen)('Weit weg', now()->addMonths(3)->toDateString());

    $lw = Livewire::test(Browser::class)->call('waehleZeitraum', 'heute');
    expect($lw->get('von'))->toBe(now()->toDateString())
        ->and($lw->viewData('auftraege')->total())->toBe(1);

    $lw->call('waehleZeitraum', 'heute');   // zweiter Klick hebt auf
    expect($lw->get('zeitraum'))->toBe('')
        ->and($lw->get('von'))->toBeNull()
        ->and($lw->viewData('auftraege')->total())->toBe(2);
});

it('Status-Filter ist ein Toggle', function () {
    ($this->anlegen)('A', '2026-09-01');

    $lw = Livewire::test(Browser::class)->call('waehleStatus', 'planned');
    expect($lw->get('statusFilter'))->toBe('planned');

    $lw->call('waehleStatus', 'planned');
    expect($lw->get('statusFilter'))->toBe('');
});

it('Spalten-Ansichten schalten die Spalten um, Kopf und Zellen bleiben synchron', function () {
    ($this->anlegen)('A', '2026-09-01');

    $standard = Livewire::test(Browser::class)->viewData('spalten');
    $kueche = Livewire::test(Browser::class)->call('waehleAnsicht', 'kueche')->viewData('spalten');

    expect($standard)->toContain('einkauf')->not->toContain('posten')
        ->and($kueche)->toContain('posten')->toContain('zeit')->not->toContain('einkauf')
        // Reihenfolge folgt IMMER dem Katalog, sonst versetzt sich die Tabelle
        ->and($kueche)->toBe(array_values(array_filter(
            array_keys(Browser::SPALTEN), fn ($k) => in_array($k, $kueche, true)
        )));
});

it('eine unbekannte Ansicht wird ignoriert statt die Tabelle zu leeren', function () {
    ($this->anlegen)('A', '2026-09-01');

    $lw = Livewire::test(Browser::class)->call('waehleAnsicht', 'gibtsnicht');
    expect($lw->get('ansicht'))->toBe('standard')->and($lw->viewData('spalten'))->not->toBeEmpty();
});

it('der Filter-Zustand steht vollständig in der URL', function () {
    $urlProps = collect((new ReflectionClass(Browser::class))->getProperties())
        ->filter(fn ($p) => $p->getAttributes(\Livewire\Attributes\Url::class) !== [])
        ->map(fn ($p) => $p->getName())->values()->all();

    expect($urlProps)->toBe(['orderId', 'statusFilter', 'von', 'bis', 'suche', 'zeitraum', 'ansicht', 'perPage']);
});

it('Zeilen-Klick wählt fürs Detail-Panel, ohne den Editor zu öffnen', function () {
    $a = ($this->anlegen)('Klickziel', '2026-09-01');

    Livewire::test(Browser::class)
        ->call('waehle', $a->id)
        ->assertSet('orderId', $a->id)
        ->assertDispatched('production-order-selected')
        ->assertNotDispatched('produktion-editor.bearbeiten');
});

it('rendert Detail-Panel, KPI-Zeile und Pagination-Gerüst', function () {
    ($this->anlegen)('Sichtbar', now()->toDateString());

    Livewire::test(Browser::class)
        ->assertSeeHtml('data-produktion-dashboard')
        ->assertSeeHtml('data-produktion-dashboard-hauptpanel')
        ->assertSeeHtml('data-produktion-dashboard-lekkarai')
        ->assertSeeHtml('data-produktion-dashboard-manntage')
        ->assertSeeHtml('data-produktion-dashboard-changelog')
        ->assertSeeHtml('data-produktion-dashboard-produktion')
        ->assertSeeHtml('data-produktion-dashboard-performance')
        ->assertSeeHtml('data-produktion-dashboard-auslastung')
        ->assertSeeHtml('data-produktion-dashboard-horizont')
        ->assertSeeHtml('data-produktion-dashboard-zeitraum')
        ->assertSeeHtml('data-produktion-tagesplanung-details')
        ->assertSeeHtml('data-produktion-kpi')
        ->assertSeeHtml('data-produktion-statusfilter')
        ->assertSeeHtml('data-produktion-ansichten')
        ->assertSeeHtml('data-produktion-wandmonitor')
        ->assertSee('Sichtbar')
        ->assertSee('Offene Aufträge');
});

it('hat das Küchenchef-Dashboard direkt auf der Hauptseite mit 3/7/14 Tagen und Monatsblick', function () {
    ($this->anlegen)('Bankett Freitag', '2026-09-04');

    $lw = Livewire::test(Browser::class)
        ->set('dashboardVon', '2026-09-01')
        ->call('waehleDashboardFenster', 3)
        ->assertSet('dashboardTage', 3)
        ->assertSeeHtml('data-produktion-dashboard-fenster')
        ->assertSeeHtml('data-produktion-dashboard-tag="2026-09-01"')
        ->assertSeeHtml('data-produktion-dashboard-tagesordnung')
        ->assertSee('Küchenchef-Dashboard')
        ->assertSee('Steuerung')
        ->assertSee('Auslastung')
        ->assertSee('Planung in Manntagen')
        ->assertSee('Change Log')
        ->assertSee('Performance')
        ->assertSee('Tagesplanung Details');

    expect($lw->viewData('dashboard')['fenster'])->toBe(3)
        ->and($lw->viewData('dashboard')['von'])->toBe('2026-09-01')
        ->and($lw->viewData('dashboard')['bis'])->toBe('2026-09-03');

    $lw->call('waehleDashboardFenster', 14)->assertSet('dashboardTage', 14);
    expect($lw->viewData('dashboard')['fenster'])->toBe(14)
        ->and($lw->viewData('dashboard')['bis'])->toBe('2026-09-14');

    $lw->call('waehleDashboardFenster', 30)->assertSet('dashboardTage', 30);
    expect($lw->viewData('dashboard')['fenster'])->toBe(30)
        ->and($lw->viewData('dashboard')['bis'])->toBe('2026-09-30');
});

it('verschiebt den Dashboard-Starttag unabhängig vom Listenfilter', function () {
    $lw = Livewire::test(Browser::class)
        ->set('von', '2026-08-01')
        ->set('dashboardVon', '2026-09-10')
        ->call('dashboardTagVerschieben', 1)
        ->assertSet('dashboardVon', '2026-09-11');

    expect($lw->get('von'))->toBe('2026-08-01')
        ->and($lw->viewData('dashboard')['von'])->toBe('2026-09-11');
});

it('erlaubt einen gezielten Dashboard-Zeitraum über Kalender-von-bis', function () {
    $lw = Livewire::test(Browser::class)
        ->set('dashboardVon', '2026-10-05')
        ->set('dashboardBis', '2026-10-23')
        ->assertSeeHtml('data-produktion-dashboard-zeitraum');

    expect($lw->viewData('dashboard')['von'])->toBe('2026-10-05')
        ->and($lw->viewData('dashboard')['bis'])->toBe('2026-10-23')
        ->and($lw->viewData('dashboard')['fenster'])->toBe(19);
});

it('sagt im Leerzustand, was zu tun ist', function () {
    Livewire::test(Browser::class)->set('suche', 'gibtesnicht')
        ->assertSeeHtml('data-produktion-leer')
        ->assertSee('Filter zurücksetzen');
});

it('zeigt nur Aufträge des eigenen Teams (D1)', function () {
    ($this->anlegen)('Meiner', '2026-09-01');
    $this->svc->saveNew($this->childB, '2026-09-01', 'Fremder', []);

    expect(Livewire::test(Browser::class)->viewData('auftraege')->pluck('name')->all())->toBe(['Meiner']);
});
