<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Preislisten\StammdatenImport;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenImportService;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenUebernahmeService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\PreislisteApplyTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Stammdaten-Import 1b (Kurator 29.10.2026): Entscheidung je Zeile, Übernahme mit Guards. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
    $this->admin = $this->makeUser($this->team, 'Chef', 'owner');
    $this->actingAs($this->admin);
    $this->stamm = app(StammdatenImportService::class);
    $this->uebernahme = app(StammdatenUebernahmeService::class);
    $this->items = app(SupplierItemService::class);
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    $this->mehl = FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'designation' => 'Mehl', 'article_number' => '1001']);
    $this->stage = function (string $csv) {
        $pfad = tempnam(sys_get_temp_dir(), 'ub') . '.csv';
        file_put_contents($pfad, $csv);

        return $this->stamm->stage($this->team, $pfad, $this->hanos->id);
    };
    $this->zeile = fn ($lauf, int $nr) => Zeile::where('run_id', $lauf->id)->where('row_nr', $nr)->first();
    $this->waehle = fn ($lauf, int $nr, string $e = 'uebernehmen', bool $allergene = false) => $this->uebernahme->entscheide($this->team, $this->admin, $lauf, [['id' => ($this->zeile)($lauf, $nr)->id, 'entscheidung' => $e, 'allergene_bestaetigt' => $allergene]]);
    $this->kopf = "Artikelnummer;Bezeichnung;Marke;Nettopreis;Allergen Gluten;Nährwert kcal;Nährwert Fett;Haltbarkeit trocken Tage;Gültig ab\n";
});

it('ohne Entscheidung wird nichts geschrieben — auch nicht beim Übernehmen', function () {
    $lauf = ($this->stage)($this->kopf . "N1;Butter;B;9,90;;;;;\n");
    $vorher = [FoodAlchemistSupplierItem::count(), FoodAlchemistPrice::count()];

    $b = $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);

    expect($b['angewendet'])->toBe(0)->and([FoodAlchemistSupplierItem::count(), FoodAlchemistPrice::count()])->toBe($vorher);
});

it('neuer Artikel: Übernehmen legt Artikel, Preis, Nährwerte und Haltbarkeit an; Allergene NICHT ohne Bestätigung', function () {
    $lauf = ($this->stage)($this->kopf . "N1;Butter;Bergland;9,90;enthalten;740;82;21;\n");
    ($this->waehle)($lauf, 2);

    $b = $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);
    $item = FoodAlchemistSupplierItem::where('article_number', 'N1')->first();

    expect($b['angewendet'])->toBe(1)->and($item)->not->toBeNull()
        ->and($item->brand)->toBe('Bergland')->and((int) $item->team_id)->toBe($this->team->id)->and((int) $item->shelf_life_dry_days)->toBe(21)
        ->and((float) app(PriceService::class)->activeFor($item->id, $this->team)->price)->toBe(9.9)
        ->and($this->items->getNutrition($item)['energy_kcal'])->toBe('740')
        ->and($this->items->getAllergens($item)['gluten'])->toBe('unbekannt')              // nicht bestätigt ⇒ nicht geschrieben
        ->and(($this->zeile)($lauf, 2)->status)->toBe(Zeile::STATUS_ANGEWENDET);
});

it('mit Bestätigung werden die Allergene geschrieben', function () {
    $lauf = ($this->stage)($this->kopf . "N1;Butter;B;9,90;enthalten;;;;\n");
    ($this->waehle)($lauf, 2, 'uebernehmen', true);
    $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);

    expect($this->items->getAllergens(FoodAlchemistSupplierItem::where('article_number', 'N1')->first())['gluten'])->toBe('enthalten');
});

it('Allergen-Rückstufung: ohne Bestätigung bleibt die ganze Zeile stehen, mit Bestätigung wird sie geschrieben', function () {
    $this->items->setAllergens($this->team, $this->mehl, ['gluten' => 'enthalten']);
    $lauf = ($this->stage)($this->kopf . "1001;Mehl;Neu;;nein;;;;\n");
    ($this->waehle)($lauf, 2);

    $b = $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);
    expect($b['angewendet'])->toBe(0)->and($b['uebersprungen'][0]['grund'])->toContain('Rückstufung')
        ->and($this->items->getAllergens($this->mehl->fresh())['gluten'])->toBe('enthalten')->and($this->mehl->fresh()->brand)->toBeNull();

    ($this->waehle)($lauf, 2, 'uebernehmen', true);
    $b2 = $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);
    expect($b2['angewendet'])->toBe(1)->and($this->items->getAllergens($this->mehl->fresh())['gluten'])->toBe('nicht_enthalten')->and($this->mehl->fresh()->brand)->toBe('Neu');
});

it('harter Nährwert-Befund: die Nährwerte der Zeile werden nie geschrieben, der Rest schon', function () {
    $lauf = ($this->stage)($this->kopf . "N2;Öl;Ö;5,00;;1200;99;;\n");
    ($this->waehle)($lauf, 2);
    $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);
    $item = FoodAlchemistSupplierItem::where('article_number', 'N2')->first();

    expect($item)->not->toBeNull()->and($this->items->getNutrition($item)['energy_kcal'])->toBe('')->and($item->brand)->toBe('Ö');
});

it('Fehlerzeile und mehrdeutiger Artikel sind nicht übernehmbar', function () {
    FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'designation' => 'Doppelt', 'article_number' => '1001']);
    $lauf = ($this->stage)($this->kopf . "1001;Mehl;X;;;;;;\n;Ohne Schlüssel;Y;;;;;;\n");
    ($this->waehle)($lauf, 2);
    ($this->waehle)($lauf, 3);

    $b = $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);
    expect($b['angewendet'])->toBe(0)->and(count($b['uebersprungen']))->toBe(2)
        ->and(FoodAlchemistSupplierItem::where('brand', 'X')->count())->toBe(0);
});

it('Verwerfen schließt die Zeile ab und schreibt nichts', function () {
    $lauf = ($this->stage)($this->kopf . "N1;Butter;B;9,90;;;;;\n");
    ($this->waehle)($lauf, 2, 'verwerfen');
    $b = $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);

    expect(($this->zeile)($lauf, 2)->status)->toBe(Zeile::STATUS_VERWORFEN)->and($b['angewendet'])->toBe(0)->and(FoodAlchemistSupplierItem::where('article_number', 'N1')->count())->toBe(0);
});

it('Veralterung: hat sich der Bestand seit der Prüfung geändert, wird die Zeile nicht geschrieben', function () {
    $lauf = ($this->stage)($this->kopf . "1001;Mehl;Neu;;;;;;\n");
    ($this->waehle)($lauf, 2);
    $this->mehl->update(['brand' => 'Zwischendurch geändert']);

    $b = $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);
    expect($b['angewendet'])->toBe(0)->and($b['uebersprungen'][0]['grund'])->toContain('seit der Prüfung')->and($this->mehl->fresh()->brand)->toBe('Zwischendurch geändert');
});

it('Preis mit „Gültig ab“ in der Zukunft wird nicht geschrieben, mit Hinweis; der Rest geht durch', function () {
    $lauf = ($this->stage)($this->kopf . "N3;Salz;S;1,10;;;;;01.01.2099\n");
    ($this->waehle)($lauf, 2);
    $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);
    $item = FoodAlchemistSupplierItem::where('article_number', 'N3')->first();

    expect($item)->not->toBeNull()->and(app(PriceService::class)->activeFor($item->id, $this->team))->toBeNull()
        ->and(collect(($this->zeile)($lauf, 2)->hinweise)->pluck('code'))->toContain('preis_zurueckgestellt');
});

it('Idempotenz: ein zweites Übernehmen schreibt nichts doppelt', function () {
    $lauf = ($this->stage)($this->kopf . "N1;Butter;B;9,90;;;;;\n");
    ($this->waehle)($lauf, 2);
    $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);
    $b = $this->uebernahme->uebernehmen($this->team, $this->admin, $lauf);

    expect($b['angewendet'])->toBe(0)->and(FoodAlchemistSupplierItem::where('article_number', 'N1')->count())->toBe(1)->and(FoodAlchemistPrice::where('supplier_item_id', FoodAlchemistSupplierItem::where('article_number', 'N1')->value('id'))->count())->toBe(1);
});

it('Sammelaktion: unkritische Zeilen werden vorgemerkt, Zeilen mit Allergen-Änderung nicht', function () {
    $lauf = ($this->stage)($this->kopf . "N1;Butter;B;9,90;;;;;\nN2;Mehl2;M;1,00;enthalten;;;;\n1001;Mehl;Neu;;;;;;\n");
    $n = $this->uebernahme->merkeUnkritischeVor($this->team, $this->admin, $lauf);

    expect($n)->toBe(2)->and(($this->zeile)($lauf, 3)->entscheidung)->toBe('offen')->and(($this->zeile)($lauf, 2)->entscheidung)->toBe('uebernehmen');
});

it('Rechte: Unter-Team und Viewer dürfen weder entscheiden noch übernehmen; fremde Läufe sind zu', function () {
    $lauf = ($this->stage)($this->kopf . "N1;Butter;B;9,90;;;;;\n");
    foreach ([[$this->makeUser($this->childA, 'Kind', 'owner'), $this->childA], [$this->makeUser($this->team, 'Gast', 'viewer'), $this->team]] as [$u, $t]) {
        expect(fn () => $this->uebernahme->uebernehmen($t, $u, $lauf))->toThrow(FaRechtFehltException::class);
        expect(fn () => $this->uebernahme->entscheide($t, $u, $lauf, [['id' => 1, 'entscheidung' => 'uebernehmen']]))->toThrow(FaRechtFehltException::class);
    }
    $fremd = \Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun::create(['team_id' => $this->childA->id, 'filename' => 'f.csv', 'status' => 'staged', 'art' => 'stammdaten']);
    expect(fn () => $this->uebernahme->uebernehmen($this->team, $this->admin, $fremd))->toThrow(\RuntimeException::class, 'gehört nicht');
    expect(FoodAlchemistSupplierItem::where('article_number', 'N1')->count())->toBe(0);
});

it('MCP APPLY: zeilen + unkritische_vormerken übernehmen; Allergene nur mit Bestätigung; Preislisten-Lauf-Weg bleibt', function () {
    $lauf = ($this->stage)($this->kopf . "N1;Butter;B;9,90;enthalten;;;;\n");
    $ctx = new ToolContext($this->admin, $this->team);
    $r = (new PreislisteApplyTool)->execute(['lauf_id' => $lauf->id, 'zeilen' => [['id' => ($this->zeile)($lauf, 2)->id, 'entscheidung' => 'uebernehmen']]], $ctx);

    expect($r->success)->toBeTrue()->and($r->data['angewendet'])->toBe(1)
        ->and($this->items->getAllergens(FoodAlchemistSupplierItem::where('article_number', 'N1')->first())['gluten'])->toBe('unbekannt');

    $lauf2 = ($this->stage)($this->kopf . "N5;Pfeffer;P;3,00;;;;;\n");
    $r2 = (new PreislisteApplyTool)->execute(['lauf_id' => $lauf2->id, 'unkritische_vormerken' => true], $ctx);
    expect($r2->data['angewendet'])->toBe(1);

    $kind = $this->makeUser($this->childA, 'Kind', 'owner');
    expect((new PreislisteApplyTool)->execute(['lauf_id' => $lauf2->id, 'unkritische_vormerken' => true], new ToolContext($kind, $this->childA))->success)->toBeFalse();
});

it('UI: Zeile übernehmen, Allergene bestätigen, Sammelaktion und „vorgemerkte übernehmen“', function () {
    $lauf = ($this->stage)($this->kopf . "N1;Butter;B;9,90;enthalten;;;;\nN2;Salz;S;1,00;;;;;\n");
    $c = Livewire::test(StammdatenImport::class)->call('laufWaehlen', $lauf->id)
        ->assertSeeHtml('data-stammdaten-unkritisch')->assertSeeHtml('data-stammdaten-zeile-uebernehmen')->assertSeeHtml('data-stammdaten-allergene-bestaetigt');
    $c->call('unkritischVormerken')->assertSeeHtml('data-stammdaten-vorgemerkt');
    $c->call('entscheiden', ($this->zeile)($lauf, 2)->id, 'uebernehmen', true)->call('uebernehmen')->assertSet('fehler', null);

    expect($this->items->getAllergens(FoodAlchemistSupplierItem::where('article_number', 'N1')->first())['gluten'])->toBe('enthalten')
        ->and(FoodAlchemistSupplierItem::where('article_number', 'N2')->count())->toBe(1);
});
