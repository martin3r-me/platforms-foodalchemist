<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Settings\Index as Einstellungen;
use Platform\FoodAlchemist\Livewire\Settings\Start;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einstellungen › Übersicht (Neuordnung 23.10., E-2): erster Reiter, Kachel je Reiter mit zählbarem Status, Link je Bereich auf
 * Reiter + Bereich; nur was der Benutzer sehen darf; Standortprofil/Abos als Links auf ihre eigenen Seiten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Admin', 'owner'));
});

it('Übersicht ist der erste Reiter und Einstieg ohne Sektion', function () {
    expect(array_key_first(Einstellungen::GRUPPEN))->toBe('uebersicht');
    Livewire::test(Einstellungen::class)->assertSet('tab', 'uebersicht')->assertSeeHtml('data-settings-sektion="start"');
});

it('Kacheln je Reiter mit Status; offen wird eingerichtet, sobald es Daten gibt; Links führen auf Reiter + Bereich', function () {
    $c = Livewire::test(Start::class);
    $html = $c->html();
    expect($html)->toContain('data-start-kachel="kalkulation"')->toContain('data-start-link="betriebe"')
        ->toContain(e(route('foodalchemist.einstellungen', ['tab' => 'kalkulation', 'bereich' => 'betriebe'])))
        ->toContain('kein Kalkulationsprofil')->toContain('data-start-fortschritt')
        ->not->toContain('data-start-kachel="uebersicht"');

    FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine']);
    expect(Livewire::test(Start::class)->html())->toContain('1 Kalkulationsprofil')->not->toContain('kein Kalkulationsprofil');
});

it('Plattform-Kachel nur für Master; Mandant sieht sie nicht', function () {
    expect(Livewire::test(Start::class)->html())->not->toContain('data-start-kachel="plattform"');

    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    expect(Livewire::test(Start::class)->html())->toContain('data-start-kachel="plattform"');
});

it('Standort (Unter-Team) sieht keine Oberteam-Bereiche in den Kacheln; Mitglied sieht die Übersicht lesend', function () {
    $this->actingAs($this->makeUser($this->childA, 'Standort', 'owner'));
    expect(Livewire::test(Start::class)->html())->not->toContain('data-start-link="unter-teams"')->not->toContain('data-start-link="dna-felder"');

    $this->actingAs($this->makeUser($this->rootTeam, 'Mitglied', 'member'));
    Livewire::test(Einstellungen::class)->assertSeeHtml('data-settings-start')->assertSeeHtml('data-settings-nur-lesen');
});
