<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Aufschlagsklassen;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — die Basissatz-Vorschau der Preisklassen folgt dem gewählten Betrieb (lokaler Wähler,
 * nicht die globale Brille). Klassenfaktoren/MwSt/Rundung bleiben teamweit (nicht getestet hier).
 * Aufbau: keine Bezugsbasen → enterpriseBaseRate nutzt ziel_we_fallback (100/target).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->childA));
    $this->team = $this->childA;
    // Team-Ziel-WE 25 % → Basissatz 4,0. Bezugsbasen 0 → Fallback-Pfad.
    app(TeamSettingsService::class)->update($this->team, [
        'target_food_cost_pct' => 25,
        'calculation_reference_bases' => ['mek' => 0, 'fek' => 0, 'hk' => 0],
    ]);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->team->id, 'name' => 'Bistro']);
});

it('ohne Betrieb zeigt der Basissatz die Team-Kostenstruktur', function () {
    $c = Livewire::test(Aufschlagsklassen::class);
    expect((float) $c->viewData('base')['factor'])->toBe(4.0)
        ->and($c->viewData('scopeOutletName'))->toBeNull();
});

it('mit gewähltem Betrieb folgt der Basissatz dem Betriebs-Override', function () {
    // Betrieb: strengere Ziel-WE 20 % → Basissatz 5,0.
    app(OutletSettingsService::class)->update($this->team, $this->betrieb, ['target_food_cost_pct' => 20]);

    $c = Livewire::test(Aufschlagsklassen::class)->set('outletId', $this->betrieb->id);
    expect((float) $c->viewData('base')['factor'])->toBe(5.0)
        ->and($c->viewData('scopeOutletName'))->toBe('Bistro');
});

it('fremder Betrieb wird ignoriert → Team-Basissatz', function () {
    $fremd = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);
    app(OutletSettingsService::class)->update($this->childB, $fremd, ['target_food_cost_pct' => 20]);

    $c = Livewire::test(Aufschlagsklassen::class)->set('outletId', $fremd->id);
    expect((float) $c->viewData('base')['factor'])->toBe(4.0)
        ->and($c->viewData('scopeOutletName'))->toBeNull();
});
