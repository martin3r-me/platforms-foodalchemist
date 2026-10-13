<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §3 / Schritt 4d: Preislisten-Import eines Mandanten — Abgleich + Prüfliste.
 * Diese Stufe schreibt weder Preise noch Artikel; ein Treffer wartet auf die Schreib-Schicht (Teampreis).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PreislistenImportService::class);
    $this->team = $this->rootTeam;

    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    $this->chefs = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Chefs Culinar']);
    $this->la = fn ($sup, string $nr, ?string $ean = null, $team = null) => FoodAlchemistSupplierItem::create([
        'team_id' => ($team ?? $this->team)->id, 'supplier_id' => $sup->id, 'designation' => "Artikel {$nr}",
        'article_number' => $nr, 'ean_packaging' => $ean,
    ]);
    $this->mehl = ($this->la)($this->hanos, '1001', '4000000000017');
    $this->oel = ($this->la)($this->chefs, '2002');

    $this->csv = function (string $inhalt, string $endung = 'csv'): string {
        $pfad = tempnam(sys_get_temp_dir(), 'pl') . '.' . $endung;
        file_put_contents($pfad, $inhalt);

        return $pfad;
    };
    $this->stati = fn ($run) => FoodAlchemistPriceImportLine::where('run_id', $run->id)->orderBy('row_nr')->pluck('status', 'row_nr')->all();
});

it('Treffer über Artikelnummer + Lieferant (Lieferant aus der Spalte); es wird weder ein Preis geschrieben noch ein Artikel angelegt', function () {
    $preiseVorher = FoodAlchemistPrice::count();
    $artikelVorher = FoodAlchemistSupplierItem::count();

    $pfad = ($this->csv)("Lieferant;Artikelnummer;Bezeichnung;Gebinde;Preis netto;gültig ab\nHanos;1001;Mehl 25kg;Sack;12,50;01.11.2026\nChefs Culinar;2002;Öl 5l;Kanister;1.234,56;2026-11-01\n");
    $run = $this->svc->stage($this->team, $pfad);

    expect(($this->stati)($run))->toBe([2 => 'treffer', 3 => 'treffer'])
        ->and($run->counts['treffer'])->toBe(2)
        ->and(FoodAlchemistPrice::count())->toBe($preiseVorher)               // kein Preis
        ->and(FoodAlchemistSupplierItem::count())->toBe($artikelVorher);       // kein Artikel

    $z = FoodAlchemistPriceImportLine::where('run_id', $run->id)->orderBy('row_nr')->get();
    expect($z[0]->supplier_item_id)->toBe($this->mehl->id)->and($z[0]->match_method)->toBe('artikelnummer')
        ->and((float) $z[0]->price)->toBe(12.5)->and($z[0]->valid_from->toDateString())->toBe('2026-11-01')
        ->and((float) $z[1]->price)->toBe(1234.56);
});

it('EAN ist der Fallback, wenn die Artikelnummer nicht trifft', function () {
    $pfad = ($this->csv)("Artikelnummer;EAN;Preis\nXYZ;4000000000017;9,90\n");
    $run = $this->svc->stage($this->team, $pfad, [], $this->hanos->id);

    $z = FoodAlchemistPriceImportLine::where('run_id', $run->id)->first();
    expect($z->status)->toBe('treffer')->and($z->match_method)->toBe('ean')->and($z->supplier_item_id)->toBe($this->mehl->id);
});

it('Zeilen ohne Treffer kommen in die Prüfliste, es wird kein Artikel angelegt', function () {
    $artikelVorher = FoodAlchemistSupplierItem::count();
    $pfad = ($this->csv)("Artikelnummer;Preis\n1001;5,00\n9999;7,00\n");
    $run = $this->svc->stage($this->team, $pfad, [], $this->hanos->id);

    $pruefliste = $this->svc->pruefliste($run);
    expect($pruefliste)->toHaveCount(1)
        ->and($pruefliste->first()->status)->toBe('ohne_treffer')->and($pruefliste->first()->article_number)->toBe('9999')
        ->and(FoodAlchemistSupplierItem::count())->toBe($artikelVorher);
});

it('mehrdeutige Artikelnummer, unbekannter Lieferant und doppelte Zeile landen mit Grund in der Prüfliste', function () {
    ($this->la)($this->hanos, '1001');   // zweiter Artikel mit derselben Nummer → mehrdeutig
    ($this->la)($this->hanos, '3003');
    $pfad = ($this->csv)("Lieferant;Artikelnummer;Preis\nHanos;1001;5\nUnbekannt GmbH;3003;6\nHanos;3003;7\nHanos;3003;8\n");
    $run = $this->svc->stage($this->team, $pfad);

    expect(($this->stati)($run))->toBe([2 => 'mehrdeutig', 3 => 'lieferant_unbekannt', 4 => 'treffer', 5 => 'doppelt']);
});

it('Preis- und Datumsformate; unbrauchbare Zeilen sind Fehler mit Grund, nie ein Preis', function () {
    $pfad = ($this->csv)("Artikelnummer;Preis;gültig ab\n1001;€ 3,20;31.12.2026\n1001;0;\n1001;abc;\n1001;4,00;32.13.2026\n");
    $run = $this->svc->stage($this->team, $pfad, [], $this->hanos->id);

    $z = FoodAlchemistPriceImportLine::where('run_id', $run->id)->orderBy('row_nr')->get();
    expect($z->pluck('status')->all())->toBe(['treffer', 'fehler', 'fehler', 'fehler'])
        ->and((float) $z[0]->price)->toBe(3.2)->and($z[0]->valid_from->toDateString())->toBe('2026-12-31')
        ->and($z[1]->reason)->toContain('0 oder negativ')->and($z[2]->reason)->toContain('keine Zahl')->and($z[3]->reason)->toContain('Datum');
});

it('Ohne gültig-ab-Spalte gilt heute; alte .xls und kaputte Excel-Dateien werden mit Hinweis abgelehnt; Windows-1252 wird gelesen', function () {
    $run = $this->svc->stage($this->team, ($this->csv)("Artikelnummer;Preis\n1001;2\n"), [], $this->hanos->id);
    expect(FoodAlchemistPriceImportLine::where('run_id', $run->id)->first()->valid_from->toDateString())->toBe(now()->toDateString());

    expect(fn () => $this->svc->stage($this->team, ($this->csv)('x', 'xls'), [], $this->hanos->id))->toThrow(RuntimeException::class, '.xls');
    // Mit OpenSpout: kaputte Datei = „nicht lesen“. Ohne die Bibliothek (Sandbox ohne composer update): die Meldung nennt den Weg, statt hart zu scheitern.
    $erwartet = class_exists(\OpenSpout\Reader\XLSX\Reader::class) ? 'nicht lesen' : 'openspout/openspout';
    expect(fn () => $this->svc->stage($this->team, ($this->csv)('keine Tabelle', 'xlsx'), [], $this->hanos->id))->toThrow(RuntimeException::class, $erwartet);

    $latin = mb_convert_encoding("Lieferant;Artikelnummer;Preis\nChefs Culinar;2002;1\n", 'Windows-1252', 'UTF-8');
    $run2 = $this->svc->stage($this->team, ($this->csv)($latin), []);
    expect(($this->stati)($run2))->toBe([2 => 'treffer']);
});

it('Spaltenzuordnung: automatisch über Synonyme, explizit über Mapping; fehlende Pflichtspalten sind ein Fehler', function () {
    $run = $this->svc->stage($this->team, ($this->csv)("ArtNr;Netto-EK;Name\n1001;4,5;Mehl\n"), [], $this->hanos->id);
    expect(($this->stati)($run))->toBe([2 => 'treffer']);

    $run2 = $this->svc->stage($this->team, ($this->csv)("Code;Betrag\n1001;4,5\n"), ['article_number' => 'Code', 'price' => 'Betrag'], $this->hanos->id);
    expect(($this->stati)($run2))->toBe([2 => 'treffer']);

    expect(fn () => $this->svc->stage($this->team, ($this->csv)("Artikelnummer;Bemerkung\n1001;x\n"), [], $this->hanos->id))->toThrow(RuntimeException::class, 'Preis-Spalte');
    expect(fn () => $this->svc->stage($this->team, ($this->csv)("Artikelnummer;Preis\n1001;4\n"), []))->toThrow(RuntimeException::class, 'Lieferant');
});

it('Sichtbarkeit: stamm_paket-Artikel ohne Opt-in ist kein Treffer, mit Opt-in schon; fremde Teams sind nie sichtbar', function () {
    $paket = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->hanos->id, 'designation' => 'Paket', 'article_number' => '7007', 'stamm_class' => 'stamm_paket']);
    $fremd = ($this->la)($this->hanos, '8008', null, $this->childA);   // Artikel eines Unter-Teams (für den Mandanten Root nicht sichtbar)
    $pfad = ($this->csv)("Artikelnummer;Preis\n7007;1\n8008;2\n");

    $ohne = $this->svc->stage($this->team, $pfad, [], $this->hanos->id);
    expect(($this->stati)($ohne))->toBe([2 => 'ohne_treffer', 3 => 'ohne_treffer']);

    StammPaket::freischalten($this->team);
    $mit = $this->svc->stage($this->team, $pfad, [], $this->hanos->id);
    expect(($this->stati)($mit))->toBe([2 => 'treffer', 3 => 'ohne_treffer']);
});

it('Globale Preiszeilen bleiben unberührt; jeder Upload ist ein neuer Lauf (append-only)', function () {
    $global = FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => $this->mehl->id, 'price' => 9.99, 'status' => '0']);
    $vorher = $global->fresh()->toArray();
    $pfad = ($this->csv)("Artikelnummer;Preis\n1001;5\n");

    $a = $this->svc->stage($this->team, $pfad, [], $this->hanos->id);
    $b = $this->svc->stage($this->team, $pfad, [], $this->hanos->id);

    expect($a->id)->not->toBe($b->id)
        ->and(DB::table('foodalchemist_price_import_lines')->count())->toBe(2)
        ->and($global->fresh()->toArray())->toBe($vorher)
        ->and(FoodAlchemistPrice::count())->toBe(1);
});

it('Prüfliste als CSV und Befehl preisliste-import', function () {
    $pfad = ($this->csv)("Artikelnummer;Preis\n1001;5\n9999;7\n");
    $ziel = sys_get_temp_dir() . '/pruefliste_' . uniqid() . '.csv';

    $this->artisan('foodalchemist:preisliste-import', ['datei' => $pfad, '--team' => $this->team->id, '--supplier' => $this->hanos->id, '--pruefliste' => $ziel])
        ->expectsOutputToContain('kein Preis geschrieben')
        ->assertSuccessful();

    $inhalt = file_get_contents($ziel);
    expect($inhalt)->toContain('9999')->toContain('ohne_treffer')->not->toContain(';1001;');
    @unlink($ziel);
});
