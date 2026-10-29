<?php

use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\Stammdaten\BasisNaehrwertPruefer;
use Platform\FoodAlchemist\Services\Stammdaten\NaehrwertPruefer;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenImportService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Stammdaten-Import Stufe 1a (Kurator 29.10.2026): Vorlage + Einlesen + Prüfliste. Nichts geht in den Bestand.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
    $this->svc = app(StammdatenImportService::class);
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    $this->chefs = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Chefs Culinar']);
    $this->mehl = FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'designation' => 'Mehl', 'article_number' => '1001', 'ean_packaging' => '4000000000017']);

    $this->csv = function (string $inhalt): string {
        $pfad = tempnam(sys_get_temp_dir(), 'sd') . '.csv';
        file_put_contents($pfad, $inhalt);

        return $pfad;
    };
    $this->aktionen = fn (Lauf $l) => Zeile::where('run_id', $l->id)->orderBy('row_nr')->pluck('aktion', 'row_nr')->all();
    $this->bestand = fn () => [FoodAlchemistSupplierItem::count(), FoodAlchemistPrice::count(), FoodAlchemistItemAllergen::count()];
});

it('Roundtrip: die Vorlage wird vollständig erkannt — jede Spalte, ohne Nachzuordnung', function () {
    $pfad = ($this->csv)(str_replace(['Beispiel-Lieferant', '4000000000017'], ['Hanos', '4999999999999'], $this->svc->vorlageCsv()));
    $lauf = $this->svc->stage($this->team, $pfad);

    $hinweise = (array) ($lauf->counts['hinweise'] ?? []);
    expect($hinweise)->toBe([])                                  // nichts „ignoriert“, „ohne Ziel“ oder „später“
        ->and($lauf->art)->toBe('stammdaten')
        ->and($lauf->counts['zeilen'])->toBe(1);

    $z = Zeile::where('run_id', $lauf->id)->first();
    // 14 Allergene, 18 Zusatzstoffe, Nährwerte, Eigene — alles in `daten`
    expect(collect($z->daten)->keys()->filter(fn ($k) => str_starts_with($k, 'al:'))->count())->toBe(14)
        ->and(collect($z->daten)->keys()->filter(fn ($k) => str_starts_with($k, 'dk:') && $z->daten[$k] !== '')->count())->toBe(0)   // Beispiel lässt Zusatzstoffe leer
        ->and($z->daten['x:haltbar_trocken'])->toBe('365')
        ->and($z->valid_from->toDateString())->toBe('2026-11-01')
        ->and($z->aktion)->toBe('neu');                           // Beispiel-Artikel A-4711 gibt es nicht
});

it('alle 18 Zusatzstoff-Überschriften der Vorlage werden einzeln erkannt', function () {
    $kopf = array_column(StammdatenImportService::VORLAGE, 0);
    $zeilen = collect($kopf)->map(fn ($k) => str_starts_with($k, 'Zusatzstoff') ? 'ja' : '')->all();
    $csv = implode(';', $kopf) . "\n" . implode(';', array_map(fn ($k, $v) => match ($k) {
        'Lieferant' => 'Hanos', 'Artikelnummer' => 'ZZ1', 'Bezeichnung' => 'Test', default => $v,
    }, $kopf, $zeilen)) . "\n";
    $lauf = $this->svc->stage($this->team, ($this->csv)($csv));

    expect(collect(Zeile::where('run_id', $lauf->id)->first()->daten)->keys()->filter(fn ($k) => str_starts_with($k, 'dk:'))->count())->toBe(18);
});

it('schreibt nichts in den Bestand: weder Artikel noch Preis noch Allergene', function () {
    $vorher = ($this->bestand)();
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung;Nettopreis;Allergen Milch\nHanos;NEU1;Butter;9,90;enthalten\nHanos;1001;Mehl Neu;1,00;nein\n"));

    expect(($this->bestand)())->toBe($vorher)
        ->and($lauf->counts['neu'])->toBe(1)
        ->and($lauf->counts['aendert'])->toBe(1);
});

it('Aktionen: neu | ändert | unverändert | Fehler; ein bekannter Artikel ohne Änderung bleibt unverändert', function () {
    $this->mehl->update(['brand' => 'Mühle']);
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;EAN Gebinde;Bezeichnung;Marke\n"
        . "Hanos;NEU1;;Butter;X\n"            // neu
        . "Hanos;1001;;Mehl;Andere Marke\n"   // ändert
        . "Hanos;1001;;Mehl;Mühle\n"          // unverändert (zweite Zeile derselben Artikelnummer, gleiche Werte)
        . "Hanos;;;Ohne Schlüssel;X\n"));     // Fehler: weder Artikelnummer noch EAN

    expect(($this->aktionen)($lauf))->toBe([2 => 'neu', 3 => 'aendert', 4 => 'unveraendert', 5 => 'fehler']);
    $diff = Zeile::where('run_id', $lauf->id)->where('row_nr', 3)->first()->diff;
    expect($diff['felder']['brand'])->toBe(['alt' => 'Mühle', 'neu' => 'Andere Marke']);
});

it('Treffer über EAN, wenn die Artikelnummer nicht trifft', function () {
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;EAN Gebinde;Bezeichnung;Marke\nHanos;XYZ;4000000000017;Mehl;M\n"));
    $z = Zeile::where('run_id', $lauf->id)->first();

    expect($z->match_method)->toBe('ean')->and($z->supplier_item_id)->toBe($this->mehl->id)->and($z->aktion)->toBe('aendert');
});

it('mehrere Lieferanten in einer Datei; unbekannter Lieferant ⇒ Fehlerzeile, nie geraten', function () {
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung\nHanos;A1;Eins\nChefs Culinar;B1;Zwei\nGibtsNicht;C1;Drei\n;D1;Vier\n"));
    $z = Zeile::where('run_id', $lauf->id)->orderBy('row_nr')->get();

    expect($z->pluck('supplier_id')->all())->toBe([$this->hanos->id, $this->chefs->id, null, null])
        ->and($z->pluck('aktion')->all())->toBe(['neu', 'neu', 'fehler', 'fehler'])
        ->and($z[2]->status)->toBe('lieferant_unbekannt');
});

it('ohne Lieferant-Spalte und ohne Standard-Lieferant: Fehler vor dem Anlegen eines Laufs', function () {
    expect(fn () => $this->svc->stage($this->team, ($this->csv)("Artikelnummer;Bezeichnung\nA1;Eins\n")))->toThrow(\RuntimeException::class, 'Lieferant');
    expect(Lauf::count())->toBe(0);
});

it('Pflichtspalten fehlen ⇒ kein Fehler, der Lauf wartet auf die Zuordnung (1a2); keine Zeilen', function () {
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Marke\nHanos;X\n"));

    expect($lauf->status)->toBe('zuordnung_noetig')->and($lauf->mapping['grund'])->toContain('Pflicht-Spalte')->and(Zeile::count())->toBe(0);
});

it('geerbter Artikel des Eltern-Teams ⇒ Konflikt (D1), kein Schreiben', function () {
    $geerbt = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->hanos->id, 'designation' => 'Geerbt', 'article_number' => 'G1']);
    $lauf = $this->svc->stage($this->childA, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung;Marke\nHanos;G1;Geerbt;X\n"), $this->hanos->id);

    expect(($this->aktionen)($lauf))->toBe([2 => 'konflikt'])
        ->and($geerbt->fresh()->brand)->toBeNull();
});

it('Allergen-Rückstufung enthalten → nicht_enthalten ist ein Konflikt mit Hinweis; Aufstufung nicht', function () {
    app(SupplierItemService::class)->setAllergens($this->team, $this->mehl, ['gluten' => 'enthalten', 'milk' => 'nicht_enthalten']);
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung;Allergen Gluten;Allergen Milch\nHanos;1001;Mehl;nein;enthalten\n"));
    $z = Zeile::where('run_id', $lauf->id)->first();

    expect($z->aktion)->toBe('konflikt')
        ->and(collect($z->hinweise)->pluck('code'))->toContain('allergen_rueckstufung')
        ->and($z->diff['allergene']['gluten'])->toBe(['alt' => 'enthalten', 'neu' => 'nicht_enthalten'])
        ->and($z->diff['allergene']['milk'])->toBe(['alt' => 'nicht_enthalten', 'neu' => 'enthalten']);

    // Gegenprobe: nur Aufstufung ⇒ kein Konflikt
    $lauf2 = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung;Allergen Milch\nHanos;1001;Mehl;enthalten\n"));
    expect(Zeile::where('run_id', $lauf2->id)->first()->aktion)->toBe('aendert');
});

it('leere Allergen-Zelle heißt „keine Aussage“ — sie taucht weder im Diff noch als Rückstufung auf', function () {
    app(SupplierItemService::class)->setAllergens($this->team, $this->mehl, ['gluten' => 'enthalten']);
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung;Allergen Gluten\nHanos;1001;Mehl;\n"));
    $z = Zeile::where('run_id', $lauf->id)->first();

    expect($z->aktion)->toBe('unveraendert')->and($z->diff)->toBeNull();
});

it('Nährwert-Prüfer: unplausible Werte erzeugen Hinweise, blockieren aber nicht; der Prüfer ist austauschbar', function () {
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung;Nährwert Kohlenhydrate;Nährwert davon Zucker;Nährwert kcal;Nährwert kJ\nHanos;N1;Brot;10;40;250;300\n"));
    $z = Zeile::where('run_id', $lauf->id)->first();
    $codes = collect($z->hinweise)->pluck('code')->all();

    expect($codes)->toContain('zucker_ueber_kh')->toContain('kcal_kj')
        ->and($z->aktion)->toBe('neu');

    app()->bind(NaehrwertPruefer::class, fn () => new class implements NaehrwertPruefer {
        public function pruefe(array $zeile, ?string $warengruppe = null, ?string $sub = null): array { return [['regel' => 'ersatz', 'schwere' => 'hinweis', 'feld' => null, 'wert' => null, 'erwartet' => null, 'vorschlag' => null, 'text' => 'anderer Prüfer']]; }
    });
    $lauf2 = app(StammdatenImportService::class)->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung;Nährwert Fett\nHanos;N2;Öl;99\n"));
    expect(collect(Zeile::where('run_id', $lauf2->id)->first()->hinweise)->pluck('code')->all())->toBe(['ersatz']);
    expect(app(NaehrwertPruefer::class))->not->toBeInstanceOf(BasisNaehrwertPruefer::class);
});

it('Preis: neu/geändert wird als Soll/Ist gezeigt, geschrieben wird nichts; ungültiger Preis ⇒ Fehler', function () {
    app(\Platform\FoodAlchemist\Services\PriceService::class)->createFor($this->team, $this->mehl, 10.0, '0');
    $vorher = FoodAlchemistPrice::count();
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung;Nettopreis;Gültig ab\nHanos;1001;Mehl;12,50;01.01.2099\nHanos;1001;Mehl;abc;\n"));
    $z = Zeile::where('run_id', $lauf->id)->orderBy('row_nr')->get();

    expect($z[0]->diff['preis'])->toEqual(['alt' => 10.0, 'neu' => 12.5])
        ->and(collect($z[0]->hinweise)->pluck('code'))->toContain('gueltig_ab_zukunft')
        ->and($z[1]->aktion)->toBe('fehler')
        ->and(FoodAlchemistPrice::count())->toBe($vorher);
});

it('Excel-Datei wird wie CSV gelesen', function () {
    if (! class_exists('\OpenSpout\Writer\XLSX\Writer')) {
        $this->markTestSkipped('openspout nicht installiert');
    }
    $pfad = tempnam(sys_get_temp_dir(), 'sd') . '.xlsx';
    $w = new \OpenSpout\Writer\XLSX\Writer();
    $w->openToFile($pfad);
    $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Lieferant', 'Artikelnummer', 'Bezeichnung', 'Nettopreis']));
    $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Hanos', 'X1', 'Excel-Artikel', 4.5]));
    $w->close();

    $lauf = $this->svc->stage($this->team, $pfad, null, null, 'xlsx');
    expect(($this->aktionen)($lauf))->toBe([2 => 'neu']);
});

it('ein Stammdaten-Lauf lässt sich nicht als Preisliste anwenden', function () {
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung\nHanos;A1;Eins\n"));
    $user = $this->makeUser($this->team, 'Kurator', 'owner');

    expect(fn () => app(PreislistenImportService::class)->anwenden($this->team, $user, $lauf))->toThrow(\RuntimeException::class, 'Stammdaten-Lauf');
});

it('Prüfliste: Filter nach Aktion, strikt team-eigen', function () {
    $lauf = $this->svc->stage($this->team, ($this->csv)("Lieferant;Artikelnummer;Bezeichnung\nHanos;A1;Eins\nHanos;1001;Mehl\n"));

    expect($this->svc->pruefliste($lauf, 'neu'))->toHaveCount(1)
        ->and($this->svc->pruefliste($lauf))->toHaveCount(2);
});
