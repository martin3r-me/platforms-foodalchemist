<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Preislisten\StammdatenImport;
use Platform\FoodAlchemist\Models\FoodAlchemistImportZuordnung;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\Stammdaten\ImportZuordnungService;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenImportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\PreislisteUploadTool;
use Platform\FoodAlchemist\Tools\PreislisteZuordnenTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 1a2 (Kurator 29.10.2026): eigene Lieferanten-Dateien ohne Vorlage — „Zuordnung nötig“ statt Fehler, Zuordnung je Lieferant merken und anwenden.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
    $this->admin = $this->makeUser($this->team, 'Chef', 'owner');
    $this->actingAs($this->admin);
    $this->stamm = app(StammdatenImportService::class);
    $this->liste = app(PreislistenImportService::class);
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    $this->chefs = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Chefs Culinar']);
    FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'designation' => 'Mehl', 'article_number' => '1001']);
    $this->csv = function (string $inhalt, string $endung = 'csv'): string {
        $pfad = tempnam(sys_get_temp_dir(), 'zu') . '.' . $endung;
        file_put_contents($pfad, $inhalt);

        return $pfad;
    };
    $this->eigene = "Artikel Code;Warenbezeichnung;Preis EUR;Kennzeichen\n1001;Mehl neu;12,50;x\nN1;Butter;9,90;y\n";
    $this->karte = ['Artikelnummer' => 'Artikel Code', 'Bezeichnung' => 'Warenbezeichnung', 'Nettopreis' => 'Preis EUR'];
});

it('Stammdaten: eigene Datei ohne erkannte Pflichtspalten ⇒ „Zuordnung nötig“ (kein Fehler), Datei wartet, keine Zeilen', function () {
    $lauf = $this->stamm->stage($this->team, ($this->csv)($this->eigene), $this->hanos->id);

    expect($lauf->status)->toBe('zuordnung_noetig')
        ->and($lauf->mapping['kopf'])->toBe(['Artikel Code', 'Warenbezeichnung', 'Preis EUR', 'Kennzeichen'])
        ->and($lauf->mapping['vorschau'])->toHaveCount(2)
        ->and($lauf->counts['zeilen'])->toBe(2)
        ->and(Zeile::where('run_id', $lauf->id)->count())->toBe(0);
    Storage::disk('local')->assertExists($lauf->datei_pfad);
});

it('Stammdaten: zuordnen prüft die wartende Datei, entfernt den wartenden Lauf samt Datei und merkt die Zuordnung je Lieferant', function () {
    $wartend = $this->stamm->stage($this->team, ($this->csv)($this->eigene), $this->hanos->id);
    $pfad = $wartend->datei_pfad;
    $neu = $this->stamm->zuordnen($this->team, $wartend, $this->karte, $this->admin->id);

    expect($neu->status)->toBe('staged')->and($neu->counts['neu'])->toBe(1)->and($neu->counts['aendert'])->toBe(1)
        ->and(Lauf::find($wartend->id))->toBeNull();
    Storage::disk('local')->assertMissing($pfad);
    expect(FoodAlchemistImportZuordnung::where('supplier_id', $this->hanos->id)->where('art', 'stammdaten')->count())->toBe(1);
});

it('Stammdaten: beim nächsten Upload desselben Lieferanten wird die gemerkte Zuordnung automatisch angewendet', function () {
    $w = $this->stamm->stage($this->team, ($this->csv)($this->eigene), $this->hanos->id);
    $this->stamm->zuordnen($this->team, $w, $this->karte);

    $lauf = $this->stamm->stage($this->team, ($this->csv)("Artikel Code;Warenbezeichnung;Preis EUR;Kennzeichen\nN2;Salz;1,10;z\n"), $this->hanos->id);

    expect($lauf->status)->toBe('staged')->and($lauf->counts['neu'])->toBe(1)->and(implode(' ', $lauf->counts['hinweise'] ?? []))->not->toContain('geändert');
    expect(Zeile::where('run_id', $lauf->id)->first()->diff['preis']['neu'])->toBe(1.1);
});

it('Stammdaten: geänderte Kopfzeile ⇒ Hinweis mit neuen/fehlenden Spalten; fehlt eine Pflichtspalte ⇒ wieder „Zuordnung nötig“', function () {
    $w = $this->stamm->stage($this->team, ($this->csv)($this->eigene), $this->hanos->id);
    $this->stamm->zuordnen($this->team, $w, $this->karte);

    $mitNeu = $this->stamm->stage($this->team, ($this->csv)("Artikel Code;Warenbezeichnung;Preis EUR;Kennzeichen;Neue Spalte\nN3;Pfeffer;3,00;q;r\n"), $this->hanos->id);
    expect($mitNeu->status)->toBe('staged')
        ->and(implode(' ', $mitNeu->counts['hinweise']))->toContain('geändert')->toContain('neu: Neue Spalte');

    $ohnePflicht = $this->stamm->stage($this->team, ($this->csv)("Code;Warenbezeichnung;Preis EUR\nN4;Zimt;2,00\n"), $this->hanos->id);
    expect($ohnePflicht->status)->toBe('zuordnung_noetig');
});

it('Gegenprobe: merken=false speichert nichts; andere Lieferanten und andere Teams erben die Zuordnung nicht', function () {
    $w = $this->stamm->stage($this->team, ($this->csv)($this->eigene), $this->hanos->id);
    $this->stamm->zuordnen($this->team, $w, $this->karte, null, false);
    expect(FoodAlchemistImportZuordnung::count())->toBe(0);

    $w2 = $this->stamm->stage($this->team, ($this->csv)($this->eigene), $this->hanos->id);
    $this->stamm->zuordnen($this->team, $w2, $this->karte);
    $andererLieferant = $this->stamm->stage($this->team, ($this->csv)($this->eigene), $this->chefs->id);
    $anderesTeam = $this->stamm->stage($this->childA, ($this->csv)($this->eigene), $this->hanos->id);

    expect($andererLieferant->status)->toBe('zuordnung_noetig')->and($anderesTeam->status)->toBe('zuordnung_noetig');
});

it('Stammdaten: zuordnen eines fremden Laufs oder ohne Pflichtspalten wird abgelehnt; die Datei wartet weiter', function () {
    $w = $this->stamm->stage($this->team, ($this->csv)($this->eigene), $this->hanos->id);

    expect(fn () => $this->stamm->zuordnen($this->childA, $w, $this->karte))->toThrow(\RuntimeException::class, 'gehört nicht');
    expect(fn () => $this->stamm->zuordnen($this->team, $w, ['Marke' => 'Kennzeichen']))->toThrow(\RuntimeException::class, 'reicht noch nicht');
    expect(Lauf::find($w->id)?->status)->toBe('zuordnung_noetig');
    Storage::disk('local')->assertExists($w->datei_pfad);
    expect(fn () => $this->stamm->zuordnen($this->team, $w, ['Bezeichnung' => 'Gibt es nicht']))->toThrow(\RuntimeException::class, 'Spalte, die es nicht gibt');
});

it('Datei-Ablage: nur der eigene Ordner wird gelesen (Pfad-Manipulation wirkungslos), Verwerfen löscht die Datei', function () {
    $w = $this->stamm->stage($this->team, ($this->csv)($this->eigene), $this->hanos->id);
    $zuo = app(ImportZuordnungService::class);
    $pfad = $w->datei_pfad;

    $w->forceFill(['datei_pfad' => '../.env'])->save();
    expect(fn () => $zuo->datei($w->fresh()))->toThrow(\RuntimeException::class);

    $w->forceFill(['datei_pfad' => $pfad])->save();
    $zuo->verwerfen($w->fresh());
    Storage::disk('local')->assertMissing($pfad);
});

it('Preisliste: eigene Datei ohne erkannte Preisspalte ⇒ „Zuordnung nötig“, zuordnen + merken, nächster Upload automatisch', function () {
    $w = $this->liste->stage($this->team, ($this->csv)($this->eigene), [], $this->hanos->id);
    expect($w->status)->toBe('zuordnung_noetig')->and($w->art)->toBe('preisliste');

    $neu = $this->liste->zuordnen($this->team, $w, ['price' => 'Preis EUR', 'article_number' => 'Artikel Code'], null);
    expect($neu->status)->toBe('staged')->and($neu->counts['treffer'])->toBe(1)->and(Lauf::find($w->id))->toBeNull();

    $naechster = $this->liste->stage($this->team, ($this->csv)("Artikel Code;Warenbezeichnung;Preis EUR;Kennzeichen\n1001;Mehl;11,00;x\n"), [], $this->hanos->id);
    expect($naechster->status)->toBe('staged')->and($naechster->counts['treffer'])->toBe(1);
});

it('Leser-Robustheit: Windows-1252 + Semikolon, Komma-Trenner, Tab, BOM, deutsches Dezimalkomma und Tausenderpunkt', function () {
    $fall = [
        'cp1252' => mb_convert_encoding("Lieferant;Artikelnummer;Bezeichnung;Nettopreis\nHanos;K1;Käse Würfel;1.234,56\n", 'Windows-1252', 'UTF-8'),
        'komma' => "Lieferant,Artikelnummer,Bezeichnung,Nettopreis\nHanos,K1,Käse,\"1.234,56\"\n",
        'tab' => "Lieferant\tArtikelnummer\tBezeichnung\tNettopreis\nHanos\tK1\tKäse\t1.234,56\n",
        'bom' => "\xEF\xBB\xBFLieferant;Artikelnummer;Bezeichnung;Nettopreis\nHanos;K1;Käse;1.234,56\n",
    ];
    foreach ($fall as $name => $inhalt) {
        $lauf = $this->stamm->stage($this->team, ($this->csv)($inhalt));
        $z = Zeile::where('run_id', $lauf->id)->first();
        expect($lauf->status)->toBe('staged', $name)->and($z->aktion)->toBe('neu', $name)->and($z->diff['preis']['neu'])->toBe(1234.56, $name);
    }
});

it('MCP: UPLOAD liefert „zuordnung_noetig“ mit Kopf + Vorschau, ZUORDNEN macht daraus die Prüfliste; Unter-Team verboten', function () {
    $ctx = new ToolContext($this->admin, $this->team);
    $up = (new PreislisteUploadTool)->execute(['art' => 'stammdaten', 'filename' => 'eigen.csv', 'content_base64' => base64_encode($this->eigene), 'supplier_id' => $this->hanos->id], $ctx);
    expect($up->success)->toBeTrue()->and($up->data['status'])->toBe('zuordnung_noetig')->and($up->data['kopf'])->toContain('Warenbezeichnung')->and($up->data['vorschau'])->toHaveCount(2);

    $kind = $this->makeUser($this->childA, 'Kind', 'owner');
    $verboten = (new PreislisteZuordnenTool)->execute(['run_id' => $up->data['lauf_id'], 'mapping' => $this->karte], new ToolContext($kind, $this->childA));
    expect($verboten->success)->toBeFalse();

    $z = (new PreislisteZuordnenTool)->execute(['run_id' => $up->data['lauf_id'], 'mapping' => $this->karte], $ctx);
    expect($z->success)->toBeTrue()->and($z->data['zaehler']['neu'])->toBe(1)->and($z->data['zuordnung_gemerkt'])->toBeTrue();

    $zweit = (new PreislisteUploadTool)->execute(['art' => 'stammdaten', 'filename' => 'eigen2.csv', 'content_base64' => base64_encode($this->eigene), 'supplier_id' => $this->hanos->id], $ctx);
    expect($zweit->data['zaehler']['zeilen'])->toBe(2);   // gemerkt ⇒ kein erneutes Zuordnen
});

it('UI: Zuordnungs-Panel erscheint, Auswahl + „Zuordnen und prüfen“ erzeugt die Prüfliste', function () {
    $c = Livewire::test(StammdatenImport::class)
        ->set('lieferantId', $this->hanos->id)
        ->set('datei', UploadedFile::fake()->createWithContent('eigen.csv', $this->eigene))
        ->call('pruefen')
        ->assertSeeHtml('data-stammdaten-zuordnung')
        ->assertSeeHtml('data-stammdaten-zuordnen')
        ->set('zuordnung', [0 => 'Artikelnummer', 1 => 'Bezeichnung', 2 => 'Nettopreis', 3 => ''])
        ->call('zuordnen')
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-stammdaten-pruefliste');

    expect(Lauf::where('status', 'staged')->where('art', 'stammdaten')->count())->toBe(1)->and(Lauf::where('status', 'zuordnung_noetig')->count())->toBe(0);
});
