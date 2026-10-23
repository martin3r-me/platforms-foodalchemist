<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk v3.6.2 §7.1 (Dominique 23.10.2026): »Bund« und »Stange« sind Form der Ware, kein Verpackungswort.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fehler = fn (string $name, ?string $wg) => app(GpNamingService::class)
        ->validateGpName($name, array_filter(['warengruppe' => $wg], fn ($v) => $v !== null))['errors'];
});

it('Bund und Stange sind als Form im GP-Namen erlaubt', function () {
    expect(($this->fehler)('Möhren: frisch, Bund', '01'))->toBe([])
        ->and(($this->fehler)('Zimt: trocken, Stange', '10'))->toBe([])
        ->and(($this->fehler)('Möhren: frisch, Bund', null))->toBe([]);
});

it('Gegenprobe: die übrigen Verpackungswörter bleiben verboten', function () {
    foreach (['Tomaten: konserviert, Dose', 'Zimt: trocken, Beutel', 'Möhren: frisch, Karton', 'Gurken: konserviert, Glas', 'Mehl: trocken, Gebinde'] as $name) {
        expect(($this->fehler)($name, '01'))->not->toBe([], $name);
    }
});

it('Die Regel gp.7.1.gebinde führt Bund und Stange nicht mehr, Glas/Dose/Karton weiter', function () {
    $tokens = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'gp.7.1.gebinde')->firstOrFail()->params['tokens'];

    expect($tokens)->not->toContain('Bund')->not->toContain('Stange')->toContain('Glas')->toContain('Dose')->toContain('Karton');
});
