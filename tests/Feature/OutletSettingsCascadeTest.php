<?php

use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 (Betriebs-/Kunden-Kalkulation) — Slice A: Outlet-Override-Speicher + Resolver-Kaskade.
 * Auflösung Outlet → Team → Code-Default (TeamSettingsService::skalar); Fixkosten Per-Block-Replace.
 * Additiv/reversibel: outlet=null == heutiges Team-Verhalten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->settings = app(TeamSettingsService::class);
    $this->outletSettings = app(OutletSettingsService::class);
    $this->fix = app(FixkostenService::class);

    $this->betrieb = function ($team, string $name) {
        return FoodAlchemistOutlet::create(['team_id' => $team->id, 'name' => $name]);
    };
});

it('Skalar-Kaskade: Outlet-Override > Team-Wert > Code-Default', function () {
    // Team-Marge bewusst != Default (15), damit Team-Wert und Default unterscheidbar sind.
    $this->settings->update($this->childA, ['margin_pct' => 22]);
    $mitOverride = ($this->betrieb)($this->childA, 'Standort Süd');
    $ohneOverride = ($this->betrieb)($this->childA, 'Standort Nord');

    // Ohne Betrieb + Betrieb-ohne-Override erben den Team-Wert.
    expect($this->settings->margePct($this->childA))->toBe(22.0)
        ->and($this->settings->margePct($this->childA, $ohneOverride))->toBe(22.0);

    // Outlet-Override gewinnt; Team bleibt unberührt.
    $this->outletSettings->update($this->childA, $mitOverride, ['margin_pct' => 25]);
    expect($this->settings->margePct($this->childA, $mitOverride))->toBe(25.0)
        ->and($this->settings->margePct($this->childA))->toBe(22.0)
        ->and($this->settings->margePct($this->childA, $ohneOverride))->toBe(22.0);

    // Team ganz ohne Zeile ⇒ Code-Default.
    expect($this->settings->margePct($this->childB))->toBe(TeamSettingsService::MARGE_DEFAULT);
});

it('Tenancy-Guard: fremder Betrieb wird ignoriert (fällt aufs eigene Team zurück)', function () {
    $this->settings->update($this->childA, ['margin_pct' => 22]);
    $this->settings->update($this->childB, ['margin_pct' => 40]);

    $betriebB = ($this->betrieb)($this->childB, 'B-Standort');
    $this->outletSettings->update($this->childB, $betriebB, ['margin_pct' => 25]);

    // childA fragt mit einem Betrieb aus childB → Override wird ignoriert, eigener Team-Wert.
    expect($this->settings->margePct($this->childA, $betriebB))->toBe(22.0);
});

it('Fixkosten Per-Block-Replace: Betrieb ersetzt nur seine Blöcke, Rest erbt vom Team', function () {
    $this->fix->create($this->childA, ['label' => 'Miete', 'amount' => 1000, 'block_key' => 'fertigungs_gk']);
    $this->fix->create($this->childA, ['label' => 'Verwaltung', 'amount' => 500, 'block_key' => 'verwaltung']);

    $satellit = ($this->betrieb)($this->childA, 'Satellit');
    $this->fix->create($this->childA, ['label' => 'Miete Satellit', 'amount' => 200, 'block_key' => 'fertigungs_gk'], $satellit);

    $team = $this->fix->summeJeBlock($this->childA);
    $outlet = $this->fix->summeJeBlock($this->childA, $satellit);

    expect($team['fertigungs_gk'])->toBe(1000.0)
        ->and($team['verwaltung'])->toBe(500.0)
        // fertigungs_gk ersetzt (200), verwaltung geerbt (500).
        ->and($outlet['fertigungs_gk'])->toBe(200.0)
        ->and($outlet['verwaltung'])->toBe(500.0);
});

it('Backward-Compat: Team-Liste + Team-Summe sehen keine Betriebs-Zeilen', function () {
    $this->fix->create($this->childA, ['label' => 'Miete', 'amount' => 1000, 'block_key' => 'fertigungs_gk']);
    $satellit = ($this->betrieb)($this->childA, 'Satellit');
    $this->fix->create($this->childA, ['label' => 'Miete Satellit', 'amount' => 200, 'block_key' => 'fertigungs_gk'], $satellit);

    expect($this->fix->liste($this->childA))->toHaveCount(1)
        ->and((float) $this->fix->summeJeBlock($this->childA)['fertigungs_gk'])->toBe(1000.0)
        ->and($this->fix->listeFuerOutlet($this->childA, $satellit))->toHaveCount(1);
});
