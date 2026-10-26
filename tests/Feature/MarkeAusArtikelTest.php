<?php

use Illuminate\Support\Facades\Artisan;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel;
use Platform\FoodAlchemist\Services\Marken\MarkenErgebnis;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3 (Dominique 26.10.2026): EIN Dienst bestimmt die Marke der GP-Klammer. Reihenfolge Necta-brand → Kürzel aus der
 * Bezeichnung (Matchregel wie gp_helfer/marken_kuerzel.py::resolve, Klaus) → Kurzname, wenn Hersteller → offen. Händlername nie, MSC nie.
 * Dazu der Seeder `foodalchemist:marken-einspielen` mit den Daten im Modul.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->cc = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Chefs Culinar West Weeze', 'rolle' => 'haendler']);
    $this->heisters = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Bäckerei Heisters', 'rolle' => 'hersteller', 'kurzname' => 'Heisters']);
    $this->offen = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Feinkost Sonnenschein GmbH']);   // Standard: rolle offen
    foreach ([['kn', 'Knorr'], ['oet', 'Dr. Oetker'], ['oetk', 'Dr. Oetker'], ['wib', 'Wiberg'], ['bürg', 'Bürger'], ['pomb', 'Pomberg']] as [$k, $m]) {
        FoodAlchemistSupplierMarke::create(['supplier_id' => null, 'kuerzel' => $k, 'marke' => $m, 'typ' => 'herstellermarke']);
    }
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->cc->id, 'kuerzel' => 'bc', 'marke' => 'Base Culinar', 'typ' => 'eigenmarke']);
    $this->nr = 0;
    $this->la = fn (FoodAlchemistSupplier $s, string $bez, array $extra = []) => FoodAlchemistSupplierItem::create($extra + [
        'team_id' => null, 'supplier_id' => $s->id, 'designation' => $bez, 'article_number' => 'M' . (++$this->nr), 'qty' => 1, 'unit_code' => 'kg']);
    $this->marke = fn (FoodAlchemistSupplierItem $la) => app(MarkeAusArtikel::class)->fuer($la->fresh());
});

it('Reihenfolge: Necta-brand vor Kürzel vor Kurzname; sonst offen', function () {
    $necta = ($this->marke)(($this->la)($this->heisters, 'Kn.Bratenjus 1kg', ['brand' => 'Block House']));
    $kuerzel = ($this->marke)(($this->la)($this->heisters, 'Kn.Bratenjus pastös 1kg'));
    $kurz = ($this->marke)(($this->la)($this->heisters, 'Brötchen Gouda belegt'));
    $eigen = ($this->marke)(($this->la)($this->cc, 'BC Bierschinken i.Sc.         500g'));
    $offen = ($this->marke)(($this->la)($this->cc, 'Convenience-Sauce Jäger 1l'));

    expect([$necta->marke, $necta->quelle])->toBe(['Block House', MarkenErgebnis::QUELLE_NECTA])
        ->and([$kuerzel->marke, $kuerzel->quelle, $kuerzel->roh])->toBe(['Knorr', MarkenErgebnis::QUELLE_KUERZEL, 'Kn.'])
        ->and([$kurz->marke, $kurz->quelle])->toBe(['Heisters', MarkenErgebnis::QUELLE_KURZNAME])
        ->and([$eigen->marke, $eigen->quelle])->toBe(['Base Culinar', MarkenErgebnis::QUELLE_KUERZEL])
        ->and($offen->offen())->toBeTrue()->and($offen->quelle)->toBe(MarkenErgebnis::QUELLE_OFFEN);
});

it('Händlername nie: weder aus Necta-brand noch als Kurzname; Rolle offen verhält sich wie Händler', function () {
    $this->offen->update(['kurzname' => 'Sonnenschein']);   // Kurzname zählt nur beim Hersteller

    expect(($this->marke)(($this->la)($this->cc, 'Bratensauce 1l', ['brand' => 'Chefs Culinar']))->offen())->toBeTrue()
        ->and(($this->marke)(($this->la)($this->cc, 'Bratensauce 1l', ['brand' => 'CHEFS']))->offen())->toBeTrue()
        ->and(($this->marke)(($this->la)($this->cc, 'Rinderfond 1l', ['brand' => 'Chef']))->marke)->toBe('Chef')   // Nestlé-Marke ≠ Chefs Culinar (ganze Wörter)
        ->and(($this->marke)(($this->la)($this->offen, 'Kartoffelsalat 2kg'))->offen())->toBeTrue()
        ->and(($this->marke)(($this->la)($this->offen, 'Kartoffelsalat 2kg', ['brand' => 'Feinkost Sonnenschein']))->offen())->toBeTrue()
        ->and(($this->marke)(($this->la)($this->offen, 'Kartoffelsalat 2kg', ['brand' => 'Homann']))->marke)->toBe('Homann');   // fremde Marke im Artikel zählt
});

it('Matchregel wie marken_kuerzel.py: erste drei Wörter, Kürzel allein oder vor einem Punkt (Beispiele aus __main__)', function () {
    $d = app(MarkeAusArtikel::class);
    $faelle = [
        'TK Oet.Mini Amerikaner' => 'Dr. Oetker', 'Kn.Bratenjus pastös' => 'Knorr', 'Wib.Cajun Kreol' => 'Wiberg', 'TK Bürg.Bergkäsenocken' => 'Bürger',
        'Pomb.Gurkensalat' => 'Pomberg', 'SUS Schweinerücken oKn.m.Schw' => null, 'Knorr Fond' => null, 'TK BonP.Cannelloni' => null, 'Oetk Kirschgrütze' => 'Dr. Oetker',
        'Rinder Fond dunkel Kn.' => null,            // Kürzel erst im 4. Wort
        'Bratensoße/Kn. 1kg' => 'Knorr',             // „/“ trennt Wörter
        'KNX Brühe' => null, 'Kn2 Brühe' => null,     // nur Buchstaben, allein oder vor Punkt
    ];
    foreach ($faelle as $bez => $soll) {
        expect($d->kuerzelAus($bez, null)[0] ?? null)->toBe($soll, $bez);
    }
    expect($d->kuerzelAus('BC Jagdwurst', $this->cc->id)[0] ?? null)->toBe('Base Culinar')
        ->and($d->kuerzelAus('BC Jagdwurst', $this->heisters->id))->toBeNull();   // Lieferanten-Kürzel gilt nur bei seinem Lieferanten
});

it('Händlerschutz: Necta-brand und globale bzw. Hersteller-Kürzel nie mit dem eigenen Händlernamen — kuratierte Eigenmarke des Händlers schon', function () {
    $hanos = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos Venlo', 'rolle' => 'haendler']);
    $gilde = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Gilde Frisch-Markt Rhein-Ruhr EG', 'rolle' => 'haendler']);
    $kluth = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Frischeteam Geschwister Kluth KG', 'rolle' => 'hersteller', 'kurzname' => 'Kluth']);   // Dominique 26.10.: Hersteller
    // Lücke aus dem Fund (Kurator 26.10.): ein GLOBALES Kürzel, das wie ein Händler heißt — synthetisch, Gilde bleibt in der Seeder-CSV ohne Eintrag (Gruppe U)
    FoodAlchemistSupplierMarke::create(['supplier_id' => null, 'kuerzel' => 'gilde', 'marke' => 'Gilde', 'typ' => 'herstellermarke']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => null, 'kuerzel' => 'kluth', 'marke' => 'Kluth', 'typ' => 'herstellermarke']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $hanos->id, 'kuerzel' => 'selektie', 'marke' => 'Hanos Selektie', 'typ' => 'eigenmarke', 'vorrang' => 1]);
    app(MarkeAusArtikel::class)->vergessen();

    expect(($this->marke)(($this->la)($gilde, 'Gilde Spießbraten vom Nacken'))->offen())->toBeTrue()                           // globales Kürzel = eigener Händlername
        ->and(($this->marke)(($this->la)($gilde, 'Spießbraten', ['brand' => 'Gilde']))->offen())->toBeTrue()                 // Necta-brand = eigener Name
        ->and(($this->marke)(($this->la)($this->cc, 'Gilde Bratensauce 1l'))->marke)->toBe('Gilde')                           // fremder Händler: Marke
        ->and(($this->marke)(($this->la)($this->cc, 'Kluth Cashewkerne 1kg'))->marke)->toBe('Kluth')
        ->and(($this->marke)(($this->la)($kluth, 'Kluth Möhren geschält'))->marke)->toBe('Kluth')                             // Kluth ist jetzt Hersteller: Kürzel
        ->and(($this->marke)(($this->la)($kluth, 'Möhren geschält'))->marke)->toBe('Kluth')                                   // … sonst Kurzname
        ->and(($this->marke)(($this->la)($hanos, 'SALAMI HANOS SELEKTIE'))->marke)->toBe('Hanos Selektie')                    // kuratierte Eigenmarke
        ->and(($this->marke)(($this->la)($hanos, 'Erdnüsse scharf', ['brand' => 'Hanos Selektie']))->marke)->toBe('Hanos Selektie')
        ->and(($this->marke)(($this->la)($hanos, 'Rauwkost Coleslaw Luxe 1kg', ['brand' => 'HANOS']))->offen())->toBeTrue(); // „HANOS“ ohne kuratierten Eintrag
});

it('Hanos: Marke „HANOS“ (Necta oder Wort in der Bezeichnung) → „Hanos Selektie“ in der Klammer (Dominique 26.10.)', function () {
    $hanos = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos Venlo', 'rolle' => 'haendler']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $hanos->id, 'kuerzel' => 'hanos', 'marke' => 'Hanos Selektie', 'typ' => 'eigenmarke']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $hanos->id, 'kuerzel' => 'selektie', 'marke' => 'Hanos Selektie', 'typ' => 'eigenmarke', 'vorrang' => 1]);
    app(MarkeAusArtikel::class)->vergessen();

    $necta = ($this->marke)(($this->la)($hanos, 'Rauwkost Coleslaw Luxe 1kg', ['brand' => 'HANOS']));
    expect([$necta->marke, $necta->quelle, $necta->roh])->toBe(['Hanos Selektie', 'necta_brand', 'HANOS'])
        ->and(($this->marke)(($this->la)($hanos, 'HANOS WILDSAUCE'))->marke)->toBe('Hanos Selektie')
        ->and(($this->marke)(($this->la)($hanos, 'SALAMI HANOS SELEKTIE'))->marke)->toBe('Hanos Selektie')
        ->and(($this->marke)(($this->la)($this->cc, 'Coleslaw 1kg', ['brand' => 'HANOS']))->marke)->toBe('HANOS');   // bei anderem Händler: Necta wörtlich (Eintrag gilt nur für Hanos)
});

it('MSC ist eine Zertifizierung: nie Marke, weder als Kürzel noch aus Necta-brand', function () {
    FoodAlchemistSupplierMarke::create(['supplier_id' => null, 'kuerzel' => 'msc', 'marke' => 'MSC', 'typ' => 'herstellermarke']);   // Altlast in der Tabelle
    app(MarkeAusArtikel::class)->vergessen();

    expect(($this->marke)(($this->la)($this->cc, 'MSC Kutter-Frikadelle 75g'))->offen())->toBeTrue()
        ->and(($this->marke)(($this->la)($this->cc, 'Fischstäbchen', ['brand' => 'MSC']))->offen())->toBeTrue();
});

it('Seeder: Probelauf schreibt nichts, --apply spielt Rollen und Kürzel ein, zweiter Lauf ändert nichts; Fehltreffer werden gelistet', function () {
    FoodAlchemistSupplierMarke::query()->delete();
    $this->heisters->update(['rolle' => 'offen', 'kurzname' => null]);
    $this->cc->update(['rolle' => 'offen']);

    expect(Artisan::call('foodalchemist:marken-einspielen'))->toBe(0)
        ->and(FoodAlchemistSupplierMarke::count())->toBe(0)->and($this->heisters->fresh()->rolle->value)->toBe('offen');

    Artisan::call('foodalchemist:marken-einspielen', ['--apply' => true]);
    $ausgabe = Artisan::output();
    expect($this->heisters->fresh()->rolle->value)->toBe('hersteller')->and($this->heisters->fresh()->kurzname)->toBe('Heisters')
        ->and($this->cc->fresh()->rolle->value)->toBe('haendler')->and($this->cc->fresh()->kurzname)->toBeNull()
        ->and(FoodAlchemistSupplierMarke::whereNull('supplier_id')->pluck('marke', 'kuerzel')->all())
            ->toMatchArray(['kn' => 'Knorr', 'oet' => 'Dr. Oetker', 'oetk' => 'Dr. Oetker', 'wib' => 'Wiberg', 'bürg' => 'Bürger', 'pomb' => 'Pomberg'])
        ->and(FoodAlchemistSupplierMarke::where('supplier_id', $this->cc->id)->pluck('marke', 'kuerzel')->all())
            ->toMatchArray(['bc' => 'Base Culinar', 'sc' => 'Star Culinar', 'mm' => 'Müllers Mühle', 'cart' => "Carte d'Or"])
        ->and(FoodAlchemistSupplierMarke::where('kuerzel', 'msc')->exists())->toBeFalse()
        ->and($ausgabe)->toContain('BOS Food GmbH');   // im Test nicht angelegt → gelistet, nie geraten

    $anzahl = FoodAlchemistSupplierMarke::count();
    Artisan::call('foodalchemist:marken-einspielen', ['--apply' => true]);
    expect(FoodAlchemistSupplierMarke::count())->toBe($anzahl)->and(Artisan::output())->toContain('0 Änderungen geschrieben');
});

it('Seeder weist Sperrwörter ab und nimmt neue Zeilen aus einem anderen Ordner auf (erweiterbar)', function () {
    $ordner = sys_get_temp_dir() . '/marken_' . bin2hex(random_bytes(4));
    mkdir($ordner);
    file_put_contents($ordner . '/lieferanten_rolle.csv', "lieferant;rolle;kurzname\nBäckerei Heisters;hersteller;Heisters\n");
    file_put_contents($ordner . '/kuerzel.csv', "lieferant;kuerzel;marke;typ\n;msc;MSC;herstellermarke\nChefs Culinar West Weeze;Peka.;Peka;herstellermarke\n");

    Artisan::call('foodalchemist:marken-einspielen', ['--pfad' => $ordner, '--apply' => true]);

    expect(Artisan::output())->toContain('Sperrwort')
        ->and(FoodAlchemistSupplierMarke::where('kuerzel', 'msc')->exists())->toBeFalse()
        ->and(FoodAlchemistSupplierMarke::where('supplier_id', $this->cc->id)->where('kuerzel', 'peka')->value('marke'))->toBe('Peka');
    array_map('unlink', glob($ordner . '/*'));
    rmdir($ordner);
});

it('Runde 2 aus der Modul-CSV: Beispiele aus marken_kuerzel.py (__main__) 1:1 — HT vor SC, FF vor Bindestrich, Handelshof am Ende, Lieferanten-Scope', function () {
    FoodAlchemistSupplierMarke::query()->delete();
    $l = [];
    foreach (['Edna Backwaren GmbH', 'Handelshof Mönchengladbach', 'Hanos Venlo', 'Delta Fleisch Handels GmbH'] as $name) {
        $l[$name] = FoodAlchemistSupplier::create(['team_id' => null, 'name' => $name])->id;
    }
    Artisan::call('foodalchemist:marken-einspielen', ['--apply' => true]);
    $d = app(MarkeAusArtikel::class);
    $d->vergessen();
    $cc = $this->cc->id;
    $faelle = [
        ['TK SC HT Rindergulasch 2x1,5kg', $cc, 'Hi Taste'], ['TK HT Bami Goreng', $cc, 'Hi Taste'], ['SC Lachsfleisch ohne Kette', $cc, 'Star Culinar'],
        ['Kn.Bratenjus', $cc, 'Knorr'], ['TK BonP.Cannelloni', $cc, 'Bon Pastaio'], ['FF-Laugenfußballbrötchen 0,07 kg', $l['Edna Backwaren GmbH'], 'Fix und fertig'],
        ['MARINADE XY HENG.', $l['Handelshof Mönchengladbach'], 'Henglein'], ['KETCHUP 5KG OE', $l['Handelshof Mönchengladbach'], 'Dr. Oetker'], ['KETCHUP OE', $l['Hanos Venlo'], null],
        ['SUS Schweinerücken oKn.m.Schw4,5kg', $cc, null], ['Ma.Tomatenketchup', null, 'Maggi'], ['DC Lachs Lasagne', $l['Delta Fleisch Handels GmbH'], null], ['TK DC Lachs', $cc, 'Danish Crown'],
        ['Ma-Würze flüssig', null, null],   // Bindestrich zählt nur bei FF
        ['TK Block Wildschweingulasch   250g', $cc, 'Block Menü'], ['Block Sauce Bolognese 2kg', $cc, 'Block Menü'],   // Dominique 26.10.: Block = Block Menü
        ['SOJASAUCE DUNKEL 500ML LEE', $l['Handelshof Mönchengladbach'], 'Lee Kum Kee'],                                   // Handelshof-Suffix
    ];
    foreach ($faelle as [$bez, $sid, $soll]) {
        expect($d->kuerzelAus($bez, $sid)[0] ?? null)->toBe($soll, $bez);
    }
});
