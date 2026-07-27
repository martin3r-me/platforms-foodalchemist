<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\FileArticleImportService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 13 · S1a/S1b (Kanal B) — Datei-Reader + Artikel-Upsert + Preis-Write + E4-Kette.
 *
 * Die tragenden Regeln des Services stehen hier als Test: leere Zelle löscht nichts
 * (⇒ Idempotenz), D1 gilt auch beim Import, Mehrdeutigkeit ist ein Fehler — und für
 * S1b: nur ein bewegter Preis schreibt eine Zeile, und ein bewegter Preis MUSS bis in
 * die Rezeptkosten durchschlagen (keine stille Drift).
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

it('nennt Spalten späterer Stufen und solche ohne Ziel-Feld, statt sie still zu verschlucken', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Naehrwert kcal;Mindestbestellwert;Preis-Notiz;Lieferzeit Kommentar',
        '70012;Zanderfilet;92;250;Streichpreis;montags',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['spalten']['spaeter'])->toHaveCount(2)
        ->and(implode(' ', $bericht['spalten']['spaeter']))->toContain('S1c')
        ->and(implode(' ', $bericht['spalten']['spaeter']))->toContain('S2')
        ->and($bericht['spalten']['unbekannt'])->toBe(['Lieferzeit Kommentar'])
        ->and(implode(' ', $bericht['hinweise']))->toContain('Ohne Ziel-Feld');

    // Ohne Preis-Spalte gibt es keinen Preis-Block und keine Preis-Zeile.
    expect($bericht['befunde'][0])->not->toHaveKey('preis')
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistPrice::count())->toBe(0);
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

// ---- S1b: Preis + Post-Import-Kette (E4) ---------------------------------

it('legt beim ersten Preis eine aktive Zeile an und lässt einen unveränderten Preis in Ruhe', function () {
    $pfad = csv(['Artikel-Nr;Bezeichnung;Preis', '70012;Zanderfilet;28,90']);

    $erster = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);
    expect($erster['preise']['neu'])->toBe(1)
        ->and($erster['befunde'][0]['preis']['status'])->toBe('neu');

    $item = FoodAlchemistSupplierItem::firstWhere('article_number', '70012');
    $aktiv = app(PriceService::class)->activeFor($item->id);
    expect((float) $aktiv->price)->toBe(28.9)
        ->and($aktiv->status)->toBe('0')
        ->and($aktiv->valid_to)->toBeNull();

    // Zweiter Lauf: append-only darf keine zweite Zeile für denselben Preis anlegen.
    $zweiter = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);
    expect($zweiter['preise']['unveraendert'])->toBe(1)
        ->and($zweiter['preise']['geaendert'])->toBe(0)
        ->and(FoodAlchemistPrice::count())->toBe(1);
});

it('schließt bei geändertem Preis den Vorgänger und meldet alt → neu', function () {
    $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Preis', '70012;Zanderfilet;28,90']), apply: true);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Preis', '70012;Zanderfilet;31,50']), apply: true);

    expect($bericht['preise']['geaendert'])->toBe(1)
        ->and($bericht['befunde'][0]['preis']['alt'])->toBe(28.9)
        ->and($bericht['befunde'][0]['preis']['neu'])->toBe(31.5)
        ->and($bericht['unveraendert'])->toBe(1);   // der Artikel-Stamm selbst blieb gleich

    $item = FoodAlchemistSupplierItem::firstWhere('article_number', '70012');
    expect(FoodAlchemistPrice::where('supplier_item_id', $item->id)->count())->toBe(2)
        ->and((float) app(PriceService::class)->activeFor($item->id)->price)->toBe(31.5)
        ->and(FoodAlchemistPrice::where('supplier_item_id', $item->id)->whereNotNull('valid_to')->count())->toBe(1);
});

it('lehnt Null-, Negativ- und Unsinn-Preise als Zeilen-Befund ab, ohne den Lauf zu reißen', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Preis',
        '70012;Nullpreis;0,00',
        '70013;Service-Zuschlag;-4,50',
        '70014;Buchstabensalat;auf Anfrage',
        '70015;Sauber;12,00',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['neu'])->toBe(4)                    // alle vier Artikel entstehen
        ->and($bericht['fehler'])->toBe(0)
        ->and($bericht['preise']['fehler'])->toBe(3)
        ->and($bericht['preise']['neu'])->toBe(1)
        ->and($bericht['befunde'][0]['preis']['grund'])->toContain('Null-EK')
        ->and($bericht['befunde'][1]['preis']['grund'])->toContain('GL-11 I5')
        ->and($bericht['befunde'][2]['preis']['grund'])->toContain('keine Zahl')
        ->and(FoodAlchemistPrice::count())->toBe(1);
});

it('nimmt den Aktions-Status aus der Datei und verlangt zu einem Status auch einen Betrag', function () {
    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Preis;Preis-Status', '70012;Zanderfilet;24,90;Aktion']), apply: true);

    expect($bericht['preise']['neu'])->toBe(1);
    $item = FoodAlchemistSupplierItem::firstWhere('article_number', '70012');
    expect(app(PriceService::class)->activeFor($item->id)->status)->toBe('2');

    expect(fn () => $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Preis-Status', '70012;Zanderfilet;Aktion']), apply: true))
        ->toThrow(RuntimeException::class, 'ohne „Preis"');
});

it('E4: ein geänderter EK rechnet die nutzenden Rezepte neu — keine stille Drift', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zander');
    $item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'article_number' => '70012', 'designation' => 'Zanderfilet', 'qty' => 1, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $gp->id,
    ]);
    $gp->update(['lead_la_supplier_item_id' => $item->id]);

    $rezept = $this->makeRecipe($this->rootTeam, 'Zanderfilet gebraten');
    $this->makeIngredient($rezept, 'Zanderfilet', $gp, '1000');

    $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Preis', '70012;Zanderfilet;20,00']), apply: true);
    $ekVorher = (float) $rezept->fresh()->ek_total_eur;

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Preis', '70012;Zanderfilet;40,00']), apply: true);

    expect($bericht['kette']['gps'])->toBe(1)
        ->and($bericht['kette']['rezepte'])->toBe(1)
        ->and($bericht['kette']['neu_berechnet'])->toBe(1)
        ->and($ekVorher)->toBeGreaterThan(0.0)
        ->and((float) $rezept->fresh()->ek_total_eur)->toBeGreaterThan($ekVorher);

    // R2.1 hängt am selben Lauf: +100 % am Lead-LA muss ein Preis-Sprung-Signal auslösen.
    expect($bericht['kette']['signale'])->toBeGreaterThan(0)
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistSignal::where('type', 'preis_sprung_marge_impact')->count())->toBeGreaterThan(0);
});

it('Trockenlauf zeigt die Kette als Vorschau, rechnet aber nichts und schreibt keinen Preis', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zander');
    $item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'article_number' => '70012', 'designation' => 'Zanderfilet', 'qty' => 1, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $gp->id,
    ]);
    $rezept = $this->makeRecipe($this->rootTeam, 'Zanderfilet gebraten');
    $this->makeIngredient($rezept, 'Zanderfilet', $gp, '1000');

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Preis', '70012;Zanderfilet;28,90']));

    expect($bericht['kette']['rezepte'])->toBe(1)
        ->and($bericht['kette']['neu_berechnet'])->toBe(0)
        ->and($bericht['preise']['neu'])->toBe(1)
        ->and(FoodAlchemistPrice::count())->toBe(0);
});

it('Kette bleibt still, wenn der bewegte Artikel an keinem GP hängt', function () {
    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Preis', '70012;Zanderfilet;28,90']), apply: true);

    expect($bericht['preise']['neu'])->toBe(1)
        ->and($bericht['kette'])->toBe(['bewegt' => 1, 'gps' => 0, 'rezepte' => 0, 'neu_berechnet' => 0, 'signale' => 0, 'abgeschnitten' => false]);
});

it('D1: am geerbten Artikel wird auch kein Preis geschrieben', function () {
    $geerbt = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'article_number' => '70012', 'designation' => 'Zanderfilet (Eltern-Katalog)',
    ]);

    $bericht = $this->import->importiere($this->childA, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Preis', '70012;Zanderfilet;28,90']), apply: true);

    expect($bericht['uebersprungen'])->toBe(1)
        ->and($bericht['befunde'][0])->not->toHaveKey('preis')
        ->and(FoodAlchemistPrice::where('supplier_item_id', $geerbt->id)->count())->toBe(0);
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
