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
 * Getränke (WG 15, Entscheid Dominique 28.10.): Marke als Klammer-Zusatz statt im Namen („Ananassaft: 1 l / (Happy Day)“), Füllmenge nach §7.1a,
 * kein Zustandswort, rezepturware bleibt false. Ohne belegte Marke: Klammer leer, generisches Getränk (kein marke_offen). KI gemockt.
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

it('„Happy Day Ananassaft Pk1,0l“ → Ananassaft / 1 l / Happy Day — auch wenn die KI die Marke in die Hauptzutat schreibt', function () {
    ($this->ki)(['hauptzutat' => 'Happy Day Ananassaft', 'commodity_group_code' => '15', 'pflichtangabe' => '', 'zusatz' => '', 'rezepturware' => true]);
    $f = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)('Happy Day Ananassaft Pk1,0l', ['brand' => 'Happy Day']));

    expect([$f['hauptzutat'], $f['pflichtangabe'], $f['zusatz'], $f['rezepturware'], $f['marke_offen']])->toBe(['Ananassaft', '1 l', 'Happy Day', false, false])
        ->and(($this->name)($f))->toBe('Ananassaft: 1 l / (Happy Day)');
});

it('Bier mit vol-%: Marke in die Klammer, Vol-% und Gebinde bleiben in der Pflichtangabe', function () {
    ($this->ki)(['hauptzutat' => 'Pils', 'commodity_group_code' => '15', 'pflichtangabe' => '4,9 vol-%, Flasche 0,33 l', 'zusatz' => '']);
    $f = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)('Jever Pils 4,9% 24x0,33l Fl.', ['brand' => 'Jever']));

    expect([$f['hauptzutat'], $f['pflichtangabe'], $f['zusatz']])->toBe(['Pils', '4,9 vol-%, Flasche 0,33 l', 'Jever'])
        ->and(($this->name)($f))->toBe('Pils: 4,9 vol-%, Flasche 0,33 l / (Jever)');
});

it('Wasser ohne Marke: Klammer leer, generisch (kein marke_offen), Füllmenge aus dem Artikel', function () {
    ($this->ki)(['hauptzutat' => 'Tafelwasser', 'commodity_group_code' => '15', 'processing' => 'still', 'pflichtangabe' => '', 'zusatz' => '']);
    $f = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)('Tafelwasser still 0,75l'));

    expect([$f['hauptzutat'], $f['pflichtangabe'], $f['zusatz'], $f['marke_offen']])->toBe(['Tafelwasser', '0,75 l', '', false]);
});

it('Füllmenge nach §7.1a in Litern; ohne Füllmenge null', function () {
    expect(GpNamingService::fuellmenge('Happy Day Ananassaft Pk1,0l'))->toBe('1 l')
        ->and(GpNamingService::fuellmenge('Cola 330 ml Dose'))->toBe('0,33 l')
        ->and(GpNamingService::fuellmenge('Likör 70cl'))->toBe('0,7 l')
        ->and(GpNamingService::fuellmenge('Mineralwasser 0,75L'))->toBe('0,75 l')
        ->and(GpNamingService::fuellmenge('Orangensaft'))->toBeNull();
});
