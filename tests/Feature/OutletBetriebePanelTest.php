<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Betriebe;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Slice D (Panel): das Betriebe-Panel pflegt nur noch Identität/Optik/Vorlage.
 * Die Kalkulations-Overrides (Marge/Ziel-WE/Stundensatz/Material-GK/Lohnneben. + eigenes
 * Zuschlagsschema/Fixkosten/Bezugsbasen) wohnen jetzt unter „Herstellkosten & Zuschläge"
 * (siehe HerstellkostenOutletEditorTest). (Browser-Layout gesondert abnehmen.)
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Panel speichert Identität + Präsentations-Vorlage des Betriebs', function () {
    $outlet = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine']);

    Livewire::test(Betriebe::class)
        ->call('edit', $outlet->id)
        ->set('form.name', 'Kantine Nord')
        ->set('form.color', '#6d28d9')
        ->set('form.vorlage', 'navigator')
        ->call('speichern')
        ->assertHasNoErrors();

    $fresh = $outlet->fresh();
    expect($fresh->name)->toBe('Kantine Nord')
        ->and($fresh->color)->toBe('#6d28d9')
        ->and($fresh->presentation_design)->toBe('navigator');
});

it('Panel fasst die Kosten-Overrides nicht mehr an (die wohnen in Herstellkosten)', function () {
    $outlet = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Filiale']);
    // Vorbestehender Override (z. B. via Herstellkosten gesetzt) bleibt beim Betriebe-Speichern unberührt.
    app(OutletSettingsService::class)->update($this->rootTeam, $outlet, ['margin_pct' => 42]);

    Livewire::test(Betriebe::class)
        ->call('edit', $outlet->id)
        ->set('form.name', 'Filiale West')
        ->call('speichern')
        ->assertHasNoErrors();

    $s = app(OutletSettingsService::class)->for($outlet->fresh());
    expect((float) $s->margin_pct)->toBe(42.0);   // unverändert
});
