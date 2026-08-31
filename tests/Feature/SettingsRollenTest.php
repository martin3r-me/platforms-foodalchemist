<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Rollen;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole as Role;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Stufe 3 P3.1 — Küchen-Rollen mit Kostensatz. Team-eigene Stammdaten, Muster wie Posten:
 * Slug-Dublettenschutz, Inline-Edit, Lösch-Schutz (nur stilllegen), Komma-Satz.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('legt eine Rolle mit Satz an und blockt die Dublette', function () {
    Livewire::test(Rollen::class)
        ->set('neu.name', 'Koch')->set('neu.satz', '35')
        ->call('create')
        ->assertSet('fehler', null)
        ->assertSet('meldung', fn ($m) => str_contains((string) $m, 'angelegt'));

    $rolle = Role::where('team_id', $this->rootTeam->id)->where('slug', 'koch')->first();
    expect($rolle)->not->toBeNull()
        ->and($rolle->name)->toBe('Koch')
        ->and((float) $rolle->stundensatz_eur)->toBe(35.0);

    // Dublette über Slug
    Livewire::test(Rollen::class)
        ->set('neu.name', 'Koch')->set('neu.satz', '40')
        ->call('create')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'gibt es schon'));

    // Name ist Pflicht
    Livewire::test(Rollen::class)
        ->set('neu.name', '')->call('create')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Pflicht'));
});

it('liest den Satz mit Komma und leer = flacher Team-Satz (null)', function () {
    $r = Role::create(['team_id' => $this->rootTeam->id, 'slug' => 'huko', 'name' => 'Hilfskoch', 'stundensatz_eur' => 22.5]);

    Livewire::test(Rollen::class)->call('feldSetzen', $r->id, 'satz', '18,50');
    expect((float) $r->fresh()->stundensatz_eur)->toBe(18.5);

    Livewire::test(Rollen::class)->call('feldSetzen', $r->id, 'satz', '');
    expect($r->fresh()->stundensatz_eur)->toBeNull();     // leer ⇒ flacher Team-Stundensatz greift
});

it('benennt um und legt still statt zu löschen', function () {
    $r = Role::create(['team_id' => $this->rootTeam->id, 'slug' => 'chef', 'name' => 'Küchenchef', 'stundensatz_eur' => 55]);

    Livewire::test(Rollen::class)->call('feldSetzen', $r->id, 'name', 'Souschef');
    expect($r->fresh()->name)->toBe('Souschef');

    Livewire::test(Rollen::class)->call('aktivToggle', $r->id);
    expect($r->fresh()->is_inactive)->toBeTrue();
    expect(Role::withTrashed()->find($r->id))->not->toBeNull();   // nicht gelöscht
});

it('Betrieb-Scope: Satz-Override landet in outlet_role_rates; leer entfernt ihn', function () {
    $r = Role::create(['team_id' => $this->rootTeam->id, 'slug' => 'koch', 'name' => 'Koch', 'stundensatz_eur' => 28]);
    $betrieb = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Nord']);

    Livewire::test(Rollen::class)->set('outletId', $betrieb->id)->call('feldSetzen', $r->id, 'satz', '32');
    $s = app(OutletSettingsService::class)->for($betrieb->fresh());
    expect((float) $s->outlet_role_rates[(string) $r->id])->toBe(32.0)
        ->and((float) $r->fresh()->stundensatz_eur)->toBe(28.0);   // Team-Rolle unberührt

    // leer = Override entfernen (erbt wieder Team-Satz).
    Livewire::test(Rollen::class)->set('outletId', $betrieb->id)->call('feldSetzen', $r->id, 'satz', '');
    expect(app(OutletSettingsService::class)->for($betrieb->fresh())->outlet_role_rates)->toBeNull();
});

it('Betrieb-Scope legt keine Rollen an + toggelt nicht (nur Sätze)', function () {
    $r = Role::create(['team_id' => $this->rootTeam->id, 'slug' => 'koch', 'name' => 'Koch', 'stundensatz_eur' => 28]);
    $betrieb = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Nord']);

    Livewire::test(Rollen::class)->set('outletId', $betrieb->id)
        ->set('neu.name', 'Neu')->call('create')
        ->call('aktivToggle', $r->id);

    expect(Role::where('team_id', $this->rootTeam->id)->where('slug', 'neu')->exists())->toBeFalse()
        ->and($r->fresh()->is_inactive)->toBeFalse();
});

it('editiert keine geerbte Rolle aus dem Eltern-Team', function () {
    // Rolle im Eltern-Team (rootTeam ist Elternteil der Kinder in SeedsTeamHierarchy).
    $elternRolle = Role::create(['team_id' => $this->rootTeam->id, 'slug' => 'koch', 'name' => 'Koch', 'stundensatz_eur' => 30]);

    // Als Kind-Team-User: geerbte Rolle ist sichtbar (Vorlage), aber nicht schreibbar.
    $this->actingAs($this->makeUser($this->childA));
    Livewire::test(Rollen::class)
        ->call('feldSetzen', $elternRolle->id, 'satz', '99')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Schreibzugriff'));

    expect((float) $elternRolle->fresh()->stundensatz_eur)->toBe(30.0);   // unverändert
});
