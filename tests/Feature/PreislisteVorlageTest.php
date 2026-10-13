<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Preislisten\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Preislisten-Seite: „Vorlage herunterladen" (CSV + XLSX, MCP preisliste.VORLAGE_GET).
 * Kern: die Vorlage wird vom EIGENEN Import ohne Nachzuordnung erkannt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PreislistenImportService::class);
    $this->team = $this->rootTeam;
    $this->hilfe = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Beispiel-Lieferant']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->team->id, 'supplier_id' => $this->hilfe->id, 'designation' => 'Weizenmehl', 'article_number' => 'A-4711', 'ean_packaging' => '4000000000017',
    ]);
    $this->datei = function (string $endung): string {
        $pfad = tempnam(sys_get_temp_dir(), 'vorlage') . '.' . $endung;
        if ($endung === 'csv') {
            file_put_contents($pfad, $this->svc->vorlageCsv());
        } else {
            $this->svc->schreibeVorlageXlsx($pfad);
        }

        return $pfad;
    };
});

it('★ die CSV-Vorlage wird vom eigenen Import ohne Nachzuordnung erkannt (alle sieben Felder) und als Treffer abgeglichen', function () {
    $pfad = ($this->datei)('csv');

    $v = $this->svc->vorschau($pfad, 'csv');
    expect($v['kopf'])->toBe(PreislistenImportService::VORLAGE_KOPF)
        ->and($v['vorschlag'])->toEqualCanonicalizing(['supplier' => 0, 'article_number' => 1, 'ean' => 2, 'designation' => 3, 'unit' => 4, 'price' => 5, 'valid_from' => 6]);

    $run = $this->svc->stage($this->team, $pfad);   // ohne Mapping
    $zeile = FoodAlchemistPriceImportLine::where('run_id', $run->id)->first();
    expect($run->counts['treffer'])->toBe(1)
        ->and($zeile->supplier_item_id)->toBe($this->la->id)
        ->and((float) $zeile->price)->toBe(18.9)
        ->and($zeile->valid_from->toDateString())->toBe('2026-11-01');
});

it('die Excel-Vorlage wird ebenso erkannt (nur mit openspout), Blatt 2 trägt den Hinweis', function () {
    if (! $this->svc->xlsxVerfuegbar()) {
        $this->markTestSkipped('openspout nicht installiert — nur CSV-Vorlage.');
    }
    $pfad = ($this->datei)('xlsx');

    $v = $this->svc->vorschau($pfad, 'xlsx');
    expect($v['vorschlag'])->toEqualCanonicalizing(['supplier' => 0, 'article_number' => 1, 'ean' => 2, 'designation' => 3, 'unit' => 4, 'price' => 5, 'valid_from' => 6]);
    $run = $this->svc->stage($this->team, $pfad, [], null, null, 'xlsx');
    expect($run->counts['treffer'])->toBe(1);
});

it('ohne openspout: XLSX nicht verfügbar mit klarer Meldung, CSV bleibt', function () {
    if ($this->svc->xlsxVerfuegbar()) {
        $this->markTestSkipped('openspout ist installiert.');
    }

    expect(fn () => $this->svc->schreibeVorlageXlsx(sys_get_temp_dir() . '/x.xlsx'))->toThrow(RuntimeException::class, 'CSV')
        ->and($this->svc->vorlageCsv())->toContain('Preis netto');
});

it('Hinweis nennt die Pflichtfelder: Preis und Artikelnummer ODER EAN', function () {
    $vorlage = $this->svc->vorlage();

    expect($vorlage['hinweis'])->toContain('Preis')->toContain('Artikelnummer ODER EAN')
        ->and($vorlage['beispielzeile'])->toHaveCount(7);
});

it('MCP preisliste.VORLAGE_GET liefert Spalten, Beispiel, Hinweis und die CSV', function () {
    $user = $this->makeUser($this->team);
    $this->actingAs($user);
    $res = app(ToolRegistry::class)->get('foodalchemist.preisliste.VORLAGE_GET')->execute([], new ToolContext($user, $this->team));

    expect($res->success)->toBeTrue('Tool: ' . ($res->error ?? ''))
        ->and($res->data['spalten'])->toBe(PreislistenImportService::VORLAGE_KOPF)
        ->and($res->data['csv'])->toContain('Artikelnummer')->toContain('Beispiel-Lieferant')
        ->and($res->data)->toHaveKey('xlsx_verfuegbar');
});

it('Seite: Download-Knopf CSV liefert die Vorlage (auch nur lesend sichtbar)', function () {
    $this->actingAs($this->makeUser($this->team));

    Livewire::test(Index::class)
        ->assertSee('Vorlage herunterladen')
        ->assertSee('Artikelnummer ODER EAN')
        ->call('vorlageCsv')
        ->assertFileDownloaded('preisliste_vorlage.csv');
});
