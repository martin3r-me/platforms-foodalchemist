<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk v3.5.0 §7.1a / §8.6 (Dominique 19.10.2026): Getränke (WG 15) tragen das Einzelgebinde + Liter im GP-Namen,
 * ohne Zustandswort. Alle übrigen Verpackungswörter bleiben auch bei Getränken verboten; ausserhalb WG 15 gilt §7.1 unverändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fehler = fn (string $name, ?string $wg, ?string $zustand = null) => app(GpNamingService::class)
        ->validateGpName($name, array_filter(['warengruppe' => $wg, 'condition' => $zustand], fn ($v) => $v !== null))['errors'];
});

it('Einzelgebinde mit Liter ist bei Getränken (WG 15) erlaubt, ohne Zustandswort', function () {
    foreach (['Pils: 4,9 vol-%, Flasche 0,33 l', 'Pils: 4,9 vol-%, Fass 30 l', 'Cola: Dose 0,33 l', 'Wein: trocken, Bag-in-Box 3 l', 'Orangensaft: frisch gepresst, Flasche 1 l / (Bio)'] as $name) {
        expect(($this->fehler)($name, '15'))->toBe([], $name);
    }
});

it('Die Ausnahme wirkt auch mit commodity_group_code statt warengruppe im Kontext', function () {
    $fehler = app(GpNamingService::class)->validateGpName('Cola: Dose 0,33 l', ['commodity_group_code' => '15'])['errors'];

    expect($fehler)->toBe([]);
});

it('Sammelgebinde und übrige Verpackungswörter bleiben bei Getränken verboten', function () {
    foreach (['Pils: 4,9 vol-%, Kasten 20 x 0,5 l', 'Pils: 4,9 vol-%, Karton', 'Cola: Tray 24 x 0,33 l', 'Gin: 40 vol-%, Glas 0,7 l'] as $name) {
        expect(($this->fehler)($name, '15'))->not->toBe([], $name);
    }
});

it('Ausserhalb von WG 15 und ohne Warengruppe gilt §7.1 unverändert (Dose bleibt verboten)', function () {
    expect(($this->fehler)('Tomaten: konserviert, Dose', '01'))->not->toBe([])
        ->and(($this->fehler)('Cola: Dose 0,33 l', '01'))->not->toBe([])
        ->and(($this->fehler)('Cola: Dose 0,33 l', null))->not->toBe([]);
});

it('Migration: gp.7.1.gebinde trägt die WG-15-Freigabe, gp.7.1a.sammelgebinde ist aktiv und blockiert', function () {
    $alt = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'gp.7.1.gebinde')->firstOrFail();
    $neu = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'gp.7.1a.sammelgebinde')->firstOrFail();

    expect($alt->params['erlaubt_wenn'])->toBe(['warengruppe' => ['15']])
        ->and($alt->aktiv)->toBeTrue()
        ->and($neu->aktiv)->toBeTrue()->and($neu->wirkung)->toBe('blockieren')
        ->and($neu->params['bedingung'])->toBe(['warengruppe' => ['15']]);
});
