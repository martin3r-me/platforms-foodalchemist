<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Behaelter;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeContainer;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 51 — zwei Live-Defekte der Behälter-Einstellungen, gefunden beim Aufbau der
 * Behälter-Bemessung:
 *
 *  E1  `referenzen()` zählte nur die `*_legacy_id`-Rohwerte des Imports und stieg bei
 *      `legacy_id === null` mit 0 aus → jede kundeneigene Zeile war hart löschbar, egal wie oft
 *      sie genutzt wurde. Weil alle Bestands-FKs `nullOnDelete` sind, hätte das den Behälter an
 *      Rezepten und Darreichungen STILL entfernt.
 *  E2  Der Slug-Dublettencheck lief ohne Team-Filter, während die DB-Unique `(team_id, slug)`
 *      ist → Geschwister-Teams blockierten sich gegenseitig, und die Meldung verriet die
 *      Existenz fremder Einträge.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->behaelterAnlegen = function (int $teamId, string $name, ?int $legacyId = null): int {
        return DB::table('foodalchemist_vocab_containers')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'team_id' => $teamId,
            'legacy_id' => $legacyId,
            'slug' => Str::slug($name, '_'),
            'name' => $name,
            'sort_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('E1: ein kundeneigener Behälter am Rezept ist nicht mehr still löschbar', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    // legacy_id NULL = selbst angelegt. Genau der Fall, den die alte Zählung mit 0 quittierte.
    $id = ($this->behaelterAnlegen)($this->rootTeam->id, 'Eimer 10 l');

    $rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'r1', 'name' => 'Fond: Braun', 'status' => 'approved',
    ]);
    $rezept->forceFill(['container_warm_vocab_id' => $id])->save();

    Livewire::test(Behaelter::class)
        ->call('delete', 'behaelter', $id)
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'in Verwendung'));

    expect(DB::table('foodalchemist_vocab_containers')->find($id))->not->toBeNull();
});

it('E1: auch eine Zweck-Zeile in recipe_containers hält den Behälter fest', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $id = ($this->behaelterAnlegen)($this->rootTeam->id, 'GN 1/1 65mm Eigen');
    $rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'r2', 'name' => 'Ragout: Rind', 'status' => 'approved',
    ]);

    FoodAlchemistRecipeContainer::create([
        'team_id' => $this->rootTeam->id,
        'recipe_id' => $rezept->id,
        'zweck' => FoodAlchemistRecipeContainer::ZWECK_ABFUELLEN,
        'container_vocab_id' => $id,
        'referenz_menge_kg' => 8.0,
    ]);

    Livewire::test(Behaelter::class)
        ->call('delete', 'behaelter', $id)
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'in Verwendung'));

    expect(DB::table('foodalchemist_vocab_containers')->find($id))->not->toBeNull();
});

it('E1: ein wirklich unbenutzter Behälter lässt sich weiterhin löschen', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $id = ($this->behaelterAnlegen)($this->rootTeam->id, 'Nie Benutzt');

    Livewire::test(Behaelter::class)
        ->call('delete', 'behaelter', $id)
        ->assertSet('fehler', null);

    expect(DB::table('foodalchemist_vocab_containers')->find($id))->toBeNull();
});

it('E2: Geschwister-Teams dürfen denselben Behälter-Namen tragen', function () {
    $this->actingAs($this->makeUser($this->childA));
    Livewire::test(Behaelter::class)
        ->set('neu.behaelter.name', 'Eimer 10 l')
        ->call('create', 'behaelter')
        ->assertSet('fehler', null);

    // Sibling: eigener Bestand, eigener Slug-Raum — die DB-Unique ist (team_id, slug).
    $this->actingAs($this->makeUser($this->childB));
    Livewire::test(Behaelter::class)
        ->set('neu.behaelter.name', 'Eimer 10 l')
        ->call('create', 'behaelter')
        ->assertSet('fehler', null);

    expect(DB::table('foodalchemist_vocab_containers')->where('slug', 'eimer_10_l')->count())->toBe(2);
});

it('E2: im EIGENEN Team bleibt die Dublette blockiert', function () {
    $this->actingAs($this->makeUser($this->childA));

    Livewire::test(Behaelter::class)
        ->set('neu.behaelter.name', 'Eimer 10 l')->call('create', 'behaelter')->assertSet('fehler', null);

    Livewire::test(Behaelter::class)
        ->set('neu.behaelter.name', 'Eimer 10 l')->call('create', 'behaelter')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'existiert schon'));

    expect(DB::table('foodalchemist_vocab_containers')->where('slug', 'eimer_10_l')->count())->toBe(1);
});
