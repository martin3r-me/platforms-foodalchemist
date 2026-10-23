<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Behaelter;
use Platform\FoodAlchemist\Livewire\Settings\Posten;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einstellungen E-3c (Dominique 23.10.): Listen statt Formularzeilen. Posten: Zeile mit Name, Bereich, Minuten je Tag, Besetzung in
 * Kurzform, Details erst aufgeklappt; Anlegen auf „+ Posten“. Behälter & Geräte: Katalog mit Zähler, Chips (12 sichtbar, Rest auf
 * Klick), „Neu“ als Dialog. Die Bedienung (feldSetzen, besetzungSetzen, create …) bleibt dieselbe.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.bearbeitungssperre' => false]);
    $this->actingAs($this->makeUser($this->rootTeam, 'Admin', 'owner'));
});

it('Posten: Zeile zeigt Besetzung in Kurzform und Minuten, Details aufklappbar, Anlegen erst auf Klick', function () {
    $koch = FoodAlchemistKitchenRole::create(['team_id' => $this->rootTeam->id, 'slug' => 'koch', 'name' => 'Koch', 'stundensatz_eur' => 40]);
    $hilfe = FoodAlchemistKitchenRole::create(['team_id' => $this->rootTeam->id, 'slug' => 'hilfe', 'name' => 'Hilfe', 'stundensatz_eur' => 25]);
    $wk = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'wk', 'name' => 'Warme Küche', 'group_name' => 'Produktion']);
    $wk->forceFill(['besetzung' => [(string) $koch->id => 2, (string) $hilfe->id => 1], 'schicht_minuten' => 320])->save();
    FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'sp', 'name' => 'Spüle']);

    $html = Livewire::test(Posten::class)->html();

    $zeile = substr($html, strpos($html, 'data-posten="' . $wk->id . '"'));                            // die Zeile der Warmen Küche
    preg_match('/data-posten-besetzung-kurz>([^<]*)</', $zeile, $kurz);
    expect($kurz[1] ?? '')->toContain('2 Koch')->toContain('1 Hilfe')->toContain(' · ');                // Reihenfolge = Reihenfolge der Rollen
    expect($html)->toContain('data-posten-liste')->toContain('960 min')   // 3 Köpfe × 320 min
        ->toContain('nicht besetzt')
        ->toContain('data-posten-details')->toContain('x-show="offen"')                                    // Details im DOM, eingeklappt
        ->toContain('data-posten-neu')->toContain('x-show="neuOffen"')
        ->toContain('data-settings-info');                                                                // Hilfe als (i)

    Livewire::test(Posten::class)->call('feldSetzen', $wk->id, 'name', 'Warme Küche Nord');
    expect($wk->fresh()->name)->toBe('Warme Küche Nord');
});

it('Behälter & Geräte: Zähler je Liste, ab 13 Einträgen „+ x weitere“, Neu und Bearbeiten im Dialog', function () {
    foreach (range(1, 14) as $i) {
        DB::table('foodalchemist_vocab_serving_vehicles')->insert(['uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
            'slug' => 'v' . $i, 'name' => 'Vehikel ' . $i, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
    }
    $gn = DB::table('foodalchemist_vocab_containers')->insertGetId(['uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'gn11', 'name' => 'GN 1/1', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $c = Livewire::test(Behaelter::class);
    expect($c->html())->toContain('data-vokabular="vehikel"')->toContain('data-vokabular-alle="vehikel"')->toContain('+ 2 weitere')
        ->toContain('data-behaelter-dialog')->toContain('data-vokabular-anlegen="regen"')->not->toContain('data-behaelter-editform');

    $c->call('bearbeitenStart', $gn)->assertSeeHtml('data-behaelter-editform')->call('zeileAbbrechen')->assertDontSeeHtml('data-behaelter-editform');

    $c->set('neu.regen.name', 'Kombidämpfer')->call('create', 'regen');
    expect(DB::table('foodalchemist_vocab_regeneration_devices')->where('name', 'Kombidämpfer')->exists())->toBeTrue();
});
