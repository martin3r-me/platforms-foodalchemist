<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Herstellkosten;
use Platform\FoodAlchemist\Services\CatalogPricingService;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\KalkulationService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M-K6 / Doc 16 §10.2: Fixkosten → abgeleitete Gemeinkosten-Zuschläge (mehrstufig).
 * Material-GK auf Wareneinsatz, Fertigungs-GK auf Fertigungslohn, Logistik auf HK.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fix = app(FixkostenService::class);
    $this->kalk = app(KalkulationService::class);
    $this->settings = app(TeamSettingsService::class);

    // Drei GK-Blöcke auf „abgeleitet" + Bezugsbasen (monatlich) + Stundensatz/Marge.
    $this->settings->update($this->rootTeam, [
        'stundensatz_eur' => 30, 'margin_pct' => 15,
        'calculation_reference_bases' => ['mek' => 20000, 'fek' => 4000, 'hk' => 30000],
        'calculation_schema' => [
            ['key' => 'lohn', 'label' => 'Lohn', 'type' => 'arbeitszeit', 'value' => 0, 'active' => true, 'sort' => 10, 'mode' => 'manuell'],
            ['key' => 'gemeinkosten', 'label' => 'Material-GK', 'type' => 'pct_mek', 'value' => 0, 'active' => true, 'sort' => 40, 'mode' => 'abgeleitet'],
            ['key' => 'fertigungs_gk', 'label' => 'Fertigungs-GK', 'type' => 'pct_fek', 'value' => 0, 'active' => true, 'sort' => 50, 'mode' => 'abgeleitet'],
            ['key' => 'logistik', 'label' => 'Logistik', 'type' => 'pct_hk', 'value' => 0, 'active' => true, 'sort' => 70, 'mode' => 'abgeleitet'],
        ],
    ]);
    $this->fix->create($this->rootTeam, ['label' => 'Einkauf/Lager', 'amount' => 4000, 'periode' => 'monatlich', 'block_key' => 'gemeinkosten']);
    $this->fix->create($this->rootTeam, ['label' => 'Spüle/Energie', 'amount' => 2000, 'periode' => 'monatlich', 'block_key' => 'fertigungs_gk']);
    $this->fix->create($this->rootTeam, ['label' => 'LKW', 'amount' => 1500, 'periode' => 'monatlich', 'block_key' => 'logistik']);
});

it('leitet die Zuschlag-Sätze aus Fixkosten ÷ Bezugsbasis ab', function () {
    $schema = collect($this->fix->aufgeloestesSchema($this->rootTeam))->keyBy('key');

    expect($schema['gemeinkosten']['value'])->toBe(20.0)   // 4000 / 20000 (MEK)
        ->and($schema['fertigungs_gk']['value'])->toBe(50.0) // 2000 / 4000 (FEK)
        ->and($schema['logistik']['value'])->toBe(5.0);      // 1500 / 30000 (HK)
});

it('rechnet mehrstufig mit den abgeleiteten Sätzen', function () {
    // MEK 10; FEK = 20 min @ 30 €/h = 10; MGK 20%×10=2; FGK 50%×10=5; HK=27; Logistik 5%×27=1,35.
    $r = $this->kalk->berechne($this->rootTeam, 10.0, 20.0, 0.0);

    expect($r['fek'])->toBe(10.0)
        ->and($r['hk'])->toBe(27.0)
        ->and($r['hk2'])->toBe(28.35)                      // 27 + 1,35 Logistik
        ->and($r['vk_vorschlag'])->toBe(32.6);             // 28,35 × 1,15
});

it('normalisiert jährliche Fixkosten auf Monatsbasis', function () {
    $this->fix->create($this->rootTeam, ['label' => 'Versicherung', 'amount' => 12000, 'periode' => 'jaehrlich', 'block_key' => 'logistik']);
    // logistik jetzt 1500 + 1000 (12000/12) = 2500 / 30000 (HK) = 8,33 %.
    $schema = collect($this->fix->aufgeloestesSchema($this->rootTeam))->keyBy('key');

    expect($schema['logistik']['value'])->toBe(8.33);
});

it('ohne Bezugsbasis bleibt der abgeleitete Satz 0 (keine Division durch 0)', function () {
    $this->settings->update($this->rootTeam, ['calculation_reference_bases' => ['mek' => 0, 'fek' => 0, 'hk' => 0]]);
    $schema = collect($this->fix->aufgeloestesSchema($this->rootTeam))->keyBy('key');

    expect($schema['gemeinkosten']['value'])->toBe(0.0)
        ->and($schema['logistik']['value'])->toBe(0.0);
});

it('setzt den gekennzeichneten Catering-Beispielsatz nur in einen leeren Bestand ein', function () {
    $team = $this->rootTeam;
    $this->fix->liste($team)->each(fn ($row) => $this->fix->delete($team, $row->id));

    $this->fix->cateringBeispielwerte($team);

    expect($this->fix->liste($team))->toHaveCount(count(FixkostenService::CATERING_EXAMPLE_COSTS))
        ->and($this->fix->liste($team)->sum(fn ($row) => $row->monatsbetrag()))->toBe(15000.0)
        ->and(fn () => $this->fix->cateringBeispielwerte($team))->toThrow(RuntimeException::class);
});

it('rechnet den Catering-Beispielsatz in der Einstellungsseite sofort bis zum Basissatz durch', function () {
    $team = $this->rootTeam;
    $this->fix->liste($team)->each(fn ($row) => $this->fix->delete($team, $row->id));
    $this->actingAs($this->makeUser($team));

    Livewire::test(Herstellkosten::class)
        ->call('cateringBeispielwerte')
        ->assertSet('fehler', null)
        ->assertSet('meldung', 'Catering-Beispielwerte berechnet. Bitte anschließend auf den eigenen Betrieb anpassen.');

    expect($this->settings->bezugsbasen($team))->toEqual(FixkostenService::CATERING_EXAMPLE_BASES)
        ->and(app(CatalogPricingService::class)->enterpriseBaseRate($team)['source'])->toBe('kostenstruktur');
});
