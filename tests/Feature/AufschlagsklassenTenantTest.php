<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Aufschlagsklassen;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MVP-039 (Audit 23, P0): Die Aufschlagsklassen-Settings mutierten über ungescopte
 * `find`/`findOrFail` — ein Kind-Team konnte geerbte (Ancestor-) und globale (`team_id NULL`)
 * Klassen bearbeiten, deaktivieren und löschen. `delete()` blockierte nur ein abweichendes
 * nicht-null `team_id`, ließ also globale Zeilen durch. MVP-040: der Verwendungszähler zählte
 * teamübergreifend und konnte fremde Nutzung verraten.
 *
 * Regel: sichtbar (global + Ancestry) darf gelesen und am eigenen Gericht referenziert werden;
 * mutieren darf NUR das Besitzer-Team. Globale Zeilen gehören niemandem → für alle read-only.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->eigene = FoodAlchemistMarkupClass::create([
        'team_id' => $this->childA->id, 'code' => 'A-STD', 'label' => 'Standard A',
        'raw_markup_pct' => 200, 'service_pct' => 0, 'profit_pct' => 0, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);
    $this->geerbte = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id, 'code' => 'ROOT-STD', 'label' => 'Master-Standard',
        'raw_markup_pct' => 250, 'service_pct' => 0, 'profit_pct' => 0, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);
    $this->globale = FoodAlchemistMarkupClass::create([
        'team_id' => null, 'code' => 'GLOB-STD', 'label' => 'Global-Standard',
        'raw_markup_pct' => 260, 'service_pct' => 0, 'profit_pct' => 0, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);

    $this->actingAs($this->makeUser($this->childA, 'Kind A User'));
});

it('speichert keine Änderung an einer geerbten Klasse (MVP-039)', function () {
    Livewire::test(Aufschlagsklassen::class)
        ->set('editId', $this->geerbte->id)
        ->set('form', ['label' => 'Gekapert', 'raw_markup_pct' => '999', 'service_pct' => '0', 'profit_pct' => '0', 'vat_rate' => '19', 'formula_type' => 'aufschlag', 'note' => ''])
        ->call('save')
        ->assertSet('fehler', fn ($m) => is_string($m) && $m !== '');

    expect($this->geerbte->fresh()->label)->toBe('Master-Standard')
        ->and((float) $this->geerbte->fresh()->raw_markup_pct)->toBe(250.0);
});

it('speichert keine Änderung an einer globalen Klasse (MVP-039)', function () {
    Livewire::test(Aufschlagsklassen::class)
        ->set('editId', $this->globale->id)
        ->set('form', ['label' => 'Gekapert', 'raw_markup_pct' => '1', 'service_pct' => '0', 'profit_pct' => '0', 'vat_rate' => '19', 'formula_type' => 'aufschlag', 'note' => ''])
        ->call('save')
        ->assertSet('fehler', fn ($m) => is_string($m) && $m !== '');

    expect($this->globale->fresh()->label)->toBe('Global-Standard');
});

it('deaktiviert keine geerbte oder globale Klasse (MVP-039)', function () {
    Livewire::test(Aufschlagsklassen::class)
        ->call('toggleInactive', $this->geerbte->id)
        ->call('toggleInactive', $this->globale->id);

    expect((bool) $this->geerbte->fresh()->is_inactive)->toBeFalse()
        ->and((bool) $this->globale->fresh()->is_inactive)->toBeFalse();
});

it('löscht keine globale Klasse — das Loch der team_id-null-Zeile (MVP-039)', function () {
    Livewire::test(Aufschlagsklassen::class)
        ->call('delete', $this->globale->id)
        ->assertSet('fehler', fn ($m) => is_string($m) && $m !== '');

    expect(FoodAlchemistMarkupClass::withTrashed()->find($this->globale->id)->deleted_at)->toBeNull();
});

it('bearbeitet und löscht die EIGENE Klasse weiterhin', function () {
    Livewire::test(Aufschlagsklassen::class)
        ->call('edit', $this->eigene->id)
        ->assertSet('editId', $this->eigene->id)
        ->set('form.label', 'Standard A neu')
        ->call('save')
        ->assertSet('fehler', null);

    expect($this->eigene->fresh()->label)->toBe('Standard A neu');

    Livewire::test(Aufschlagsklassen::class)->call('delete', $this->eigene->id);
    expect(FoodAlchemistMarkupClass::withTrashed()->find($this->eigene->id)->deleted_at)->not->toBeNull();
});

it('der Verwendungszähler zählt keine fremden Teams (MVP-040)', function () {
    // Eigenes Gericht nutzt die eigene Klasse …
    $this->makeRecipe($this->childA, 'Eigenes Gericht', ['is_sales_recipe' => true, 'markup_class_id' => $this->eigene->id]);
    // … Geschwister-Gericht (Team B) nutzt dieselbe Klasse — darf im Zähler von A nicht auftauchen.
    $this->makeRecipe($this->childB, 'Fremd-Gericht', ['is_sales_recipe' => true, 'markup_class_id' => $this->eigene->id]);

    Livewire::test(Aufschlagsklassen::class)
        ->assertViewHas('zaehler', fn ($z) => (int) ($z[$this->eigene->id] ?? 0) === 1);
});
