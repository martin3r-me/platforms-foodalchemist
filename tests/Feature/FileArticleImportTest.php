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

it('nennt Spalten ohne Ziel-Feld und unbekannte Spalten, statt sie still zu verschlucken', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Preis-Notiz;Lieferzeit Kommentar',
        '70012;Zanderfilet;Streichpreis;montags',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    // Seit S2 ist die Vorlage vollständig — es gibt keine „spätere Stufe" mehr.
    expect($bericht['spalten']['spaeter'])->toBe([])
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

// ---- S1c: Nährwerte / Allergene / Zusatzstoffe ---------------------------

it('schreibt alle drei Detail-Blöcke aus einer Zeile', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Nährwert kcal;Nährwert Eiweiß;Nährwert Salz;Allergen Milch;Allergen Gluten;Allergen Sellerie;Zusatzstoff Farbstoff;Zusatzstoff koffeinhaltig',
        '70012;Sahnesauce;188;2,4;1,2;ja;Spuren;nein;nein;ja',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['neu'])->toBe(1)
        ->and($bericht['details'])->toBe(['naehrwerte' => 1, 'allergene' => 1, 'zusatzstoffe' => 1]);

    $item = FoodAlchemistSupplierItem::firstWhere('article_number', '70012');
    $svc = app(\Platform\FoodAlchemist\Services\SupplierItemService::class);

    expect((float) $item->nutritionals->energy_kcal)->toBe(188.0)
        ->and((float) $item->nutritionals->protein)->toBe(2.4)
        // Salz (g) → Natrium (mg): GL-08 rückwärts, 1,2 g × 400 = 480 mg ⇒ salzG() = 1,2
        ->and((float) $item->nutritionals->sodium)->toBe(480.0)
        ->and(round((float) $item->nutritionals->salzG(), 4))->toBe(1.2);

    $allergene = $svc->getAllergens($item->refresh());
    expect($allergene['milk'])->toBe('enthalten')
        ->and($allergene['gluten'])->toBe('spuren')
        ->and($allergene['celery'])->toBe('nicht_enthalten')
        ->and($allergene['fish'])->toBe('unbekannt')      // nicht in der Datei ⇒ unberührt
        ->and($item->allergens->source)->toBe('datei');   // Lineage: NICHT „manual"

    $deklarationen = $svc->getDeclarations($item);
    expect($deklarationen['with_dye'])->toBe('nein')
        ->and($deklarationen['caffeinated'])->toBe('ja')
        ->and($deklarationen['waxed'])->toBe('unbekannt');
});

it('mischt statt zu ersetzen: eine Teil-Spalte löscht die gepflegten Nachbarwerte nicht', function () {
    $item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'article_number' => '70012', 'designation' => 'Sahnesauce',
    ]);
    $svc = app(\Platform\FoodAlchemist\Services\SupplierItemService::class);
    $svc->setAllergens($this->rootTeam, $item, ['milk' => 'enthalten', 'celery' => 'spuren']);
    $svc->setNutrition($this->rootTeam, $item, ['energy_kcal' => '188', 'protein' => '2,4']);

    // Die Datei kennt NUR Gluten und Fett.
    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Allergen Gluten;Nährwert Fett', '70012;Sahnesauce;ja;18,5']), apply: true);

    expect($bericht['befunde'][0]['details']['allergene'])->toBe(['gluten'])
        ->and($bericht['befunde'][0]['details']['naehrwerte'])->toBe(['fat']);

    $allergene = $svc->getAllergens($item->refresh());
    expect($allergene['gluten'])->toBe('enthalten')
        ->and($allergene['milk'])->toBe('enthalten')     // gepflegt, nicht in der Datei
        ->and($allergene['celery'])->toBe('spuren');

    expect((float) $item->nutritionals->fat)->toBe(18.5)
        ->and((float) $item->nutritionals->energy_kcal)->toBe(188.0);
});

it('ist auch bei den Detail-Blöcken idempotent und meldet Unlesbares als Warnung', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Nährwert kcal;Allergen Milch;Zusatzstoff Phosphat',
        '70012;Sahnesauce;188;ja;ja',
    ]);
    $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    $zweiter = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);
    expect($zweiter['details'])->toBe(['naehrwerte' => 0, 'allergene' => 0, 'zusatzstoffe' => 0])
        ->and($zweiter['unveraendert'])->toBe(1);

    // Unlesbare Werte sind Warnungen, keine Zeilen-Fehler (anders als beim Preis):
    // der Wert bleibt „unbekannt" und damit nach GL-01 auf der konservativen Seite.
    $krumm = $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Allergen Milch;Nährwert kcal', '70012;Sahnesauce;vielleicht;viel']), apply: true);

    expect($krumm['fehler'])->toBe(0)
        ->and($krumm['details'])->toBe(['naehrwerte' => 0, 'allergene' => 0, 'zusatzstoffe' => 0])
        ->and(implode(' ', $krumm['befunde'][0]['warnungen']))->toContain('Allergen milk')
        ->and(implode(' ', $krumm['befunde'][0]['warnungen']))->toContain('Nährwert energy_kcal');

    $item = FoodAlchemistSupplierItem::firstWhere('article_number', '70012');
    expect(app(\Platform\FoodAlchemist\Services\SupplierItemService::class)->getAllergens($item)['milk'])->toBe('enthalten');
});

it('meldet eine Detail-Spalte ohne Ziel-Feld, statt sie als Tippfehler zu behandeln', function () {
    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Nährwert Ballaststoffe;Allergen Histamin', '70012;Sahnesauce;3;ja']), apply: true);

    expect($bericht['spalten']['unbekannt'])->toBe([])
        ->and(implode(' ', $bericht['hinweise']))->toContain('Nährwert-Spalte ohne Ziel')
        ->and(implode(' ', $bericht['hinweise']))->toContain('Allergen-Spalte ohne Ziel')
        ->and($bericht['details'])->toBe(['naehrwerte' => 0, 'allergene' => 0, 'zusatzstoffe' => 0]);
});

it('E4: ein neues Allergen rechnet die nutzenden Rezepte neu — keine stille Drift', function () {
    $gp = $this->makeGp($this->rootTeam, 'Sahne');
    $item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
        'article_number' => '70012', 'designation' => 'Schlagsahne', 'qty' => 1, 'unit_code' => 'l',
    ]);
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $gp->id,
    ]);
    $rezept = $this->makeRecipe($this->rootTeam, 'Rahmsauce');
    $this->makeIngredient($rezept, 'Schlagsahne', $gp, '500');

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id,
        csv(['Artikel-Nr;Bezeichnung;Allergen Milch', '70012;Schlagsahne;ja']), apply: true);

    expect($bericht['details']['allergene'])->toBe(1)
        ->and($bericht['kette']['rezepte'])->toBe(1)
        ->and($bericht['kette']['neu_berechnet'])->toBe(1)
        ->and($rezept->fresh()->allergen_milk)->toBe('enthalten');
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
        ->and((int) $lauf->total)->toBe(1)          // S3a: Zeilenzahl wird beim Beenden nachgetragen (der Reader kennt sie erst dann)
        ->and((int) $lauf->done)->toBe(1)
        ->and((int) $lauf->failed)->toBe(0);
});

// ---- S2: Lieferbedingungen (E3) ----------------------------------------------
//
// Die Konditionen gelten dem LIEFERANTEN, nicht der Zeile — in einer Artikel-Datei
// stehen sie deshalb zwangsläufig n-mal da. Gelesen wird die ganze Datei, geschrieben
// genau einmal; ein Widerspruch zwischen zwei Zeilen wird abgelehnt statt geraten.

it('schreibt die Lieferbedingungen einmal an den Lieferanten, obwohl sie in jeder Zeile stehen', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Mindestbestellwert;Frei Haus ab;Zahlungsziel;Rückvergütung',
        '70012;Zanderfilet;250,00;500;30 Tage;3,5 %',
        '70013;Petersilienöl;250,00;500;30 Tage;3,5 %',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['konditionen']['status'])->toBe('geschrieben')
        ->and($bericht['konditionen']['abgelehnt'])->toBe([])
        ->and($bericht['konditionen']['gesetzt'])->toHaveCount(4)
        ->and($bericht['neu'])->toBe(2);

    $s = $this->supplier->fresh();
    expect((float) $s->min_order_value)->toBe(250.0)
        ->and((float) $s->free_shipping_threshold)->toBe(500.0)
        ->and($s->payment_term_days)->toBe(30)
        ->and((float) $s->rebate_pct)->toBe(3.5);
});

it('ist auch bei den Konditionen idempotent: der zweite Lauf ändert nichts', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Zahlungsziel',
        '70012;Zanderfilet;30',
    ]);

    $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);
    $zweiter = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($zweiter['konditionen']['status'])->toBe('unveraendert')
        ->and($zweiter['konditionen']['gesetzt'])->toBe([])
        ->and($zweiter['konditionen']['unveraendert'])->toBe(['Zahlungsziel']);
});

it('lehnt eine widersprüchliche Kondition ab, ohne die übrigen mitzureißen', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Zahlungsziel;Mindestbestellwert',
        '70012;Zanderfilet;30;250',
        '70013;Petersilienöl;60;250',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['konditionen']['status'])->toBe('teilweise')
        ->and($bericht['konditionen']['abgelehnt'])->toHaveCount(1)
        ->and($bericht['konditionen']['abgelehnt'][0])->toContain('Zahlungsziel')
        ->and(array_keys($bericht['konditionen']['gesetzt']))->toBe(['Mindestbestellwert']);

    $s = $this->supplier->fresh();
    expect($s->payment_term_days)->toBeNull()          // der Widerspruch bleibt ungeschrieben
        ->and((float) $s->min_order_value)->toBe(250.0); // die eindeutige Kondition steht
});

it('lehnt unplausible Konditions-Werte ab (Bonus > 100 %, Skonto-Text im Zahlungsziel)', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Rückvergütung;Zahlungsziel',
        '70012;Zanderfilet;150;30/2 %',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['konditionen']['status'])->toBe('fehler')
        ->and($bericht['konditionen']['abgelehnt'])->toHaveCount(2)
        ->and($bericht['konditionen']['gesetzt'])->toBe([]);

    $s = $this->supplier->fresh();
    expect($s->rebate_pct)->toBeNull()->and($s->payment_term_days)->toBeNull();
});

it('D1: Konditionen eines geerbten Lieferanten werden übersprungen, nicht überschrieben', function () {
    $this->supplier->update(['payment_term_days' => 14]);
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Zahlungsziel',
        '70012;Zanderfilet;30',
    ]);

    // childA sieht den Root-Lieferanten, besitzt ihn aber nicht.
    $bericht = $this->import->importiere($this->childA, $this->supplier->id, $pfad, apply: true);

    expect($bericht['konditionen']['status'])->toBe('uebersprungen')
        ->and($bericht['konditionen']['grund'])->toContain('D1')
        ->and($this->supplier->fresh()->payment_term_days)->toBe(14);
});

it('Trockenlauf zeigt die Konditionen an, schreibt sie aber nicht', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Mindestbestellwert',
        '70012;Zanderfilet;250',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad);

    expect($bericht['konditionen']['status'])->toBe('geschrieben')   // „so käme es an"
        ->and($bericht['konditionen']['gesetzt'])->toBe(['Mindestbestellwert' => 250.0])
        ->and($this->supplier->fresh()->min_order_value)->toBeNull();
});

it('Command: rendert den Konditions-Block und meldet einen Widerspruch als Fehlschlag', function () {
    $ok = csv([
        'Artikel-Nr;Bezeichnung;Zahlungsziel;Rückvergütung',
        '70012;Zanderfilet;30 Tage;3,5 %',
    ]);

    $this->artisan('foodalchemist:import-articles', [
        '--file' => $ok, '--supplier' => $this->supplier->id, '--team' => $this->rootTeam->id, '--apply' => true,
    ])->expectsOutputToContain('Lieferbedingungen gesetzt')->assertSuccessful();

    expect($this->supplier->fresh()->payment_term_days)->toBe(30);

    // Widerspruch: der Wert kommt nicht an ⇒ Exit-Code FAILURE (wie beim Preis-Fehler).
    $streit = csv([
        'Artikel-Nr;Bezeichnung;Mindestbestellwert',
        '70012;Zanderfilet;250',
        '70013;Petersilienöl;400',
    ]);

    $this->artisan('foodalchemist:import-articles', [
        '--file' => $streit, '--supplier' => $this->supplier->id, '--team' => $this->rootTeam->id, '--apply' => true,
    ])->assertFailed();

    expect($this->supplier->fresh()->min_order_value)->toBeNull();
});

it('verwirft eine Kondition auch dann, wenn erst eine spätere Zeile unlesbar ist (reihenfolge-unabhängig)', function () {
    $pfad = csv([
        'Artikel-Nr;Bezeichnung;Zahlungsziel',
        '70012;Zanderfilet;30',
        '70013;Petersilienöl;siehe Rahmenvertrag',
    ]);

    $bericht = $this->import->importiere($this->rootTeam, $this->supplier->id, $pfad, apply: true);

    expect($bericht['konditionen']['status'])->toBe('fehler')
        ->and($bericht['konditionen']['abgelehnt'][0])->toContain('Zeile 3')
        ->and($this->supplier->fresh()->payment_term_days)->toBeNull();
});
