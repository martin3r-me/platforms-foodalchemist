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

it('legt einen neuen Behälter mit Maßen und Freigaben an — ohne Deployment', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Behaelter::class)
        ->set('neu.behaelter.name', 'Eimer 10 l')
        ->set('neu.behaelter.familie', 'Eimer')
        ->set('neu.behaelter.volumen_l', '10')
        ->set('neu.behaelter.nutzfaktor', '0,9')
        ->set('neu.behaelter.max_fuellgewicht_kg', '10')
        ->set('neu.behaelter.eignung', ['abfuellen', 'transport'])
        ->call('create', 'behaelter')
        ->assertSet('fehler', null);

    $b = DB::table('foodalchemist_vocab_containers')->where('slug', 'eimer_10_l')->first();

    expect($b->familie)->toBe('Eimer')
        ->and((float) $b->volumen_l)->toBe(10.0)
        ->and((float) $b->nutzfaktor)->toBe(0.9)
        ->and(json_decode((string) $b->eignung, true))->toBe(['abfuellen', 'transport'])
        ->and($b->laenge_mm)->toBeNull();                       // rund — Maße bleiben leer, kein 0
});

it('Freigabe entziehen wirkt: der Rechner rechnet danach nicht mehr, sondern nennt den Grund', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Behaelter::class)
        ->set('neu.behaelter.name', 'Eimer 10 l')
        ->set('neu.behaelter.volumen_l', '10')
        ->set('neu.behaelter.eignung', ['abfuellen', 'regenerieren'])
        ->call('create', 'behaelter');

    $id = DB::table('foodalchemist_vocab_containers')->where('slug', 'eimer_10_l')->value('id');

    Livewire::test(Behaelter::class)
        ->call('bearbeitenStart', $id)
        ->assertSet('edit.name', 'Eimer 10 l')
        ->set('edit.eignung', ['abfuellen'])                    // Kunststoff gehört nicht in den Ofen
        ->call('bearbeitenSpeichern')
        ->assertSet('fehler', null);

    $behaelter = \Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer::find($id);
    expect($behaelter->eignung)->toBe(['abfuellen']);

    $rechner = new \Platform\FoodAlchemist\Services\BehaelterRechner;
    $out = $rechner->varianten(40.0, [
        'container' => $behaelter, 'referenz_menge_kg' => 9.0, 'dichteklasse' => null,
        'skalierung' => 'tiefer_fuellbar', 'max_schichthoehe_mm' => null, 'konfidenz_rang3' => false,
    ], [], 'regenerieren');

    expect($out['berechenbar'])->toBeFalse()
        ->and($out['grund'])->toContain('regenerieren');
});

it('das Bearbeiten lässt den Slug in Ruhe — Rezepte hängen daran', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $id = ($this->behaelterAnlegen)($this->rootTeam->id, 'GN 1/1 65mm');

    Livewire::test(Behaelter::class)
        ->call('bearbeitenStart', $id)
        ->set('edit.name', 'GN 1/1 65 mm (Edelstahl)')
        ->call('bearbeitenSpeichern')
        ->assertSet('fehler', null);

    $b = DB::table('foodalchemist_vocab_containers')->find($id);
    expect($b->name)->toBe('GN 1/1 65 mm (Edelstahl)')
        ->and($b->slug)->toBe('gn_11_65mm');
});

it('das Katalog-Kommando ist ein Dry-Run und legt erst mit --apply an', function () {
    $vorher = DB::table('foodalchemist_vocab_containers')->count();

    $this->artisan('foodalchemist:behaelter-katalog')->assertExitCode(0);
    expect(DB::table('foodalchemist_vocab_containers')->count())->toBe($vorher);

    $this->artisan('foodalchemist:behaelter-katalog --apply')->assertExitCode(0);

    $gn = DB::table('foodalchemist_vocab_containers')->where('slug', 'gn_11_65mm')->first();
    // EuroNorm 631-1, nicht die Kantenrechnung (die ergaebe 11,2 l — GN ist konisch).
    expect((float) $gn->volumen_l)->toBe(9.0)
        ->and((float) $gn->laenge_mm)->toBe(530.0)
        ->and($gn->team_id)->toBeNull();                   // global — nur der Master pflegt sie

    $eimer = DB::table('foodalchemist_vocab_containers')->where('slug', 'eimer_10_l')->first();
    expect((float) $eimer->volumen_l)->toBe(10.0)
        ->and($eimer->laenge_mm)->toBeNull()               // rund: keine erfundenen Kantenmaße
        ->and(json_decode((string) $eimer->eignung, true))->toBe(['abfuellen', 'transport']);

    $box = DB::table('foodalchemist_vocab_containers')->where('slug', 'thermobox_600x400_200_mm')->first();
    expect((bool) $box->ist_traeger)->toBeTrue()
        ->and($box->traeger_plaetze)->toBeNull();          // haengt an Innenhoehe × Behaeltertiefe
});

it('das Katalog-Kommando ist idempotent — zweimal laufen legt nichts doppelt an', function () {
    $this->artisan('foodalchemist:behaelter-katalog --apply')->assertExitCode(0);
    $nach1 = DB::table('foodalchemist_vocab_containers')->count();

    $this->artisan('foodalchemist:behaelter-katalog --apply')->assertExitCode(0);

    expect(DB::table('foodalchemist_vocab_containers')->count())->toBe($nach1);
});

it('ein Team bekommt seinen eigenen Katalog, ohne den globalen zu doppeln', function () {
    $this->artisan('foodalchemist:behaelter-katalog --apply')->assertExitCode(0);
    $global = DB::table('foodalchemist_vocab_containers')->whereNull('team_id')->count();

    $this->artisan("foodalchemist:behaelter-katalog --team={$this->childA->id} --apply")->assertExitCode(0);

    expect(DB::table('foodalchemist_vocab_containers')->where('team_id', $this->childA->id)->count())->toBe($global)
        ->and(DB::table('foodalchemist_vocab_containers')->whereNull('team_id')->count())->toBe($global);
});

it('der Master pflegt globale Katalog-Zeilen, ein Kind-Team nicht', function () {
    $this->artisan('foodalchemist:behaelter-katalog --apply')->assertExitCode(0);
    $id = DB::table('foodalchemist_vocab_containers')->whereNull('team_id')->where('slug', 'gn_11_65mm')->value('id');

    // Master (Team ohne Eltern) darf — sonst bliebe der globale Grundstock ungepflegt.
    $this->actingAs($this->makeUser($this->rootTeam));
    Livewire::test(Behaelter::class)
        ->call('bearbeitenStart', $id)
        ->set('edit.max_fuellgewicht_kg', '12')
        ->call('bearbeitenSpeichern')
        ->assertSet('fehler', null);

    expect((float) DB::table('foodalchemist_vocab_containers')->find($id)->max_fuellgewicht_kg)->toBe(12.0);

    // Kind-Team sieht die Zeile (Vererbung), pflegt sie aber nicht.
    $this->actingAs($this->makeUser($this->childA));
    Livewire::test(Behaelter::class)
        ->call('bearbeitenStart', $id)
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'nur das Besitzer-Team'));
});

it('warnt, wenn ein globaler Grundstock Team-Dubletten erzeugen würde', function () {
    // Echtdaten-Fussangel: der Bestand kam per WaWi-Import in ein TEAM. Global anzulegen
    // stellt dieselben GN-Groessen ein zweites Mal daneben.
    ($this->behaelterAnlegen)($this->rootTeam->id, 'GN 1/1 65mm');

    $this->artisan('foodalchemist:behaelter-katalog')
        ->expectsOutputToContain('hat bereits Behälter mit denselben Namen')
        ->assertExitCode(0);
});
