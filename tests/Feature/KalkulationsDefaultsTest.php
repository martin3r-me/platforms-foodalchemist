<?php

use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M1-07: Kalkulations-Defaults — der M4-Lese-Vertrag (RecomputeService
 * liest garverlustDefault()/mwst()/rundung()).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->settings = app(TeamSettingsService::class);
});

it('liefert Code-Defaults ohne gespeicherte Zeile', function () {
    expect($this->settings->garverlustDefault($this->childA, '04'))->toBeNull()
        ->and($this->settings->mwst($this->childA))->toBe(TeamSettingsService::MWST_DEFAULTS)
        ->and($this->settings->rundung($this->childA))->toBe(TeamSettingsService::RUNDUNG_DEFAULTS);
});

it('Garverlust: WG-spezifisch schlägt global (*), fehlend = null (GL-02-Kaskade)', function () {
    $this->settings->update($this->childA, ['cooking_loss_defaults' => ['*' => 5.0, '04' => 22.5]]);

    expect($this->settings->garverlustDefault($this->childA, '04'))->toBe(22.5)
        ->and($this->settings->garverlustDefault($this->childA, '01'))->toBe(5.0)
        ->and($this->settings->garverlustDefault($this->childB, '04'))->toBeNull(); // Team-isoliert
});

it('MwSt + Rundung: gespeicherte Werte überlagern Defaults feldweise', function () {
    $this->settings->update($this->childA, [
        'vat_defaults' => ['default_satz' => 'regulaer'],
        'rundungsregeln' => ['nachkommastellen' => 3],
    ]);

    expect($this->settings->mwst($this->childA))->toBe(['regulaer' => 19.0, 'ermaessigt' => 7.0, 'default_satz' => 'regulaer'])
        ->and($this->settings->rundung($this->childA))->toBe(['nachkommastellen' => 3, 'mode' => 'kaufmaennisch']);
});
