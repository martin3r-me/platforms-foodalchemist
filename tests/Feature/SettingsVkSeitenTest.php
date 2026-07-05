<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Aufschlagsklassen;
use Platform\FoodAlchemist\Livewire\Settings\Behaelter;
use Platform\FoodAlchemist\Livewire\Settings\Schreibstile;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R5 (Dominique): eigene Settings-Seiten — Schreibstile/Behälter mit Anlegen
 * (V-06: nur deaktivieren), Aufschlagsklassen EDITIERBAR (GT-8-Felder,
 * Komma-Eingaben, formel_typ-Whitelist), Dubletten-Schutz über Slug/Code.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Schreibstile: anlegen (Slug + Pflichtfelder), Dublette blockt, deaktivieren statt löschen', function () {
    Livewire::test(Schreibstile::class)
        ->set('neu.name', 'Rustikal Markig')
        ->set('neu.sprach_duktus', 'Kurz, deftig, ehrlich.')
        ->call('create')
        ->assertSet('fehler', null);

    $stil = DB::table('foodalchemist_writing_styles')->where('slug', 'rustikal_markig')->first();
    expect($stil)->not->toBeNull()->and($stil->name)->toBe('Rustikal Markig');

    // Dublette über Slug
    Livewire::test(Schreibstile::class)
        ->set('neu.name', 'Rustikal Markig')->set('neu.sprach_duktus', 'x')
        ->call('create')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'existiert schon'));

    // Pflichtfeld sprach_duktus (Prompt-Material)
    Livewire::test(Schreibstile::class)
        ->set('neu.name', 'Ohne Duktus')->call('create')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Pflicht'));

    Livewire::test(Schreibstile::class)->call('toggleInactive', $stil->id);
    expect((bool) DB::table('foodalchemist_writing_styles')->find($stil->id)->is_inactive)->toBeTrue();
});

it('Behälter & Geräte: anlegen je Vokabular (inkl. Komma-Kapazität + Equipment), Whitelist hält', function () {
    Livewire::test(Behaelter::class)
        ->set('neu.behaelter.name', 'GN 1/4 65mm')->set('neu.behaelter.group_name', 'GN')->set('neu.behaelter.kapazitaet_kg', '1,2')
        ->call('create', 'behaelter')->assertSet('fehler', null);
    $b = DB::table('foodalchemist_vocab_containers')->where('slug', 'gn_14_65mm')->first();
    expect($b)->not->toBeNull()->and((float) $b->kapazitaet_kg)->toBe(1.2);

    Livewire::test(Behaelter::class)
        ->set('neu.equipment.name', 'Räucherpistole')->set('neu.equipment.group_name', 'Spezial')
        ->call('create', 'equipment')->assertSet('fehler', null);
    expect(DB::table('foodalchemist_vocab_kitchen_equipment')->where('slug', 'raucherpistole')->exists())->toBeTrue();

    // unbekanntes Vokabular ⇒ Fehler, kein Insert
    Livewire::test(Behaelter::class)->call('create', 'boese_tabelle')
        ->assertSet('fehler', fn ($f) => $f !== null);
});

it('Aufschlagsklassen: Edit mit Komma-Prozenten + formel_typ-Whitelist; Code-Dublette blockt; Validierung greift', function () {
    $ak = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id, 'code' => 'TST', 'label' => 'Test',
        'raw_markup_pct' => 100, 'vat_rate' => 19,
    ]);

    Livewire::test(Aufschlagsklassen::class)
        ->call('edit', $ak->id)
        ->set('form.raw_markup_pct', '312,5')
        ->set('form.formula_type', 'quatsch')                           // Whitelist-Fallback
        ->call('save')->assertSet('fehler', null);
    $ak->refresh();
    expect((float) $ak->raw_markup_pct)->toBe(312.5)
        ->and($ak->formula_type)->toBe('aufschlag');

    // Nicht-numerisch ⇒ Fehler, kein Write
    Livewire::test(Aufschlagsklassen::class)
        ->call('edit', $ak->id)->set('form.vat_rate', 'abc')->call('save')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Zahl'));
    expect((float) $ak->fresh()->vat_rate)->toBe(19.0);

    // Anlegen + Code-Dublette
    Livewire::test(Aufschlagsklassen::class)
        ->set('neu.code', 'tst')->set('neu.label', 'Dublette')->set('neu.raw_markup_pct', '50')
        ->call('create')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'vergeben'));
    Livewire::test(Aufschlagsklassen::class)
        ->set('neu.code', 'NEU1')->set('neu.label', 'Neue Klasse')->set('neu.raw_markup_pct', '250')
        ->call('create')->assertSet('fehler', null);
    expect(FoodAlchemistMarkupClass::where('code', 'NEU1')->first()->raw_markup_pct)->not->toBeNull();
});
