<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenImportService;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenUebernahmeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Täglicher Lauf für fällige vorgemerkte Preise (Kurator 29.10.2026): Preisliste UND Stammdaten, idempotent, mit Protokoll. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
    $this->admin = $this->makeUser($this->team, 'Chef', 'owner');
    $this->actingAs($this->admin);
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    $this->mehl = FoodAlchemistSupplierItem::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'designation' => 'Mehl', 'article_number' => '1001']);
    $this->csv = fn (string $inhalt) => tap(tempnam(sys_get_temp_dir(), 'fp') . '.csv', fn ($p) => file_put_contents($p, $inhalt));
    $this->preis = fn ($item) => app(PriceService::class)->activeFor($item->id, $this->team);
});

it('Preisliste: vorgemerkter Preis wird erst ab dem Datum geschrieben; zweiter Lauf schreibt nichts doppelt', function () {
    $svc = app(PreislistenImportService::class);
    $lauf = $svc->stage($this->team, ($this->csv)("Artikelnummer;Preis;gültig ab\n1001;4,50;01.01.2099\n"), [], $this->hanos->id);
    $svc->anwenden($this->team, $this->admin, $lauf);
    expect(Zeile::where('run_id', $lauf->id)->first()->status)->toBe(Zeile::STATUS_ZUKUENFTIG);

    $this->artisan('foodalchemist:faellige-preise')->expectsOutputToContain('0 Läufe')->assertSuccessful();   // noch nicht fällig
    expect(($this->preis)($this->mehl))->toBeNull();

    Zeile::where('run_id', $lauf->id)->update(['valid_from' => now()->subDay()->toDateString()]);
    $this->artisan('foodalchemist:faellige-preise', ['--dry-run' => true])->expectsOutputToContain('1 Preise fällig')->assertSuccessful();
    expect(($this->preis)($this->mehl))->toBeNull();                      // Dry-run schreibt nichts

    $this->artisan('foodalchemist:faellige-preise')->expectsOutputToContain('1 Preise geschrieben')->assertSuccessful();
    expect((float) ($this->preis)($this->mehl)->price)->toBe(4.5)->and(Lauf::find($lauf->id)->status)->toBe('applied')
        ->and(Lauf::find($lauf->id)->counts['faellige_preise_zuletzt']['anzahl'])->toBe(1);

    $this->artisan('foodalchemist:faellige-preise')->expectsOutputToContain('0 Läufe')->assertSuccessful();   // idempotent
    expect(FoodAlchemistPrice::where('supplier_item_id', $this->mehl->id)->count())->toBe(1);
});

it('Stammdaten: der Lauf schreibt vorgemerkte Preise ohne Nutzer; Zeilen mit künftigem Datum bleiben', function () {
    $stamm = app(StammdatenImportService::class);
    $u = app(StammdatenUebernahmeService::class);
    $lauf = $stamm->stage($this->team, ($this->csv)("Artikelnummer;Bezeichnung;Nettopreis;Gültig ab\n1001;Mehl;4,50;01.01.2099\nN2;Salz;1,10;01.01.2099\n"), $this->hanos->id);
    $u->entscheide($this->team, $this->admin, $lauf, Zeile::where('run_id', $lauf->id)->pluck('id')->map(fn ($id) => ['id' => $id, 'entscheidung' => 'uebernehmen'])->all());
    $u->uebernehmen($this->team, $this->admin, $lauf);

    Zeile::where('run_id', $lauf->id)->where('row_nr', 2)->update(['valid_from' => now()->subDay()->toDateString()]);
    auth()->logout();                                                       // Systemlauf: kein angemeldeter Nutzer
    $this->artisan('foodalchemist:faellige-preise')->expectsOutputToContain('1 Preise geschrieben')->assertSuccessful();

    expect((float) ($this->preis)($this->mehl)->price)->toBe(4.5)
        ->and(Zeile::where('run_id', $lauf->id)->where('row_nr', 3)->first()->status)->toBe(Zeile::STATUS_ZUKUENFTIG)   // künftiges Datum bleibt vorgemerkt
        ->and(Lauf::find($lauf->id)->status)->toBe('staged');
});

it('Gegenprobe: ein Lauf ohne fällige Zeilen und ein fremdes Team werden nicht angefasst (--team)', function () {
    $svc = app(PreislistenImportService::class);
    $lauf = $svc->stage($this->team, ($this->csv)("Artikelnummer;Preis;gültig ab\n1001;4,50;01.01.2099\n"), [], $this->hanos->id);
    $svc->anwenden($this->team, $this->admin, $lauf);
    Zeile::where('run_id', $lauf->id)->update(['valid_from' => now()->subDay()->toDateString()]);

    $this->artisan('foodalchemist:faellige-preise', ['--team' => $this->childA->id])->expectsOutputToContain('0 Läufe')->assertSuccessful();
    expect(($this->preis)($this->mehl))->toBeNull();
});

it('Scheduler: das Kommando ist früh morgens eingeplant', function () {
    $ereignis = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'faellige-preise'));

    expect($ereignis)->not->toBeNull()->and($ereignis->expression)->toBe('30 5 * * *');
});
