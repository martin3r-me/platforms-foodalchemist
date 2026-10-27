<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Preis\LetzterPreisService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Rückfall „letzter Preis“ im Rezept-EK (27.10.2026), ausgelagert aus RecipeRecomputeService: Rechenpreis setzen und Vorrang aktiver Preise im
 * GP-Durchschnitt. Der Vorrang hatte vorher keinen eigenen Test (Gegenprobe zum Dateigrößen-Fix blieb grün).
 */
function lpArtikel(?float $aktiv, ?float $letzter): FoodAlchemistSupplierItem
{
    $la = new FoodAlchemistSupplierItem;
    $la->setAttribute('aktiver_preis', $aktiv);
    $la->setAttribute('letzter_preis', $letzter);
    app(LetzterPreisService::class)->effektiv($la);

    return $la;
}

it('Rechenpreis: ohne aktiven Preis zählt der letzte Preis und wird gekennzeichnet; aktiver Preis bleibt', function () {
    $eingestellt = lpArtikel(null, 2.5);
    $aktiv = lpArtikel(3.0, null);
    $ohne = lpArtikel(null, null);

    expect((float) $eingestellt->aktiver_preis)->toBe(2.5)->and($eingestellt->preis_quelle)->toBe(LetzterPreisService::QUELLE_LETZTER)
        ->and((float) $aktiv->aktiver_preis)->toBe(3.0)->and($aktiv->preis_quelle)->toBe(LetzterPreisService::QUELLE_AKTIV)
        ->and($ohne->aktiver_preis)->toBeNull()->and($ohne->preis_quelle)->toBeNull();
});

it('Vorrang im Durchschnitt: mit aktiv bepreisten Artikeln fällt der letzte Preis heraus, ohne sie zählt er', function () {
    $dienst = app(LetzterPreisService::class);
    $aktiv = lpArtikel(3.0, null);
    $eingestellt = lpArtikel(null, 2.5);

    expect($dienst->vorrang(collect([$aktiv, $eingestellt]))->values()->all())->toBe([$aktiv])
        ->and($dienst->vorrang(collect([$eingestellt]))->values()->all())->toBe([$eingestellt]);
});
