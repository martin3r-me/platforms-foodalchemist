<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\MenuEngineeringService;
use Platform\FoodAlchemist\Services\SalesImportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 32 · C3 — Verkaufs-Ist: Import, Zuordnung, Menu-Engineering.
 *
 * Die Erlösseite war das eine echte Loch im Modul. Geprüft wird deshalb nicht nur, dass
 * gelesen wird, sondern dass die Eigenschaften stimmen, an denen ein Ist-Import scheitert:
 * Idempotenz, kein stiller Verlust ungematchter Zeilen, und dass Handarbeit den nächsten
 * Import überlebt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->import = app(SalesImportService::class);

    $this->ordner = storage_path('app/' . SalesImportService::ORDNER);
    if (! is_dir($this->ordner)) {
        mkdir($this->ordner, 0775, true);
    }

    $this->schreibe = function (string $name, string $inhalt): string {
        file_put_contents($this->ordner . '/' . $name, $inhalt);
        $this->dateien[] = $this->ordner . '/' . $name;

        return $name;
    };
    $this->dateien = [];

    $this->sf = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id],
        ['label' => 'Unbestimmt'],
    );

    $this->mkGericht = function (string $key, string $name, float $vk, float $ek): FoodAlchemistRecipe {
        $r = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
            'status' => 'approved', 'is_sales_recipe' => true, 'sales_unit_count' => 1,
        ]);
        FoodAlchemistRecipeDarreichung::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->sf->id,
            'is_standard' => true, 'sales_net' => $vk, 'ek_portion' => $ek,
        ]);

        return $r;
    };
});

afterEach(function () {
    foreach ($this->dateien ?? [] as $f) {
        @unlink($f);
    }
});

it('erkennt Kopfzeile und schlägt eine Spalten-Zuordnung vor', function () {
    $name = ($this->schreibe)('verkauf_kopf.csv', "Artikel;Anzahl;Umsatz netto;Datum;Kostenstelle\nSchnitzel;3;27,00;01.07.2026;Kantine\n");

    $kopf = $this->import->kopf($name);

    expect($kopf['spalten'])->toHaveCount(5)
        ->and($kopf['vorschlag'])->toMatchArray([
            'bezeichnung' => 0, 'menge' => 1, 'umsatz' => 2, 'datum' => 3, 'bereich' => 4,
        ]);
});

it('schreibt im Trockenlauf nichts, meldet aber dasselbe Ergebnis', function () {
    ($this->mkGericht)('g1', 'Schnitzel Wiener Art', 9.00, 3.00);
    $name = ($this->schreibe)('verkauf_dry.csv', "Artikel;Anzahl;Umsatz;Datum\nSchnitzel Wiener Art;4;36,00;01.07.2026\n");
    $map = ['bezeichnung' => 0, 'menge' => 1, 'umsatz' => 2, 'datum' => 3];

    $dry = $this->import->importiere($this->rootTeam, $name, $map);
    expect($dry['apply'])->toBeFalse()
        ->and($dry['gelesen'])->toBe(1)
        ->and($dry['neu'])->toBe(1)
        ->and(FoodAlchemistSalesFact::count())->toBe(0);

    $scharf = $this->import->importiere($this->rootTeam, $name, $map, apply: true);
    expect($scharf['neu'])->toBe(1)
        ->and(FoodAlchemistSalesFact::count())->toBe(1);
});

it('ist idempotent — derselbe Export zweimal ergibt keine Dubletten', function () {
    ($this->mkGericht)('g1', 'Gulaschsuppe', 6.00, 2.00);
    $name = ($this->schreibe)('verkauf_idem.csv', "Artikel;Umsatz;Datum\nGulaschsuppe;60,00;02.07.2026\n");
    $map = ['bezeichnung' => 0, 'umsatz' => 1, 'datum' => 2];

    $this->import->importiere($this->rootTeam, $name, $map, apply: true);
    $zweiter = $this->import->importiere($this->rootTeam, $name, $map, apply: true);

    expect(FoodAlchemistSalesFact::count())->toBe(1)
        ->and($zweiter['neu'])->toBe(0)
        ->and($zweiter['aktualisiert'])->toBe(1);
});

it('behält ungematchte Zeilen samt Umsatz, statt sie zu verwerfen', function () {
    ($this->mkGericht)('g1', 'Rinderroulade', 14.00, 5.00);
    $name = ($this->schreibe)('verkauf_offen.csv',
        "Artikel;Umsatz;Datum\nRinderroulade;140,00;03.07.2026\nIrgendein Fremdposten XY;90,00;03.07.2026\n");

    $b = $this->import->importiere($this->rootTeam, $name, ['bezeichnung' => 0, 'umsatz' => 1, 'datum' => 2], apply: true);

    expect($b['gematcht'])->toBe(1)
        ->and($b['ungematcht'])->toBe(1)
        ->and($b['umsatz'])->toBe(230.0)
        ->and(FoodAlchemistSalesFact::whereNull('recipe_id')->count())->toBe(1)
        ->and(FoodAlchemistSalesFact::whereNull('recipe_id')->value('raw_label'))->toBe('Irgendein Fremdposten XY');
});

it('lässt eine Handzuordnung den nächsten Import überleben', function () {
    $gericht = ($this->mkGericht)('g1', 'Kaiserschmarrn', 8.00, 2.50);
    $name = ($this->schreibe)('verkauf_manual.csv', "Artikel;Umsatz;Datum\nHausdessert;80,00;04.07.2026\n");
    $map = ['bezeichnung' => 0, 'umsatz' => 1, 'datum' => 2];

    $this->import->importiere($this->rootTeam, $name, $map, apply: true);
    $fact = FoodAlchemistSalesFact::first();
    expect($fact->recipe_id)->toBeNull();

    $this->import->zuordnen($this->rootTeam, (int) $fact->id, (int) $gericht->id);
    expect((int) $fact->refresh()->recipe_id)->toBe((int) $gericht->id)
        ->and($fact->match_method)->toBe('manual');

    // Ohne diesen Schutz würde jeder erneute Lauf die Handarbeit mit „kein Treffer" plätten.
    $this->import->importiere($this->rootTeam, $name, $map, apply: true);
    expect((int) $fact->refresh()->recipe_id)->toBe((int) $gericht->id);
});

it('überspringt unlesbare Zeilen und benennt sie', function () {
    $name = ($this->schreibe)('verkauf_kaputt.csv', "Artikel;Umsatz;Datum\n;10,00;05.07.2026\nSuppe;10,00;kein Datum\n");

    $b = $this->import->importiere($this->rootTeam, $name, ['bezeichnung' => 0, 'umsatz' => 1, 'datum' => 2]);

    expect($b['uebersprungen'])->toBe(2)
        ->and($b['fehler'])->toHaveCount(2)
        ->and($b['fehler'][0])->toContain('ohne Bezeichnung')
        ->and($b['fehler'][1])->toContain('Datum nicht lesbar');
});

it('weist eine xlsx-Datei mit einem Weg statt einer Fehlermeldung ab', function () {
    ($this->schreibe)('verkauf.xlsx', 'egal');

    expect(fn () => $this->import->kopf('verkauf.xlsx'))
        ->toThrow(RuntimeException::class, 'als CSV');
});

it('nimmt keinen freien Pfad an', function () {
    // Ein Import, der Pfade annimmt, ist ein Lesezugriff aufs Server-Dateisystem.
    expect(fn () => $this->import->kopf('../../../../etc/passwd.csv'))
        ->toThrow(RuntimeException::class, 'Datei nicht gefunden');
});

it('baut die Menu-Engineering-Matrix aus dem Verkaufs-Ist', function () {
    // Star: viel verkauft, hoher DB. Penner: wenig verkauft, niedriger DB.
    $star = ($this->mkGericht)('g1', 'Rinderfilet', 30.00, 10.00);      // DB 20
    $penner = ($this->mkGericht)('g2', 'Beilagensalat', 4.00, 3.00);    // DB 1

    $name = ($this->schreibe)('verkauf_matrix.csv',
        "Artikel;Anzahl;Umsatz;Datum\nRinderfilet;100;3000,00;06.07.2026\nBeilagensalat;5;20,00;06.07.2026\n");
    $this->import->importiere($this->rootTeam, $name, ['bezeichnung' => 0, 'menge' => 1, 'umsatz' => 2, 'datum' => 3], apply: true);

    $m = app(MenuEngineeringService::class)->matrix($this->rootTeam);

    expect($m['quelle'])->toBe('sales')
        ->and($m['n'])->toBe(2)
        ->and($m['umsatz'])->toBe(3020.0)
        ->and($m['quadranten']['star'])->toBe(1)
        ->and($m['quadranten']['penner'])->toBe(1);

    $nachId = collect($m['zeilen'])->keyBy('recipe_id');
    expect($nachId[$star->id]['quadrant'])->toBe('star')
        ->and($nachId[$star->id]['db_eur'])->toBe(20.0)
        ->and($nachId[$penner->id]['quadrant'])->toBe('penner');
});

it('weist die Popularitäts-Quelle aus, statt Feedback als Absatz auszugeben', function () {
    ($this->mkGericht)('g1', 'Currywurst', 7.00, 2.00);

    // Ohne Verkaufs-Ist bleibt nur die menschliche Akzeptanz — und die Matrix sagt das.
    $m = app(MenuEngineeringService::class)->matrix($this->rootTeam);
    expect($m['quelle'])->toBe('feedback');
});

it('hält das Verkaufsjournal strikt am eigenen Team', function () {
    ($this->mkGericht)('g1', 'Linsensuppe', 5.00, 1.50);
    $name = ($this->schreibe)('verkauf_tenant.csv', "Artikel;Umsatz;Datum\nLinsensuppe;50,00;07.07.2026\n");
    $this->import->importiere($this->rootTeam, $name, ['bezeichnung' => 0, 'umsatz' => 1, 'datum' => 2], apply: true);

    // childA ist ein Kind von rootTeam — Umsätze des Eltern-Betriebs gehen es nichts an.
    expect(FoodAlchemistSalesFact::where('team_id', $this->childA->id)->count())->toBe(0)
        ->and(app(MenuEngineeringService::class)->matrix($this->childA)['quelle'])->not->toBe('sales');
});
