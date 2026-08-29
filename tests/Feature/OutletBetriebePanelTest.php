<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Betriebe;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Slice D (Panel): das Betriebe-Panel in den Einstellungen trägt die
 * Kalkulations-Override-Felder. Livewire-Logik: laden → speichern → leer = erbt.
 * (Browser-Layout gesondert abnehmen — Livewire::test ist layout-blind.)
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Panel rendert + speichert Kalkulations-Overrides des Betriebs; leer = erbt vom Team', function () {
    $outlet = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine']);

    Livewire::test(Betriebe::class)
        ->call('edit', $outlet->id)
        ->set('overrides.margin_pct', '33')
        ->set('overrides.stundensatz_eur', '48,50')   // Komma wird zu Punkt
        ->set('overrides.target_food_cost_pct', '')     // leer = erbt
        ->call('speichern')
        ->assertHasNoErrors();

    $s = app(OutletSettingsService::class)->for($outlet->fresh());
    expect((float) $s->margin_pct)->toBe(33.0)
        ->and((float) $s->stundensatz_eur)->toBe(48.5)
        ->and($s->target_food_cost_pct)->toBeNull();
});

it('Panel: erneutes Bearbeiten lädt die gespeicherten Overrides zurück', function () {
    $outlet = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Filiale']);
    app(OutletSettingsService::class)->update($this->rootTeam, $outlet, ['margin_pct' => 42]);

    Livewire::test(Betriebe::class)
        ->call('edit', $outlet->id)
        ->assertSet('overrides.margin_pct', '42.00');
});
