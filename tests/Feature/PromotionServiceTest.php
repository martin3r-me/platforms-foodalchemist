<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
use Platform\FoodAlchemist\Services\PromotionService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 33 · P6 — Promotion-Überwachung.
 *
 * Der Rechenweg ist einfach; gefährlich ist die Aussage. Geprüft wird deshalb vor allem, dass
 * die Fläche ihre zwei Vorbehalte einhält: die **Doppelzählung** bei geteilten Gerichten und die
 * **Zuordnungs-Abdeckung** des Verkaufsjournals. Ohne beide läse sich die Liste genauer, als
 * sie ist.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PromotionService::class);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord']);

    $this->gericht = function (string $name): FoodAlchemistRecipe {
        return FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => \Illuminate\Support\Str::slug($name),
            'name' => $name, 'status' => 'approved', 'is_sales_recipe' => true, 'sales_unit_count' => 1,
        ]);
    };

    // Karte mit Rubrik + Position auf ein Gericht.
    $this->karteMit = function (string $name, array $gerichte, array $attr = []) {
        $karte = app(SpeisekarteService::class)->create($this->rootTeam, $attr + [
            'name' => $name, 'status' => 'aktiv', 'outlet_id' => $this->betrieb->id,
        ]);
        $rubrik = app(SpeisekarteService::class)->addRubrik($this->rootTeam, (int) $karte->id, ['name' => 'Speisen']);
        foreach ($gerichte as $g) {
            DB::table('foodalchemist_menu_card_items')->insert([
                'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                'team_id' => $this->rootTeam->id, 'section_id' => $rubrik->id,
                'type' => 'gericht_ref', 'sales_recipe_id' => $g->id, 'position' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $karte;
    };

    $this->verkauf = function (FoodAlchemistRecipe $g, float $menge, float $umsatz, string $tag = '2026-07-15') {
        FoodAlchemistSalesFact::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $g->id, 'raw_label' => $g->name,
            'qty_sold' => $menge, 'revenue_net' => $umsatz, 'sold_at' => $tag,
            'source' => 'csv_import', 'source_hash' => sha1($g->name . $tag . $umsatz),
        ]);
    };
});

it('rechnet den Umsatz einer laufenden Karte über ihre Positionen', function () {
    $schnitzel = ($this->gericht)('Schnitzel');
    ($this->karteMit)('Sommerkarte', [$schnitzel]);
    ($this->verkauf)($schnitzel, 100, 1200.00);

    $p = $this->svc->uebersicht($this->rootTeam, '2026-07-31');

    expect($p['zeilen'])->toHaveCount(1)
        ->and($p['zeilen'][0]['name'])->toBe('Sommerkarte')
        ->and($p['zeilen'][0]['umsatz'])->toBe(1200.0)
        ->and($p['zeilen'][0]['menge'])->toBe(100.0)
        ->and($p['zeilen'][0]['n_gerichte'])->toBe(1);
});

it('weist den exklusiven Anteil aus, wenn ein Gericht in zwei laufenden Ausgaben steht', function () {
    // DAS ist die Falle: der Umsatz zählt bei beiden Karten, die Summe der Ausgaben ist dann
    // größer als der Gesamtumsatz. Ohne den exklusiven Anteil würde man sie addieren.
    $geteilt = ($this->gericht)('Pommes');
    $eigen = ($this->gericht)('Rinderfilet');

    ($this->karteMit)('Karte A', [$geteilt, $eigen]);
    ($this->karteMit)('Karte B', [$geteilt]);

    ($this->verkauf)($geteilt, 200, 400.00);
    ($this->verkauf)($eigen, 10, 300.00);

    $p = $this->svc->uebersicht($this->rootTeam, '2026-07-31');
    $nach = collect($p['zeilen'])->keyBy('name');

    expect($nach['Karte A']['umsatz'])->toBe(700.0)          // 400 geteilt + 300 eigen
        ->and($nach['Karte A']['umsatz_exklusiv'])->toBe(300.0)
        ->and($nach['Karte A']['n_gerichte_exklusiv'])->toBe(1)
        ->and($nach['Karte B']['umsatz'])->toBe(400.0)
        ->and($nach['Karte B']['umsatz_exklusiv'])->toBe(0.0)
        ->and($nach['Karte B']['exklusiv_pct'])->toBe(0.0);

    // Die Summe übersteigt den Gesamtumsatz — genau deshalb steht der exklusive Anteil daneben.
    expect(collect($p['zeilen'])->sum('umsatz'))->toBeGreaterThan($p['umsatz_gesamt']);
});

it('bleibt im Gültigkeitsfenster der Ausgabe', function () {
    $g = ($this->gericht)('Suppe');
    ($this->karteMit)('Julikarte', [$g], ['gueltig_von' => '2026-07-01', 'gueltig_bis' => '2026-07-31']);

    ($this->verkauf)($g, 10, 100.00, '2026-07-15');   // drin
    ($this->verkauf)($g, 10, 999.00, '2026-06-15');   // davor

    $p = $this->svc->uebersicht($this->rootTeam, '2026-07-31');
    expect($p['zeilen'][0]['umsatz'])->toBe(100.0);
});

it('kappt ein offenes Fenster am Stichtag', function () {
    // Eine unbefristet laufende Karte würde sonst auch den Umsatz von nach dem Stichtag
    // einsammeln und die Zeitreise unbrauchbar machen.
    $g = ($this->gericht)('Currywurst');
    ($this->karteMit)('Dauerkarte', [$g]);

    ($this->verkauf)($g, 10, 100.00, '2026-07-15');
    ($this->verkauf)($g, 10, 500.00, '2026-09-15');

    expect($this->svc->uebersicht($this->rootTeam, '2026-07-31')['zeilen'][0]['umsatz'])->toBe(100.0)
        ->and($this->svc->uebersicht($this->rootTeam, '2026-09-30')['zeilen'][0]['umsatz'])->toBe(600.0);
});

it('warnt, wenn zu wenig Umsatz einem Gericht zugeordnet ist', function () {
    $g = ($this->gericht)('Salat');
    ($this->karteMit)('Karte', [$g]);
    ($this->verkauf)($g, 10, 100.00);

    // Nicht zugeordnete Verkaufszeile — kann keiner Ausgabe zugerechnet werden.
    FoodAlchemistSalesFact::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => null, 'raw_label' => 'Unbekannt',
        'qty_sold' => 5, 'revenue_net' => 900.00, 'sold_at' => '2026-07-15',
        'source' => 'csv_import', 'source_hash' => 'offen',
    ]);

    $p = $this->svc->uebersicht($this->rootTeam, '2026-07-31');

    expect($p['umsatz_gesamt'])->toBe(1000.0)
        ->and($p['umsatz_zugeordnet'])->toBe(100.0)
        ->and($p['abdeckung_pct'])->toBe(10.0)
        ->and($p['hinweis'])->toContain('erst die offenen Verkaufszeilen zuordnen');
});

it('sagt es, wenn es gar kein Verkaufs-Ist gibt', function () {
    ($this->karteMit)('Karte', []);

    expect($this->svc->uebersicht($this->rootTeam)['hinweis'])->toContain('Kein Verkaufs-Ist');
});

it('zählt eine nicht laufende Ausgabe nicht mit', function () {
    $g = ($this->gericht)('Braten');
    ($this->karteMit)('Inaktiv', [$g], ['status' => 'inaktiv']);
    ($this->verkauf)($g, 10, 100.00);

    expect($this->svc->uebersicht($this->rootTeam, '2026-07-31')['zeilen'])->toBe([]);
});

it('beantwortet die Rückrichtung: in welchen Ausgaben steckt dieses Gericht', function () {
    // Die Gegenrichtung gab es bisher nicht — am Rezept hängt keine Relation zu den Ausgaben.
    $g = ($this->gericht)('Pommes');
    ($this->karteMit)('Karte A', [$g]);
    ($this->karteMit)('Karte B', [$g]);
    ($this->karteMit)('Karte C', []);

    $treffer = $this->svc->ausgabenFuerGericht($this->rootTeam, (int) $g->id);

    expect(collect($treffer)->pluck('name')->sort()->values()->all())->toBe(['Karte A', 'Karte B']);
});

it('rechnet keine fremden Umsätze mit', function () {
    $g = ($this->gericht)('Schnitzel');
    ($this->karteMit)('Karte', [$g]);
    ($this->verkauf)($g, 10, 100.00);

    FoodAlchemistSalesFact::create([
        'team_id' => $this->childB->id, 'recipe_id' => $g->id, 'raw_label' => 'Fremd',
        'qty_sold' => 999, 'revenue_net' => 9999.00, 'sold_at' => '2026-07-15',
        'source' => 'csv_import', 'source_hash' => 'fremd',
    ]);

    $p = $this->svc->uebersicht($this->rootTeam, '2026-07-31');
    expect($p['zeilen'][0]['umsatz'])->toBe(100.0)
        ->and($p['umsatz_gesamt'])->toBe(100.0);
});
