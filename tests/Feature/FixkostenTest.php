<?php

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
        'stundensatz_eur' => 30, 'marge_pct' => 15,
        'kalkulation_bezugsbasen' => ['mek' => 20000, 'fek' => 4000, 'hk' => 30000],
        'kalkulation_schema' => [
            ['key' => 'lohn', 'label' => 'Lohn', 'typ' => 'arbeitszeit', 'wert' => 0, 'aktiv' => true, 'sort' => 10, 'modus' => 'manuell'],
            ['key' => 'gemeinkosten', 'label' => 'Material-GK', 'typ' => 'pct_mek', 'wert' => 0, 'aktiv' => true, 'sort' => 40, 'modus' => 'abgeleitet'],
            ['key' => 'fertigungs_gk', 'label' => 'Fertigungs-GK', 'typ' => 'pct_fek', 'wert' => 0, 'aktiv' => true, 'sort' => 50, 'modus' => 'abgeleitet'],
            ['key' => 'logistik', 'label' => 'Logistik', 'typ' => 'pct_hk', 'wert' => 0, 'aktiv' => true, 'sort' => 70, 'modus' => 'abgeleitet'],
        ],
    ]);
    $this->fix->create($this->rootTeam, ['bezeichnung' => 'Einkauf/Lager', 'betrag' => 4000, 'periode' => 'monatlich', 'block_key' => 'gemeinkosten']);
    $this->fix->create($this->rootTeam, ['bezeichnung' => 'Spüle/Energie', 'betrag' => 2000, 'periode' => 'monatlich', 'block_key' => 'fertigungs_gk']);
    $this->fix->create($this->rootTeam, ['bezeichnung' => 'LKW', 'betrag' => 1500, 'periode' => 'monatlich', 'block_key' => 'logistik']);
});

it('leitet die Zuschlag-Sätze aus Fixkosten ÷ Bezugsbasis ab', function () {
    $schema = collect($this->fix->aufgeloestesSchema($this->rootTeam))->keyBy('key');

    expect($schema['gemeinkosten']['wert'])->toBe(20.0)   // 4000 / 20000 (MEK)
        ->and($schema['fertigungs_gk']['wert'])->toBe(50.0) // 2000 / 4000 (FEK)
        ->and($schema['logistik']['wert'])->toBe(5.0);      // 1500 / 30000 (HK)
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
    $this->fix->create($this->rootTeam, ['bezeichnung' => 'Versicherung', 'betrag' => 12000, 'periode' => 'jaehrlich', 'block_key' => 'logistik']);
    // logistik jetzt 1500 + 1000 (12000/12) = 2500 / 30000 (HK) = 8,33 %.
    $schema = collect($this->fix->aufgeloestesSchema($this->rootTeam))->keyBy('key');

    expect($schema['logistik']['wert'])->toBe(8.33);
});

it('ohne Bezugsbasis bleibt der abgeleitete Satz 0 (keine Division durch 0)', function () {
    $this->settings->update($this->rootTeam, ['kalkulation_bezugsbasen' => ['mek' => 0, 'fek' => 0, 'hk' => 0]]);
    $schema = collect($this->fix->aufgeloestesSchema($this->rootTeam))->keyBy('key');

    expect($schema['gemeinkosten']['wert'])->toBe(0.0)
        ->and($schema['logistik']['wert'])->toBe(0.0);
});
