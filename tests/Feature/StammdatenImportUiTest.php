<?php

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Preislisten\StammdatenImport;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Tools\PreislisteLaeufeGetTool;
use Platform\FoodAlchemist\Tools\PreislisteUploadTool;
use Platform\FoodAlchemist\Tools\PreislisteVorlageGetTool;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Stammdaten-Import 1a: Oberfläche + MCP. Rechte wie preisliste.UPLOAD (Haupt-Team + Kuratieren). */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->admin = $this->makeUser($this->rootTeam, 'Chef', 'owner');
    $this->actingAs($this->admin);
    $this->team = $this->rootTeam;
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'designation' => 'Mehl', 'article_number' => '1001']);
    $this->datei = fn (string $inhalt) => UploadedFile::fake()->createWithContent('stamm.csv', $inhalt);
    $this->csv = "Lieferant;Artikelnummer;Bezeichnung;Marke\nHanos;1001;Mehl;M\nHanos;N1;Butter;B\n";
});

it('UI: Vorlage herunterladen, Datei prüfen ⇒ Lauf + Prüfliste; nichts im Bestand', function () {
    $vorher = FoodAlchemistSupplierItem::count();
    $c = Livewire::test(StammdatenImport::class)
        ->assertSeeHtml('data-stammdaten-vorlage-csv')
        ->call('vorlageCsv')
        ->set('datei', ($this->datei)($this->csv))
        ->call('pruefen')
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-stammdaten-pruefliste')
        ->assertSeeHtml('data-stammdaten-zeile="neu"')
        ->assertSeeHtml('data-stammdaten-zeile="aendert"');

    expect(Lauf::where('art', 'stammdaten')->count())->toBe(1)
        ->and(FoodAlchemistSupplierItem::count())->toBe($vorher);
    $c->set('filter', 'neu')->assertSeeHtml('data-stammdaten-zeile="neu"')->assertDontSeeHtml('data-stammdaten-zeile="aendert"');
});

it('UI: Nutzer ohne Kuratieren-Recht (Viewer) und Unter-Team sehen nur lesend, prüfen schreibt keinen Lauf', function () {
    foreach ([[$this->makeUser($this->rootTeam, 'Gast', 'viewer')], [$this->makeUser($this->childA, 'Kind', 'owner')]] as [$user]) {
        $this->actingAs($user);
        Livewire::test(StammdatenImport::class)
            ->assertSeeHtml('data-stammdaten-nur-lesen')->assertDontSeeHtml('data-stammdaten-upload')
            ->set('datei', ($this->datei)($this->csv))->call('pruefen')
            ->assertSet('fehler', 'Stammdaten importieren darf nur das Haupt-Team des Kunden (Rolle Kuratieren).');
    }
    expect(Lauf::count())->toBe(0);
});

it('UI: ein Lauf eines anderen Teams lässt sich nicht öffnen', function () {
    $lauf = Lauf::create(['team_id' => $this->childA->id, 'filename' => 'fremd.csv', 'status' => 'staged', 'art' => 'stammdaten']);
    Livewire::test(StammdatenImport::class)->call('laufWaehlen', $lauf->id)->assertSet('laufId', null);
});

it('UI: der Preislisten-Schirm bettet die Stammdaten ein, die Preislisten-Läufe zeigen keine Stammdaten-Läufe', function () {
    Lauf::create(['team_id' => $this->team->id, 'filename' => 'stamm.csv', 'status' => 'staged', 'art' => 'stammdaten']);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Preislisten\Index::class)->assertSeeHtml('data-stammdaten-import')->assertDontSeeHtml('data-preisliste-lauf=');
});

it('Handy: die Prüfliste ist eine Kartenliste (details), keine breite Tabelle', function () {
    $html = file_get_contents(__DIR__ . '/../../resources/views/livewire/preislisten/stammdaten-import.blade.php');
    expect($html)->toContain('<details')->not->toContain('<table')->toContain('flex-col');
});

it('MCP: VORLAGE_GET art=stammdaten, UPLOAD art=stammdaten, LAEUFE_GET mit aktion-Filter; Unter-Team verboten', function () {
    $ctx = new ToolContext($this->admin, $this->team);
    $vorlage = (new PreislisteVorlageGetTool)->execute(['art' => 'stammdaten'], $ctx);
    expect($vorlage->success)->toBeTrue()->and($vorlage->data['art'])->toBe('stammdaten')->and($vorlage->data['spalten'])->toContain('Allergen Milch');

    $up = (new PreislisteUploadTool)->execute(['art' => 'stammdaten', 'filename' => 's.csv', 'content_base64' => base64_encode($this->csv)], $ctx);
    expect($up->success)->toBeTrue()->and($up->data['art'])->toBe('stammdaten')->and($up->data['zaehler']['neu'])->toBe(1);

    $get = (new PreislisteLaeufeGetTool)->execute(['run_id' => $up->data['lauf_id'], 'aktion' => 'neu'], $ctx);
    expect($get->data['pruefliste'])->toHaveCount(1)->and($get->data['pruefliste'][0]['aktion'])->toBe('neu')
        ->and((new PreislisteLaeufeGetTool)->execute([], $ctx)->data['laeufe'][0]['art'])->toBe('stammdaten');

    $kind = $this->makeUser($this->childA, 'Kind', 'owner');
    $verboten = (new PreislisteUploadTool)->execute(['art' => 'stammdaten', 'filename' => 's.csv', 'content_base64' => base64_encode($this->csv)], new ToolContext($kind, $this->childA));
    expect($verboten->success)->toBeFalse();
});

it('Gegenprobe: ohne art bleibt der Preislisten-Upload unverändert (art=preisliste, keine Stammdaten-Zeilen)', function () {
    $ctx = new ToolContext($this->admin, $this->team);
    $up = (new PreislisteUploadTool)->execute(['filename' => 'p.csv', 'content_base64' => base64_encode("Artikelnummer;Preis\n1001;5,00\n"), 'supplier_id' => $this->hanos->id], $ctx);

    expect($up->data['art'])->toBe('preisliste')->and(Zeile::whereNotNull('aktion')->count())->toBe(0);
});
