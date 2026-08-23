<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PortfolioService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 33 · P3 — der gemeinsame Leser über die drei Ausgabeformen.
 *
 * Geprüft wird nicht die Liste (die ist trivial), sondern das, wofür man eine Mehrbetriebs-Sicht
 * überhaupt baut: **was daran nicht stimmt** — Konflikte, Lücken und die Ausgaben, die in keiner
 * der beiden Brillen auftauchen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PortfolioService::class);

    $this->nord = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord']);
    $this->sued = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Bistro Süd']);

    $this->karte = function (array $attr) {
        return app(SpeisekarteService::class)->create($this->rootTeam, $attr + ['name' => 'Karte']);
    };
});

it('führt alle drei Ausgabeformen in einer Zeilenform', function () {
    app(FoodbookService::class)->create($this->rootTeam, ['label' => 'FB', 'status' => 'aktiv']);
    ($this->karte)(['name' => 'Sommerkarte', 'status' => 'aktiv']);
    app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'KW 30', 'status' => 'aktiv']);

    $zeilen = $this->svc->uebersicht($this->rootTeam);

    expect($zeilen)->toHaveCount(3)
        ->and(collect($zeilen)->pluck('art')->sort()->values()->all())
        ->toBe(['foodbook', 'speisekarte', 'speiseplan'])
        // Der Sprung in die Bearbeitung gehört in die Zeile — sonst ist die Übersicht eine Sackgasse.
        ->and(collect($zeilen)->every(fn ($z) => str_contains((string) $z['route'], '=')))->toBeTrue();
});

it('sortiert Laufendes nach oben', function () {
    ($this->karte)(['name' => 'Archiv', 'status' => 'archiviert']);
    ($this->karte)(['name' => 'Läuft', 'status' => 'aktiv']);

    expect($this->svc->uebersicht($this->rootTeam)[0]['name'])->toBe('Läuft');
});

it('meldet zwei laufende Karten im selben Betrieb als Konflikt', function () {
    ($this->karte)(['name' => 'Sommerkarte', 'status' => 'aktiv', 'outlet_id' => $this->nord->id]);
    ($this->karte)(['name' => 'Sonderkarte', 'status' => 'aktiv', 'outlet_id' => $this->nord->id]);
    // Andere Art im selben Betrieb ist KEIN Konflikt — Karte und Plan laufen nebeneinander.
    app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Plan', 'status' => 'aktiv', 'outlet_id' => $this->nord->id]);
    // Anderer Betrieb ebenfalls nicht.
    ($this->karte)(['name' => 'Südkarte', 'status' => 'aktiv', 'outlet_id' => $this->sued->id]);

    $k = collect($this->svc->konflikte($this->rootTeam))->where('brille', 'betrieb')->values();

    expect($k)->toHaveCount(1)
        ->and($k[0]['zuordnung'])->toBe('Kantine Nord')
        ->and($k[0]['art'])->toBe('speisekarte')
        ->and(collect($k[0]['ausgaben'])->pluck('name')->sort()->values()->all())
        ->toBe(['Sommerkarte', 'Sonderkarte']);
});

it('zählt eine abgelaufene Karte nicht als Konflikt', function () {
    // Der Kern der Fenster-Bremse: „aktiv, aber vorbei" belegt den Platz nicht mehr.
    ($this->karte)(['name' => 'Alt', 'status' => 'aktiv', 'outlet_id' => $this->nord->id, 'gueltig_bis' => '2026-06-30']);
    ($this->karte)(['name' => 'Neu', 'status' => 'aktiv', 'outlet_id' => $this->nord->id]);

    expect($this->svc->konflikte($this->rootTeam, '2026-08-15'))->toBe([]);
});

it('gruppiert Ausgaben desselben CRM-Kunden als eine Kunden-Achse', function () {
    // CRM-only (b08c5c2): die Kundenachse kommt aus der verknüpften CRM-Firma (nicht mehr aus Freitext).
    // Zwei Ausgaben mit derselben crm_company_id gehören zu EINEM Kunden.
    $kunde = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Klinikum West', 'is_active' => true]);
    ($this->karte)(['name' => 'A', 'status' => 'aktiv', 'crm_company_id' => $kunde->id]);
    ($this->karte)(['name' => 'B', 'status' => 'aktiv', 'crm_company_id' => $kunde->id]);

    $k = collect($this->svc->konflikte($this->rootTeam))->where('brille', 'kunde')->values();

    expect($k)->toHaveCount(1)
        ->and($k[0]['ausgaben'])->toHaveCount(2);
});

it('meldet Betriebe ohne laufende Ausgabe als Lücke', function () {
    ($this->karte)(['name' => 'Nordkarte', 'status' => 'aktiv', 'outlet_id' => $this->nord->id]);

    $l = collect($this->svc->luecken($this->rootTeam, 'betrieb'))->keyBy('zuordnung');

    // Bistro Süd hat gar nichts …
    expect($l['Bistro Süd']['fehlende_arten'])->toBe(['foodbook', 'speisekarte', 'speiseplan'])
        // … Kantine Nord hat eine Karte, es fehlen Foodbook und Plan.
        ->and($l['Kantine Nord']['fehlende_arten'])->toBe(['foodbook', 'speiseplan']);
});

it('führt Ausgaben ohne jede Zuordnung getrennt', function () {
    // Ohne diesen Block wären sie in beiden Brillen unsichtbar.
    ($this->karte)(['name' => 'Freistehend', 'status' => 'aktiv']);
    ($this->karte)(['name' => 'Zugeordnet', 'status' => 'aktiv', 'outlet_id' => $this->nord->id]);

    $ohne = $this->svc->ohneZuordnung($this->rootTeam);

    expect($ohne)->toHaveCount(1)
        ->and($ohne[0]['name'])->toBe('Freistehend');
});

it('formuliert den Konflikt-Hinweis für den Editor', function () {
    $a = ($this->karte)(['name' => 'Sommerkarte', 'status' => 'aktiv', 'outlet_id' => $this->nord->id]);
    $b = ($this->karte)(['name' => 'Sonderkarte', 'status' => 'aktiv', 'outlet_id' => $this->nord->id]);

    expect($this->svc->konfliktHinweis($this->rootTeam, 'speisekarte', (int) $b->id))
        ->toContain('Sommerkarte')->toContain('Kantine Nord');

    // Ohne Parallelbetrieb kein Hinweis.
    $allein = ($this->karte)(['name' => 'Allein', 'status' => 'aktiv', 'outlet_id' => $this->sued->id]);
    expect($this->svc->konfliktHinweis($this->rootTeam, 'speisekarte', (int) $allein->id))->toBeNull();
});

it('zeigt keine fremden Ausgaben und keine fremden Betriebe', function () {
    app(SpeisekarteService::class)->create($this->childB, ['name' => 'Fremde Karte', 'status' => 'aktiv']);
    FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremder Betrieb']);

    expect(collect($this->svc->uebersicht($this->rootTeam))->pluck('name'))->not->toContain('Fremde Karte')
        ->and(collect($this->svc->luecken($this->rootTeam, 'betrieb'))->pluck('zuordnung'))
        ->not->toContain('Fremder Betrieb');
});

it('holt das Speiseplan-Fenster ohne N+1', function () {
    foreach (range(1, 3) as $i) {
        $plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'P' . $i, 'status' => 'aktiv']);
        DB::table('foodalchemist_menu_plan_entries')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $this->rootTeam->id, 'menu_plan_id' => $plan->id,
            'entry_date' => '2026-07-0' . $i, 'week' => 1, 'weekday' => 1, 'meal' => 'mittag', 'position' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    DB::enableQueryLog();
    $zeilen = $this->svc->uebersicht($this->rootTeam, '2026-07-02', ['art' => 'speiseplan']);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Ein Lauf über drei Pläne darf nicht mit der Planzahl wachsen — genau dafür lädt der
    // Dienst withMin/withMax eager.
    expect($zeilen)->toHaveCount(3)
        ->and($queries)->toBeLessThanOrEqual(3)
        ->and(collect($zeilen)->firstWhere('name', 'P2')['von'])->toBe('2026-07-02');
});
