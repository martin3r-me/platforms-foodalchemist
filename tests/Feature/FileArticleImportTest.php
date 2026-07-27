<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\FileArticleImportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 13 · S1a (Kanal B) — Datei-Reader + Artikel-Upsert.
 *
 * Die drei tragenden Regeln des Services stehen hier als Test: leere Zelle löscht
 * nichts (⇒ Idempotenz), D1 gilt auch beim Import, Mehrdeutigkeit ist ein Fehler.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->import = app(FileArticleImportService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/fa_kanalb_*') ?: [] as $pfad) {
        @unlink($pfad);
    }
});

/** Schreibt eine CSV in den Temp-Ordner und gibt den Pfad zurück (Aufräumen per afterEach-Glob). */
function csv(array $zeilen, string $endung = 'csv'): string
{
    $pfad = sys_get_temp_dir() . '/fa_kanalb_' . uniqid('', true) . '.' . $endung;
    file_put_contents($pfad, implode("\n", $zeilen) . "\n");

    return $pfad;
}

it('legt einen neuen Artikel an und mappt deutsche Header, Komma-Zahlen und ja/nein', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Marke;Gebindemenge;Einheit;MwSt;Bio;Vorbestelltage',
        '70012;Zanderfilet mit Haut;Nordsee Select;2,5;Kilogramm;7,00;ja;3',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['neu'])->toBe(1)->and($bericht['fehler'])->toBe(0);

    $item = FoodAlchemistSupplierItem::firstWhere('article_number', '70012');
    expect($item)->not->toBeNull()
        ->and($item->team_id)->toBe($this->rootTeam->id)
        ->and($item->supplier_id)->toBe($this->supplier->id)
        ->and($item->designation)->toBe('Zanderfilet mit Haut')
        ->and($item->brand)->toBe('Nordsee Select')
        ->and((float) $item->qty)->toBe(2.5)
        ->and($item->unit_code)->toBe('kg')          // „Kilogramm" → kanonisch
        ->and((float) $item->vat)->toBe(7.0)
        ->and($item->is_organic)->toBeTrue()
        ->and($item->preorder_days)->toBe(3);
});

it('ist idempotent: derselbe Lauf schreibt beim zweiten Mal nichts', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Gebindemenge;Einheit',
        '70012;Zanderfilet;2,500;kg',
    ]);

    $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);
    $zweiter = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($zweiter['neu'])->toBe(0)
        ->and($zweiter['aktualisiert'])->toBe(0)
        ->and($zweiter['unveraendert'])->toBe(1)
        ->and(FoodAlchemistSupplierItem::count())->toBe(1);
});

it('aktualisiert nur die Spalten, die die Datei mitbringt — eine fehlende Spalte löscht nichts', function () {
    $item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'article_number' => '70012', 'designation' => 'Zanderfilet alt',
        'brand' => 'Hausmarke', 'qty' => 1.0, 'unit_code' => 'kg',
    ]);

    // Datei kennt Marke und Gebindemenge gar nicht, Herkunftsland ist leer.
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Herkunftsland',
        '70012;Zanderfilet mit Haut;',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['aktualisiert'])->toBe(1)
        ->and($bericht['befunde'][0]['felder'])->toBe(['designation']);

    $item->refresh();
    expect($item->designation)->toBe('Zanderfilet mit Haut')
        ->and($item->brand)->toBe('Hausmarke')       // Spalte fehlt in der Datei
        ->and((float) $item->qty)->toBe(1.0)
        ->and($item->origin_country)->toBeNull();    // leere Zelle löscht nichts
});

it('findet über die EAN, wenn die Artikelnummer nichts trifft (Lieferant hat neu nummeriert)', function () {
    $item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'article_number' => 'ALT-1', 'designation' => 'Zanderfilet', 'ean_packaging' => '4012345678901',
    ]);

    $pfad = csv([
        'Artikel-Nr;Bezeichnung;EAN Gebinde',
        'NEU-9;Zanderfilet mit Haut;4012345678901',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['aktualisiert'])->toBe(1)
        ->and($bericht['befunde'][0]['match'])->toBe('ean')
        ->and(FoodAlchemistSupplierItem::count())->toBe(1);

    expect($item->refresh()->article_number)->toBe('NEU-9');
});

it('lehnt eine Zeile ab, deren Artikelnummer mehrere Bestandsartikel trifft', function () {
    foreach (['a', 'b'] as $i) {
        FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'article_number' => '70012', 'designation' => 'Dublette ' . $i,
        ]);
    }

    $pfad = csv(['Artikel-Nr;Bezeichnung', '70012;Zanderfilet']);
    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['fehler'])->toBe(1)
        ->and($bericht['befunde'][0]['grund'])->toContain('trifft mehrere')
        ->and(FoodAlchemistSupplierItem::count())->toBe(2);
});

it('D1: ein geerbter Artikel des Eltern-Teams wird übersprungen, nicht verändert und nicht kopiert', function () {
    $geerbt = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'article_number' => '70012', 'designation' => 'Zanderfilet (Eltern-Katalog)',
    ]);

    $pfad = csv(['Artikel-Nr;Bezeichnung', '70012;Zanderfilet Kind-Version']);
    $bericht = $this->import->importiere($this->childA, $this->supplier->id, $pfad, apply: true);

    expect($bericht['uebersprungen'])->toBe(1)
        ->and($bericht['neu'])->toBe(0)
        ->and($bericht['befunde'][0]['grund'])->toContain('D1')
        ->and($geerbt->refresh()->designation)->toBe('Zanderfilet (Eltern-Katalog)')
        ->and(FoodAlchemistSupplierItem::count())->toBe(1);
});

it('Trockenlauf berichtet vollständig, schreibt aber nichts', function () {
    $pfad = csv(['Artikel-Nr;Bezeichnung', '70012;Zanderfilet']);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad);

    expect($bericht['apply'])->toBeFalse()
        ->and($bericht['neu'])->toBe(1)
        ->and(FoodAlchemistSupplierItem::count())->toBe(0);
});

it('nennt die Preis-Spalte als „spätere Stufe" statt sie still zu verschlucken', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Preis;Naehrwert kcal;Mindestbestellwert;Lieferzeit Kommentar',
        '70012;Zanderfilet;28,90;92;250;montags',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['spalten']['spaeter'])->toHaveCount(3)
        ->and(implode(' ', $bericht['spalten']['spaeter']))->toContain('S1b')
        ->and(implode(' ', $bericht['spalten']['spaeter']))->toContain('S1c')
        ->and(implode(' ', $bericht['spalten']['spaeter']))->toContain('S2')
        ->and($bericht['spalten']['unbekannt'])->toBe(['Lieferzeit Kommentar'])
        ->and($bericht['hinweise'])->toHaveCount(2);

    // Preis wird wirklich nicht geschrieben (das ist S1b).
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistPrice::count())->toBe(0);
});

it('warnt bei unbrauchbarer Einheit und lässt das Feld leer, statt die Zeile zu verlieren', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Einheit;Gebindemenge',
        '70015;Cornichons;Glas;1,7',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['neu'])->toBe(1)
        ->and($bericht['befunde'][0]['warnungen'])->toHaveCount(1)
        ->and($bericht['befunde'][0]['warnungen'][0])->toContain('Kalkulationseinheit');

    $item = FoodAlchemistSupplierItem::firstWhere('article_number', '70015');
    expect($item->unit_code)->toBeNull()->and((float) $item->qty)->toBe(1.7);
});

it('bricht vor dem ersten Schreibzugriff ab, wenn Pflicht-Spalten fehlen', function () {
    $ohneBezeichnung = csv(['Artikel-Nr;Marke', '70012;Nordsee']);
    expect(fn () => $this->import->importiere($this->rootTeam, $this->supplier->id, $ohneBezeichnung, apply: true))
        ->toThrow(RuntimeException::class, 'Bezeichnung');

    $ohneSchluessel = csv(['Bezeichnung;Marke', 'Zanderfilet;Nordsee']);
    expect(fn () => $this->import->importiere($this->rootTeam, $this->supplier->id, $ohneSchluessel, apply: true))
        ->toThrow(RuntimeException::class, 'Schlüssel');

    expect(FoodAlchemistSupplierItem::count())->toBe(0);
});

it('weist xlsx mit dem Weg zurück statt zu scheitern, und meldet unsichtbare Lieferanten', function () {
    $xlsx = csv(['egal'], 'xlsx');
    expect(fn () => $this->import->importiere($this->rootTeam, $this->supplier->id, $xlsx, apply: true))
        ->toThrow(RuntimeException::class, 'als CSV');

    // Lieferant des Kind-Teams ist für das Geschwister-Team nicht sichtbar (D1).
    $fremd = FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Nur Kind A']);
    $pfad = csv(['Artikel-Nr;Bezeichnung', '1;X']);
    expect(fn () => $this->import->importiere($this->childB, $fremd->id, $pfad, apply: true))
        ->toThrow(RuntimeException::class, 'Team-Kette');
});

it('Command: Trockenlauf ist Default, --apply schreibt und hinterlässt einen ingest-Lauf', function () {
    $pfad = csv(['Artikel-Nr;Bezeichnung', '70012;Zanderfilet']);

    $this->artisan('foodalchemist:import-articles', [
        '--file' => $pfad, '--supplier' => $this->supplier->id, '--team' => $this->rootTeam->id,
    ])->assertSuccessful();
    expect(FoodAlchemistSupplierItem::count())->toBe(0);

    $this->artisan('foodalchemist:import-articles', [
        '--file' => $pfad, '--supplier' => $this->supplier->id, '--team' => $this->rootTeam->id, '--apply' => true,
    ])->assertSuccessful();

    expect(FoodAlchemistSupplierItem::count())->toBe(1);

    $lauf = \Illuminate\Support\Facades\DB::table('foodalchemist_bulk_runs')->where('type', 'ingest')->first();
    expect($lauf)->not->toBeNull()
        ->and($lauf->status)->toBe('done')
        ->and((int) $lauf->done)->toBe(1)
        ->and((int) $lauf->failed)->toBe(0);
});
