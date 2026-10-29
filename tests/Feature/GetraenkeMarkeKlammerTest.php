<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpAbgleich\LaFelderService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Getränke (WG 15, Entscheid Dominique 28.10.): Gattungsname + Hersteller-Marke → Marke als Klammer-Zusatz („Ananassaft: 1 l / (Happy Day)“);
 * Markenprodukt ohne eigenen Gattungsnamen → Marke bleibt der Name („Coca-Cola: 0,33 l“). Die KI entscheidet (gp.suggest), der Nachlauf hält die Marke
 * nicht doppelt und setzt die Füllmenge nach §7.1a. rezepturware bleibt false; ohne Marke generisch (kein marke_offen). KI gemockt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->naming = app(GpNamingService::class);
    $this->haendler = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar West Weeze', 'rolle' => 'haendler']);
    $this->nr = 0;
    $this->la = fn (string $bez, array $extra = []) => FoodAlchemistSupplierItem::create($extra + [
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->haendler->id, 'designation' => $bez, 'article_number' => 'G' . (++$this->nr), 'qty' => 1, 'unit_code' => 'l']);
    $this->ki = fn (array $werte) => $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')
        ->andReturnUsing(fn ($key) => new AiProposal($werte, 0.8, 'Mock', [], $key)));
    $this->name = fn (array $f) => $this->naming->renderGpName($f);
});

it('Gattungsname + Hersteller-Marke: „Happy Day Ananassaft Pk1,0l“ → „Ananassaft: Tetra Pak 1 l / (Happy Day)“ — Marke nicht doppelt in der Hauptzutat', function () {
    // die KI erkennt Gattung + Marke (zusatz), schreibt die Marke aber noch mit in die Hauptzutat; Füllmenge fehlt
    ($this->ki)(['hauptzutat' => 'Happy Day Ananassaft', 'commodity_group_code' => '15', 'pflichtangabe' => '', 'zusatz' => 'Happy Day', 'rezepturware' => true]);
    $f = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)('Happy Day Ananassaft Pk1,0l', ['brand' => 'Happy Day']));

    expect([$f['hauptzutat'], $f['pflichtangabe'], $f['zusatz'], $f['rezepturware'], $f['marke_offen']])->toBe(['Ananassaft', 'Tetra Pak 1 l', 'Happy Day', false, false])
        ->and(($this->name)($f))->toBe('Ananassaft: Tetra Pak 1 l / (Happy Day)');
});

it('Markenprodukt ohne eigenen Gattungsnamen: „Coca-Cola“ bleibt der Name, keine Klammer — Füllmenge trotzdem gesetzt', function () {
    ($this->ki)(['hauptzutat' => 'Coca-Cola', 'commodity_group_code' => '15', 'pflichtangabe' => '', 'zusatz' => '']);
    $f = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)('Coca-Cola 0,33l Dose', ['brand' => 'Coca-Cola']));

    expect([$f['hauptzutat'], $f['pflichtangabe'], $f['zusatz']])->toBe(['Coca-Cola', 'Dose 0,33 l', ''])
        ->and(($this->name)($f))->toBe('Coca-Cola: Dose 0,33 l');
});

it('Bier mit vol-%: Vol-% und Gebinde bleiben, die Füllmenge wird nicht doppelt gesetzt; „Fl.“ ohne Material bleibt „Flasche“ (kein Material erfunden)', function () {
    ($this->ki)(['hauptzutat' => 'Pils', 'commodity_group_code' => '15', 'pflichtangabe' => '4,9 vol-%, Flasche 0,33 l', 'zusatz' => 'Jever']);
    $f = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)('Jever Pils 4,9% 24x0,33l Fl.', ['brand' => 'Jever']));

    expect([$f['hauptzutat'], $f['pflichtangabe'], $f['zusatz']])->toBe(['Pils', '4,9 vol-%, Flasche 0,33 l', 'Jever'])
        ->and(($this->name)($f))->toBe('Pils: 4,9 vol-%, Flasche 0,33 l / (Jever)');
});

it('Wasser ohne Marke: Klammer leer, generisch (kein marke_offen), Füllmenge aus dem Artikel; eine erfundene Marke wird verworfen', function () {
    ($this->ki)(['hauptzutat' => 'Tafelwasser', 'commodity_group_code' => '15', 'processing' => 'still', 'pflichtangabe' => '', 'zusatz' => 'Quellgold']);
    $f = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)('Tafelwasser still 0,75l'));

    expect([$f['hauptzutat'], $f['pflichtangabe'], $f['zusatz'], $f['marke_offen']])->toBe(['Tafelwasser', '0,75 l', '', false]);
});

it('Gebinde-Art mit Material aus dem Artikel (v3.8.2): MW → Glasflasche, PET → PET-Flasche; Händler-Eigenmarke in die Klammer', function () {
    ($this->ki)(['hauptzutat' => 'Pils', 'commodity_group_code' => '15', 'pflichtangabe' => '4,9 vol-%, Flasche 0,5 l', 'zusatz' => 'Bitburger']);
    $bier = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)('Bitburger Pils 4,9% 20x0,5l MW', ['brand' => 'Bitburger']));
    ($this->ki)(['hauptzutat' => 'Gerolsteiner Medium', 'commodity_group_code' => '15', 'pflichtangabe' => '', 'zusatz' => '']);
    $wasser = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)('Gerolsteiner Medium 0,5l PET', ['brand' => 'Gerolsteiner']));
    ($this->ki)(['hauptzutat' => 'Apfelsaft', 'commodity_group_code' => '15', 'pflichtangabe' => '1 l', 'zusatz' => 'Base Culinar']);
    $saft = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)('Base Culinar Apfelsaft Tetra 1l', ['brand' => 'Base Culinar']));

    expect(($this->name)($bier))->toBe('Pils: 4,9 vol-%, Glasflasche 0,5 l / (Bitburger)')
        ->and(($this->name)($wasser))->toBe('Gerolsteiner Medium: PET-Flasche 0,5 l')
        ->and(($this->name)($saft))->toBe('Apfelsaft: Tetra Pak 1 l / (Base Culinar)')
        ->and(GpNamingService::gebindeArt('König Ludwig Weißbier 5,5% 20x0,5l Glas'))->toBe('Glasflasche')
        ->and(GpNamingService::gebindeArt('Weißwein trocken BIB 5l'))->toBe('Bag-in-Box')
        ->and(GpNamingService::gebindeArt('Pils KEG 30l'))->toBe('Fass')
        ->and(GpNamingService::gebindeArt('Orangensaft 1l'))->toBeNull();               // kein Beleg → kein Material
});

it('Regel gp.7.1a.flasche_material: „Flasche“ ohne Material warnt bei Getränken, Glas-/PET-Flasche nicht, andere Warengruppen nicht', function () {
    $warn = fn (string $name, string $wg) => implode(' ', $this->naming->validateGpName($name, ['hauptzutat' => 'x', 'commodity_group_code' => $wg])['warnings'] ?? []);

    $fehler = fn (string $name) => implode(' ', $this->naming->validateGpName($name, ['hauptzutat' => 'x', 'commodity_group_code' => '15'])['errors'] ?? []);
    expect($fehler('Cola: 6 x 0,33 l'))->toContain('6 x 0,33 l')                                 // Sammelgebinde bleibt verboten
        ->and($fehler('Cola: Dose 0,33 l'))->toBe('')
        ->and($fehler('Weißbier: 5,5 vol-%, Glasflasche 0,5 l / (König Ludwig)'))->toBe('');
    expect($warn('Pils: 4,9 vol-%, Flasche 0,33 l', '15'))->toContain('Flasche')
        ->and($warn('Pils: 4,9 vol-%, Glasflasche 0,33 l', '15'))->not->toContain('Material')
        ->and($warn('Gerolsteiner Medium: PET-Flasche 0,5 l', '15'))->not->toContain('Material')
        ->and($warn('Essig: Flasche', '11'))->not->toContain('Material');
});

it('Füllmenge nach §7.1a in Litern; ohne Füllmenge null', function () {
    expect(GpNamingService::fuellmenge('Happy Day Ananassaft Pk1,0l'))->toBe('1 l')
        ->and(GpNamingService::fuellmenge('Cola 330 ml Dose'))->toBe('0,33 l')
        ->and(GpNamingService::fuellmenge('Likör 70cl'))->toBe('0,7 l')
        ->and(GpNamingService::fuellmenge('Mineralwasser 0,75L'))->toBe('0,75 l')
        ->and(GpNamingService::fuellmenge('Orangensaft'))->toBeNull();
});
