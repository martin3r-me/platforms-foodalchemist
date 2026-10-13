<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Preislisten\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §3 / Schritt 4d, Stufe 2: Preisliste aus Kundensicht hochladen (Excel + CSV), Spalten zuordnen,
 * abgleichen, Prüfliste. Schreibt weiterhin keinen Preis.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->admin = $this->makeUser($this->rootTeam, 'Chef', 'owner');
    $this->actingAs($this->admin);
    $this->team = $this->rootTeam;
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    $this->mehl = FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'designation' => 'Mehl', 'article_number' => '1001', 'ean_packaging' => '4000000000017']);

    $this->xlsx = function (array $zeilen): string {
        $pfad = tempnam(sys_get_temp_dir(), 'pl') . '.xlsx';
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($pfad);
        foreach ($zeilen as $z) {
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($z));
        }
        $writer->close();

        return $pfad;
    };
    $this->csvUpload = fn (string $inhalt, string $name = 'preise.csv') => UploadedFile::fake()->createWithContent($name, $inhalt);
});

it('liest Excel: Zahlen mit Komma, Datum als Seriennummer, Artikelnummer als Zahl; das erste Blatt, die erste Zeile mit Inhalt ist der Kopf', function () {
    $pfad = ($this->xlsx)([
        ['Artikelnummer', 'Bezeichnung', 'Preis netto', 'gültig ab'],
        [1001, 'Mehl 25kg', 1.234, (int) (new DateTimeImmutable('2026-11-01'))->diff(new DateTimeImmutable('1899-12-30'))->days],   // Datum ohne Datumsformat = Excel-Seriennummer
        ['9999', 'Unbekannt', '5,5', '01.12.2026'],
    ]);

    $run = app(PreislistenImportService::class)->stage($this->team, $pfad, [], $this->hanos->id);
    $z = FoodAlchemistPriceImportLine::where('run_id', $run->id)->orderBy('row_nr')->get();

    expect($z[0]->status)->toBe('treffer')->and($z[0]->supplier_item_id)->toBe($this->mehl->id)
        ->and((float) $z[0]->price)->toBe(1.234)                                  // 1,234 € — NICHT 1.234 = 1234 (Tausenderpunkt)
        ->and($z[0]->valid_from->toDateString())->toBe('2026-11-01')
        ->and($z[1]->status)->toBe('ohne_treffer')->and((float) $z[1]->price)->toBe(5.5)->and($z[1]->valid_from->toDateString())->toBe('2026-12-01');
})->skip(fn () => ! class_exists(\OpenSpout\Writer\XLSX\Writer::class), 'openspout/openspout nicht installiert');

it('liest Excel: eine Zelle mit Datumsformat kommt als Datum an', function () {
    $pfad = tempnam(sys_get_temp_dir(), 'pl') . '.xlsx';
    $writer = new \OpenSpout\Writer\XLSX\Writer();
    $writer->openToFile($pfad);
    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Artikelnummer', 'Preis', 'gültig ab']));
    $writer->addRow(new \OpenSpout\Common\Entity\Row([
        \OpenSpout\Common\Entity\Cell::fromValue('1001'), \OpenSpout\Common\Entity\Cell::fromValue(4.5),
        \OpenSpout\Common\Entity\Cell::fromValue(new DateTimeImmutable('2026-12-24'), (new \OpenSpout\Common\Entity\Style\Style())->setFormat('yyyy-mm-dd')),
    ]));
    $writer->close();

    $run = app(PreislistenImportService::class)->stage($this->team, $pfad, [], $this->hanos->id);

    expect(FoodAlchemistPriceImportLine::where('run_id', $run->id)->first()->valid_from->toDateString())->toBe('2026-12-24');
})->skip(fn () => ! class_exists(\OpenSpout\Writer\XLSX\Writer::class), 'openspout/openspout nicht installiert');

it('ohne OpenSpout meldet der Import den Weg: composer update oder CSV', function () {
    if (class_exists(\OpenSpout\Reader\XLSX\Reader::class)) {
        $this->markTestSkipped('openspout ist installiert');
    }
    expect(fn () => app(PreislistenImportService::class)->stage($this->team, tempnam(sys_get_temp_dir(), 'x') . '.xlsx', [], $this->hanos->id))->toThrow(RuntimeException::class);
});

it('Oberfläche: Upload zeigt Vorschau mit erkannter Zuordnung; Abgleichen legt den Lauf an, schreibt aber keinen Preis', function () {
    $preiseVorher = FoodAlchemistPrice::count();
    $artikelVorher = FoodAlchemistSupplierItem::count();

    $c = Livewire::test(Index::class)
        ->set('lieferantId', $this->hanos->id)
        ->set('datei', ($this->csvUpload)("Artikelnummer;Bezeichnung;Preis netto\n1001;Mehl;12,50\n9999;Zucker;3,00\n"))
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-preisliste-zuordnung');

    expect($c->get('zuordnung')['article_number'])->toBe('0')->and($c->get('zuordnung')['price'])->toBe('2')->and($c->get('zuordnung')['supplier'])->toBe('');

    $c->call('abgleichen')->assertSet('fehler', null)->assertSee('1 Treffer');
    $run = DB::table('foodalchemist_price_import_runs')->first();
    expect($run->team_id)->toBe($this->team->id)->and($run->filename)->toEndWith('.csv')
        ->and(FoodAlchemistPrice::count())->toBe($preiseVorher)->and(FoodAlchemistSupplierItem::count())->toBe($artikelVorher);

    $c->assertSeeHtml('data-preisliste-pruefliste')->assertSeeHtml('data-preisliste-zeile="ohne_treffer"')->assertSee('9999');
});

it('Oberfläche: manuelle Spaltenzuordnung gewinnt gegen die Erkennung', function () {
    Livewire::test(Index::class)
        ->set('lieferantId', $this->hanos->id)
        ->set('datei', ($this->csvUpload)("Code;Betrag\n1001;7,00\n"))
        ->set('zuordnung.article_number', '0')->set('zuordnung.price', '1')
        ->call('abgleichen')->assertSet('fehler', null);

    expect(FoodAlchemistPriceImportLine::first())->status->toBe('treffer');
});

it('Berechtigung: Unter-Team und Betrachter dürfen nicht importieren (vor Vorschau und Abgleich)', function () {
    $kind = $this->makeUser($this->childA, 'Kind', 'owner');
    $this->actingAs($kind);
    Livewire::test(Index::class)
        ->set('datei', ($this->csvUpload)("Artikelnummer;Preis\n1001;1\n"))
        ->assertSet('datei', null)->assertSeeHtml('data-preisliste-fehler')->assertSeeHtml('data-preisliste-nur-lesen');
    expect(DB::table('foodalchemist_price_import_runs')->count())->toBe(0);

    $viewer = $this->makeUser($this->rootTeam, 'Gast', 'viewer');
    $this->actingAs($viewer);
    Livewire::test(Index::class)->set('datei', ($this->csvUpload)("Artikelnummer;Preis\n1001;1\n"))->assertSet('datei', null);
    expect(DB::table('foodalchemist_price_import_runs')->count())->toBe(0);
});

it('Datei-Prüfung: zu groß, falsche Endung und kaputte Datei geben eine Meldung, keinen Lauf', function () {
    Livewire::test(Index::class)->set('datei', UploadedFile::fake()->create('riesig.csv', 11000))->assertSet('datei', null)->assertSeeHtml('data-preisliste-fehler');
    Livewire::test(Index::class)->set('datei', ($this->csvUpload)('hallo', 'datei.exe'))->assertSet('datei', null)->assertSeeHtml('data-preisliste-fehler');
    Livewire::test(Index::class)->set('datei', ($this->csvUpload)("Artikelnummer;Bemerkung\n1001;x\n"))->assertSeeHtml('data-preisliste-zuordnung');
    expect(DB::table('foodalchemist_price_import_runs')->count())->toBe(0);
});

it('Läufe sind strikt team-eigen; fremde Läufe sind weder wählbar noch ladbar; die Prüfliste lässt sich als CSV laden', function () {
    $svc = app(PreislistenImportService::class);
    $datei = fn (string $inhalt) => tap(tempnam(sys_get_temp_dir(), 'pl') . '.csv', fn ($p) => file_put_contents($p, $inhalt));

    $mein = $svc->stage($this->team, $datei("Artikelnummer;Preis\n9999;7\n"), [], $this->hanos->id);
    $fremd = $svc->stage($this->childA, $datei("Artikelnummer;Preis\n8888;7\n"), [], $this->hanos->id);

    expect($svc->laeufe($this->team)->pluck('id')->all())->toBe([$mein->id])
        ->and($svc->lauf($this->team, $fremd->id))->toBeNull();

    $c = Livewire::test(Index::class)->call('laufWaehlen', $fremd->id)->assertSet('laufId', null);
    $c->call('laufWaehlen', $mein->id)->assertSet('laufId', $mein->id);

    $c->call('pruefelisteLaden', $mein->id)->assertFileDownloaded('pruefliste-' . $mein->id . '.csv', null, 'text/csv; charset=UTF-8');
    $c->call('pruefelisteLaden', $fremd->id)->assertNoFileDownloaded();
});

it('MCP: preisliste.UPLOAD gleicht ab (ohne Preis zu schreiben), preisliste_laeufe.GET zeigt Läufe und Prüfliste, Rechte und Eingaben werden geprüft', function () {
    $ctx = new \Platform\Core\Contracts\ToolContext($this->admin, $this->team);
    $csv = base64_encode("Artikelnummer;Preis\n1001;5\n9999;7\n");
    $preise = FoodAlchemistPrice::count();

    $up = (new \Platform\FoodAlchemist\Tools\PreislisteUploadTool)->execute(['filename' => 'liste.csv', 'content_base64' => $csv, 'supplier_id' => $this->hanos->id], $ctx);
    expect($up->success)->toBeTrue()->and($up->data['zaehler']['treffer'])->toBe(1)->and($up->data['preise_geschrieben'])->toBe(0)
        ->and(FoodAlchemistPrice::count())->toBe($preise);

    $get = (new \Platform\FoodAlchemist\Tools\PreislisteLaeufeGetTool)->execute(['run_id' => $up->data['lauf_id']], $ctx);
    expect($get->data['pruefliste'])->toHaveCount(1)->and($get->data['pruefliste'][0]['status'])->toBe('ohne_treffer');
    expect((new \Platform\FoodAlchemist\Tools\PreislisteLaeufeGetTool)->execute([], $ctx)->data['laeufe'])->toHaveCount(1);

    $schlecht = fn (array $a) => (new \Platform\FoodAlchemist\Tools\PreislisteUploadTool)->execute($a + ['supplier_id' => $this->hanos->id], $ctx);
    expect($schlecht(['filename' => 'x.exe', 'content_base64' => $csv])->errorCode)->toBe('VALIDATION_ERROR')
        ->and($schlecht(['filename' => 'x.csv', 'content_base64' => '!!!nicht base64!!!'])->errorCode)->toBe('VALIDATION_ERROR')
        ->and($schlecht(['filename' => '../../etc/passwd.csv', 'content_base64' => $csv])->success)->toBeTrue();   // Pfadanteile im Namen werden verworfen, nur der Basisname zählt

    $kind = $this->makeUser($this->childA, 'Kind', 'owner');
    $verboten = (new \Platform\FoodAlchemist\Tools\PreislisteUploadTool)->execute(['filename' => 'liste.csv', 'content_base64' => $csv, 'supplier_id' => $this->hanos->id], new \Platform\Core\Contracts\ToolContext($kind, $this->childA));
    expect($verboten->errorCode)->toBe('FORBIDDEN');

    // Fremder Lauf ist für ein anderes Team nicht lesbar.
    $fremd = (new \Platform\FoodAlchemist\Tools\PreislisteLaeufeGetTool)->execute(['run_id' => $up->data['lauf_id']], new \Platform\Core\Contracts\ToolContext($kind, $this->childA));
    expect($fremd->errorCode)->toBe('NOT_FOUND');
});
