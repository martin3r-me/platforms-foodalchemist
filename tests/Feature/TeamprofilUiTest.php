<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel;
use Platform\FoodAlchemist\Livewire\Settings\Index as Einstellungen;
use Platform\FoodAlchemist\Livewire\Settings\Teamprofil;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\TeamProfilService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §6: Teamprofil in den Einstellungen und Machbarkeits-Hinweis am Rezept.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->admin = $this->makeUser($this->rootTeam, 'Chef', 'owner');
    $this->actingAs($this->admin);

    $eq = fn (string $slug, string $name, bool $standard = false, ?string $gruppe = 'Garen') => FoodAlchemistVocabKochequipment::create([
        'team_id' => null, 'slug' => $slug, 'name' => $name, 'group_name' => $gruppe, 'sort_order' => 10, 'is_standard' => $standard,
    ]);
    $this->topf = $eq('topf', 'Topf', true);
    $this->kombi = $eq('kombi', 'Kombidämpfer');
    $this->sous = $eq('sous', 'Sous-vide-Stick', false, 'Spezial');
});

it('Sektion Teamprofil steht in der Navigation (Gruppe Betrieb & Küche) und rendert', function () {
    expect(Einstellungen::SEKTIONEN)->toHaveKey('teamprofil')
        ->and(Einstellungen::GRUPPEN['kueche']['sektionen'])->toContain('teamprofil');

    Livewire::test(Einstellungen::class, ['sektion' => 'teamprofil'])
        ->assertSee('Teamprofil')->assertSeeHtml('data-settings-teamprofil');
});

it('zeigt die Ausstattung dreiwertig: Standard gilt als vorhanden, der Rest ist unbekannt, mit Zähler', function () {
    $html = Livewire::test(Teamprofil::class)->html();

    expect($html)->toContain('data-teamprofil-equipment="' . $this->kombi->id . '"')
        ->toContain('Standard (gilt als vorhanden)')->toContain('Fehlt')->toContain('Vorhanden')
        ->toContain('2 von 3 Geräten sind noch unbekannt');   // Kombi + Sous unbekannt, Topf Standard
});

it('Gerät auf „Fehlt“ und „Vorhanden“ setzen, zurück auf „Unbekannt“ löscht die Angabe', function () {
    $c = Livewire::test(Teamprofil::class);

    $c->call('equipmentSetzen', $this->kombi->id, 'nicht_vorhanden')->assertSet('fehler', null);
    expect(DB::table('foodalchemist_team_profile_equipment')->where('team_id', $this->rootTeam->id)->value('status'))->toBe('nicht_vorhanden');

    $c->call('equipmentSetzen', $this->kombi->id, 'vorhanden');
    expect(DB::table('foodalchemist_team_profile_equipment')->where('equipment_id', $this->kombi->id)->value('status'))->toBe('vorhanden');

    $c->call('equipmentSetzen', $this->kombi->id, 'unbekannt');
    expect(DB::table('foodalchemist_team_profile_equipment')->count())->toBe(0);
});

it('Kennzahlen speichern: Pax, Convenience (nur DNA-Werte), Notiz; ungültige Pax geben eine Meldung', function () {
    Livewire::test(Teamprofil::class)
        ->set('maxPax', '120')->set('convenience', 'teil_convenience')->set('notizen', 'Rooftop ohne Kühlhaus')
        ->call('profilSpeichern')->assertSet('fehler', null)->assertSet('meldung', 'Kennzahlen gespeichert.');

    expect(app(TeamProfilService::class)->lies($this->rootTeam, $this->rootTeam)['profil'])
        ->toMatchArray(['max_pax_parallel' => 120, 'convenience_grad' => 'teil_convenience', 'notes' => 'Rooftop ohne Kühlhaus']);

    Livewire::test(Teamprofil::class)->set('maxPax', '0')->call('profilSpeichern')->assertSet('fehler', 'max_pax_parallel muss eine ganze Zahl ab 1 sein (oder leer).');
});

it('Flächen: anlegen, ändern, löschen', function () {
    $c = Livewire::test(Teamprofil::class)
        ->set('neueFlaeche', ['name' => 'Rooftop', 'max_pax' => '120', 'saison_von' => '5', 'saison_bis' => '9'])
        ->call('flaecheAnlegen')->assertSet('fehler', null);

    $f = app(TeamProfilService::class)->lies($this->rootTeam, $this->rootTeam)['flaechen'];
    expect($f)->toHaveCount(1)->and($f[0])->toMatchArray(['name' => 'Rooftop', 'max_pax' => 120, 'saison_von' => 5, 'saison_bis' => 9]);

    $c->call('flaecheFeld', $f[0]['id'], 'max_pax', '150');
    expect(app(TeamProfilService::class)->lies($this->rootTeam, $this->rootTeam)['flaechen'][0]['max_pax'])->toBe(150);

    $c->call('flaecheFeld', $f[0]['id'], 'saison_bis', '13')->assertSet('fehler', 'Saison-Monate liegen zwischen 1 und 12.');

    $c->call('flaecheLoeschen', $f[0]['id']);
    expect(app(TeamProfilService::class)->lies($this->rootTeam, $this->rootTeam)['flaechen'])->toBe([]);
});

it('Mitglied (kein Admin) sieht das Profil, schreiben ist gesperrt', function () {
    $member = $this->makeUser($this->rootTeam, 'Koch', 'member');
    $this->actingAs($member);

    Livewire::test(Teamprofil::class)->call('equipmentSetzen', $this->kombi->id, 'nicht_vorhanden');

    expect(DB::table('foodalchemist_team_profile_equipment')->count())->toBe(0);
});

it('Rezept-Detail: Warnung nur bei „Fehlt“, bei „Unbekannt“ nur ein ruhiger Hinweis, ohne Equipment nichts', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Jus');
    DB::table('foodalchemist_recipe_equipment')->insert(['recipe_id' => $r->id, 'equipment_id' => $this->kombi->id, 'created_at' => now(), 'updated_at' => now()]);
    $svc = app(TeamProfilService::class);

    $html = Livewire::test(DetailPanel::class, ['recipeId' => $r->id])->html();
    expect($html)->toContain('data-machbarkeit-hinweis')->toContain('Profil unvollständig')->not->toContain('data-machbarkeit-warnung');

    $svc->setzeEquipment($this->rootTeam, $this->admin, $this->kombi->id, 'nicht_vorhanden');
    $html = Livewire::test(DetailPanel::class, ['recipeId' => $r->id])->html();
    expect($html)->toContain('data-machbarkeit-warnung')->toContain('Nicht machbar ohne: Kombidämpfer')->not->toContain('data-machbarkeit-hinweis');

    $svc->setzeEquipment($this->rootTeam, $this->admin, $this->kombi->id, 'vorhanden');
    $html = Livewire::test(DetailPanel::class, ['recipeId' => $r->id])->html();
    expect($html)->not->toContain('data-machbarkeit-warnung')->not->toContain('data-machbarkeit-hinweis');

    $ohne = $this->makeRecipe($this->rootTeam, 'Salat');
    expect(Livewire::test(DetailPanel::class, ['recipeId' => $ohne->id])->html())->not->toContain('data-machbarkeit');
});
