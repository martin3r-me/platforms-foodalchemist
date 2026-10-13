<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Standortprofil\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamProfile;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamProfileEquipment;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Services\TeamProfilService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\Profiltraeger;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §9 · Standortprofil: Profil eines Kunden-Standorts (Master-Team, standort_id > 0) neben dem Teamprofil (standort_id 0).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'member');
    $this->leser = $this->makeUser($this->master, 'Gast', 'viewer');
    $firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->master->id, 'name' => 'Soulkitchen GmbH', 'is_active' => true]);
    $this->projekt = app(ProjektService::class)->anlegen($this->master, $this->kurator, $firma->id, 'Soulkitchen LAGO');
    $this->lago = app(ProjektService::class)->standortAnlegen($this->master, $this->kurator, $this->projekt->id, 'LAGO');
    $this->kombi = FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => 'kombi', 'name' => 'Kombidämpfer', 'sort_order' => 10]);
    $this->svc = app(TeamProfilService::class);
    $this->traeger = Profiltraeger::standort($this->master, $this->lago->id);
});

it('Kunden-Standort: Profil, Gerät und Fläche schreiben und lesen, ohne das Teamprofil des Masters zu berühren', function () {
    $this->svc->setzeProfil($this->traeger, $this->kurator, ['max_pax_parallel' => 80, 'convenience_grad' => 'teil_convenience']);
    $this->svc->setzeEquipment($this->traeger, $this->kurator, $this->kombi->id, 'nicht_vorhanden');
    $this->svc->setzeFlaeche($this->traeger, $this->kurator, null, 'Seeterrasse', 120, 5, 9);

    $standort = $this->svc->lies($this->master, $this->traeger);
    expect($standort['standort_id'])->toBe((int) $this->lago->id)->and($standort['profil']['max_pax_parallel'])->toBe(80)
        ->and($standort['flaechen'])->toHaveCount(1)->and(collect($standort['ausstattung'])->firstWhere('equipment_id', $this->kombi->id)['status'])->toBe('nicht_vorhanden');

    // Gegenprobe: das Teamprofil des Master-Teams sieht nichts davon
    $team = $this->svc->lies($this->master, $this->master);
    expect($team['standort_id'])->toBe(0)->and($team['profil']['max_pax_parallel'])->toBeNull()->and($team['flaechen'])->toBe([])
        ->and(collect($team['ausstattung'])->firstWhere('equipment_id', $this->kombi->id)['status'])->toBe('unbekannt');
    expect($this->svc->wirksameAusstattung($this->master)[$this->kombi->id])->toBe('unbekannt');
});

it('Teamprofil und Standortprofil nebeneinander: je ein Profil je Träger, doppelte Zeilen scheitern am Index', function () {
    $this->svc->setzeProfil($this->master, $this->kurator, ['max_pax_parallel' => 30]);
    $this->svc->setzeProfil($this->traeger, $this->kurator, ['max_pax_parallel' => 80]);
    $this->svc->setzeProfil($this->traeger, $this->kurator, ['max_pax_parallel' => 90]);   // zweites Setzen aktualisiert, legt nichts neu an

    expect(FoodAlchemistTeamProfile::where('team_id', $this->master->id)->count())->toBe(2)
        ->and($this->svc->lies($this->master, $this->master)['profil']['max_pax_parallel'])->toBe(30)
        ->and($this->svc->lies($this->master, $this->traeger)['profil']['max_pax_parallel'])->toBe(90);

    expect(fn () => DB::table('foodalchemist_team_profiles')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->master->id, 'standort_id' => 0, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(\Illuminate\Database\QueryException::class);

    $this->svc->setzeEquipment($this->master, $this->kurator, $this->kombi->id, 'vorhanden');
    $this->svc->setzeEquipment($this->traeger, $this->kurator, $this->kombi->id, 'nicht_vorhanden');
    expect(FoodAlchemistTeamProfileEquipment::where('team_id', $this->master->id)->count())->toBe(2);
    $this->svc->setzeEquipment($this->traeger, $this->kurator, $this->kombi->id, null);   // unbekannt löscht nur die Standort-Zeile
    expect(FoodAlchemistTeamProfileEquipment::where('team_id', $this->master->id)->count())->toBe(1)
        ->and($this->svc->wirksameAusstattung($this->master)[$this->kombi->id])->toBe('vorhanden');
});

it('Träger-Prüfung: erfundene oder fremde Standort-ID und Nicht-Master werden abgewiesen, Leser dürfen nicht schreiben', function () {
    expect(fn () => $this->svc->setzeProfil(Profiltraeger::standort($this->master, 99999), $this->kurator, ['notes' => 'x']))->toThrow(\RuntimeException::class, 'nicht gefunden');
    expect(fn () => $this->svc->lies($this->master, Profiltraeger::standort($this->rootTeam, $this->lago->id)))->toThrow(\RuntimeException::class, 'nicht gefunden');
    expect(fn () => Profiltraeger::standort($this->master, 0))->toThrow(\InvalidArgumentException::class);
    expect(fn () => $this->svc->setzeProfil($this->traeger, $this->leser, ['notes' => 'x']))->toThrow(FaRechtFehltException::class);
    expect(FoodAlchemistTeamProfile::count())->toBe(0);
});

it('Standort löschen nimmt sein Profil mit, das Teamprofil bleibt', function () {
    $this->svc->setzeProfil($this->master, $this->kurator, ['notes' => 'Master']);
    $this->svc->setzeProfil($this->traeger, $this->kurator, ['notes' => 'LAGO']);
    $this->svc->setzeEquipment($this->traeger, $this->kurator, $this->kombi->id, 'vorhanden');
    $this->svc->setzeFlaeche($this->traeger, $this->kurator, null, 'Saal');

    app(ProjektService::class)->standortLoeschen($this->master, $this->kurator, $this->projekt->id, $this->lago->id);

    expect(DB::table('foodalchemist_team_profiles')->where('standort_id', $this->lago->id)->count())->toBe(0)
        ->and(DB::table('foodalchemist_team_profile_equipment')->where('standort_id', $this->lago->id)->count())->toBe(0)
        ->and(DB::table('foodalchemist_team_profile_areas')->where('standort_id', $this->lago->id)->count())->toBe(0)
        ->and($this->svc->lies($this->master, $this->master)['profil']['notes'])->toBe('Master');
});

it('Seite: Master sieht Eigene und Kunden-Standorte, öffnet ein Profil und pflegt Gerät und Kennzahlen', function () {
    $this->actingAs($this->kurator);

    Livewire::test(Index::class)->assertSeeHtml('data-standortprofil-eigene')->assertSeeHtml('data-standortprofil-zeile="'.$this->lago->id.'"')
        ->call('standortOeffnen', $this->lago->id)->assertSeeHtml('data-standortprofil-offen')->assertSeeHtml('data-teamprofil-equipment')
        ->set('maxPax', '75')->call('profilSpeichern')->call('equipmentSetzen', $this->kombi->id, 'vorhanden')
        ->set('neueFlaeche.name', 'Saal')->call('flaecheAnlegen')->assertSee('Saal');

    $d = $this->svc->lies($this->master, $this->traeger);
    expect($d['profil']['max_pax_parallel'])->toBe(75)->and($d['flaechen'])->toHaveCount(1)
        ->and(collect($d['ausstattung'])->firstWhere('equipment_id', $this->kombi->id)['status'])->toBe('vorhanden');
    expect($this->svc->lies($this->master, $this->master)['flaechen'])->toBe([]);   // nichts ins Teamprofil gerutscht
});

it('Seite: Leser sieht, schreibt aber nicht; ein anderes Team sieht keine Kunden-Standorte und kann keinen öffnen', function () {
    $this->actingAs($this->leser);
    Livewire::test(Index::class)->call('standortOeffnen', $this->lago->id)->set('maxPax', '10')->call('profilSpeichern')->assertSet('fehler', fn ($f) => $f !== null);
    expect($this->svc->lies($this->master, $this->traeger)['profil']['max_pax_parallel'])->toBeNull();

    $this->actingAs($this->makeUser($this->rootTeam, 'Anderer', 'owner'));
    Livewire::test(Index::class)->assertSeeHtml('data-standortprofil-eigene')->assertDontSeeHtml('data-standortprofil-kunden')
        ->call('standortOeffnen', $this->lago->id)->assertSet('standortId', 0);
});
