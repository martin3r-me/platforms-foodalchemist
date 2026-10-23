<?php

use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk v3.6.1 §9-Zusatz (Dominique 23.10.2026): Öle (WG 11) und Sirupe (WG 10/12) tragen — wie Getränke (WG 15) —
 * KEIN Zustandswort im Namen; das Feld `condition` bleibt leer. Der Validator darf das nicht blocken (Zustand ist nur dann
 * zu prüfen, wenn er gesetzt ist), und die Verpackungsregel gp.7.1.gebinde bleibt für diese Ware unverändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fehler = fn (string $name, ?string $wg, ?string $zustand = null) => app(GpNamingService::class)
        ->validateGpName($name, array_filter(['warengruppe' => $wg, 'condition' => $zustand], fn ($v) => $v !== null))['errors'];
});

it('Öle (WG 11) ohne Zustandswort und ohne condition sind gültig', function () {
    foreach (['Olivenöl', 'Olivenöl: extra vergine / (Bio)', 'Rapsöl / Pflanzenöl: raffiniert', 'Sesamöl: geröstet', 'Basilikumöl', 'Olivenöl mit Zitrone'] as $name) {
        expect(($this->fehler)($name, '11'))->toBe([], $name);
    }
});

it('Sirupe (WG 10 und 12) ohne Zustandswort und ohne condition sind gültig', function () {
    foreach (['Ahornsirup', 'Ahornsirup / (Bio)', 'Dattelsirup', 'Glukosesirup: 45 Grad', 'Agavendicksaft'] as $name) {
        expect(($this->fehler)($name, '10'))->toBe([], $name)
            ->and(($this->fehler)($name, '12'))->toBe([], $name);
    }
});

it('Ein gesetzter, aber nicht kanonischer Zustand bleibt ein Fehler (§9), auch bei Öl', function () {
    expect(($this->fehler)('Olivenöl', '11', 'flüssig'))->not->toBe([]);
});

it('Verpackungswörter bleiben bei Ölen und Sirupen verboten (§7.1, Ausnahme nur WG 15)', function () {
    expect(($this->fehler)('Olivenöl: Glas 0,5 l', '11'))->not->toBe([])
        ->and(($this->fehler)('Ahornsirup: Dose', '10'))->not->toBe([]);
});
