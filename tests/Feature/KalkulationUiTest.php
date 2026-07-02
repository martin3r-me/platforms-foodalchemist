<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Kalkulation\Index as KalkulationIndex;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #379+ Kalkulations-Werkstatt (Umbau 2026-06/07): der Screen ist das Controlling-
 * Zentrum (Regeln + Kennzahlen: effektiver HK2-Zuschlag, Fixkosten/Monat, Break-even),
 * NICHT mehr die per-Gericht-Liste — die wohnt im Concepter/Verkaufs-Kontext.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    app(TeamSettingsService::class)->update($this->rootTeam, ['hk2_zuschlag_pct' => 20, 'marge_pct' => 15]);
});

it('Werkstatt rendert Controlling-Kennzahlen: effektiver Zuschlag, Regeln, Break-even', function () {
    // Fixkosten 3.000 €/Monat + Ziel-Wareneinsatz 30 % → Break-even = 3000 ÷ 0,7
    app(FixkostenService::class)->create($this->rootTeam, ['bezeichnung' => 'Miete', 'betrag' => 3000, 'block_key' => 'gemeinkosten']);
    app(TeamSettingsService::class)->update($this->rootTeam, ['ziel_wareneinsatz_pct' => 30]);

    Livewire::test(KalkulationIndex::class)
        ->assertOk()
        ->assertViewHas('zuschlag', fn ($z) => abs((float) $z - 20.0) < 0.01)
        ->assertViewHas('regeln', fn ($r) => (float) $r['marge_pct'] === 15.0)
        ->assertViewHas('fixkostenMonat', fn ($f) => abs((float) $f - 3000.0) < 0.01)
        ->assertViewHas('breakEven', fn ($b) => abs((float) $b - 3000 / 0.7) < 0.5);
});

it('kosten-aktualisiert-Event rendert die Kacheln neu (eingebetteter Editor-Save)', function () {
    $lw = Livewire::test(KalkulationIndex::class)->assertOk();

    app(TeamSettingsService::class)->update($this->rootTeam, ['hk2_zuschlag_pct' => 35]);
    $lw->dispatch('kosten-aktualisiert')
        ->assertViewHas('zuschlag', fn ($z) => abs((float) $z - 35.0) < 0.01);
});
