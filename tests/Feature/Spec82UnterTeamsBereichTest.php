<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Index as SettingsIndex;
use Platform\FoodAlchemist\Livewire\Settings\UnterTeams;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §5 / §13 B2: Die Sektion „Unter-Teams & Freigaben“ hängt am Bereich „unterteams“.
 * Aus = Sektion unsichtbar, Direktlink gesperrt; an = wie bisher.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->rechte = app(FaRechte::class);
    // Plattform-Admin (darf Bereiche schalten, sieht aber selbst alles)
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform-Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
});

it('Bereich an (Standard): die Sektion ist in der Navigation und per Direktlink erreichbar', function () {
    expect($this->rechte->bereichAktiv($this->rootTeam, 'unterteams'))->toBeTrue();

    expect(Livewire::test(SettingsIndex::class)->viewData('sektionen'))->toHaveKey('unter-teams');
    Livewire::test(SettingsIndex::class, ['sektion' => 'unter-teams'])->assertOk();
    Livewire::test(UnterTeams::class)->assertOk();
});

it('Bereich aus: die Sektion verschwindet aus der Navigation, der Direktlink und die Komponente sind gesperrt', function () {
    $this->rechte->setzeTeamBereich($this->rootTeam, $this->admin, 'unterteams', false);
    expect($this->rechte->bereichAktiv($this->rootTeam, 'unterteams'))->toBeFalse();

    expect(Livewire::test(SettingsIndex::class)->viewData('sektionen'))->not->toHaveKey('unter-teams')
        ->and(Livewire::test(SettingsIndex::class)->viewData('sektionen'))->toHaveKey('betriebe');   // andere Sektionen bleiben
    Livewire::test(SettingsIndex::class, ['sektion' => 'unter-teams'])->assertNotFound();
    Livewire::test(UnterTeams::class)->assertNotFound();
});

it('Bereich aus nur für ein Team: ein anderes Team (Geschwister-Mandant) behält die Sektion', function () {
    $fremd = \Platform\Core\Models\Team::create(['name' => 'Anderer Mandant', 'user_id' => 1, 'personal_team' => false]);
    $this->rechte->setzeTeamBereich($this->rootTeam, $this->admin, 'unterteams', false);

    $this->actingAs($this->makeUser($fremd, 'Fremd-User'));

    expect(Livewire::test(SettingsIndex::class)->viewData('sektionen'))->toHaveKey('unter-teams');
});

it('Plattform-Admin sieht die Sektion auch bei ausgeschaltetem Bereich (wie bei jedem Bereich)', function () {
    $this->rechte->setzeTeamBereich($this->rootTeam, $this->admin, 'unterteams', false);
    $this->actingAs($this->admin);

    expect(Livewire::test(SettingsIndex::class)->viewData('sektionen'))->toHaveKey('unter-teams');
});

it('Unter-Team: weiter nicht sichtbar, auch wenn der Bereich an ist (nur Oberteam)', function () {
    $this->actingAs($this->makeUser($this->childA, 'Kind-User'));

    expect(Livewire::test(SettingsIndex::class)->viewData('sektionen'))->not->toHaveKey('unter-teams');
});
