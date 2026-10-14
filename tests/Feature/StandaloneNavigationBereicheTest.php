<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Eigenständige Hülle: die Navigation zeigt nur Bereiche, die das Team gebucht hat (wie die Plattform-Sidebar), und „Projekte" nur im Master-Team.
 * Vorher nahm das Layout `config('foodalchemist.sidebar')` ungefiltert (z. B. „Projekte" bei einem Kunden-Team).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.shell' => 'eigenstaendig']);
    MasterTeam::flush();
    $this->plattformAdmin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->plattformAdmin->email)]]);
    $this->rechte = app(\Platform\FoodAlchemist\Services\FaRechte::class);
    $this->nav = function (Team $team): string {
        $user = $this->makeUser($team, 'Küche ' . $team->id . uniqid(), 'member');
        $this->actingAs($user);

        return view('foodalchemist::layouts.partials.standalone-nav')->render();   // die Navigation der eigenständigen Hülle (ohne Terminal/Meldungen)
    };
    $this->lager = fn () => route('foodalchemist.lager.index');
    $this->projekte = fn () => route('foodalchemist.projekte.index');
});

it('★ ein Team ohne gebuchten Bereich sieht dessen Menüpunkt nicht, andere bleiben', function () {
    $mitLager = ($this->nav)($this->rootTeam);
    expect($mitLager)->toContain(($this->lager)());

    $this->rechte->setzeTeamBereich($this->rootTeam, $this->plattformAdmin, 'lager', false);
    $ohneLager = ($this->nav)($this->rootTeam);

    expect($ohneLager)->not->toContain(($this->lager)())
        ->and($ohneLager)->toContain(route('foodalchemist.dashboard'));   // übrige Navigation bleibt
});

it('★ „Projekte" steht nur im Master-Team in der Navigation', function () {
    expect(($this->nav)($this->rootTeam))->not->toContain(($this->projekte)())
        ->and(($this->nav)($this->master))->toContain(($this->projekte)());
});

it('ein Unter-Team erbt die abgeschalteten Bereiche seines Haupt-Teams', function () {
    $this->rechte->setzeTeamBereich($this->rootTeam, $this->plattformAdmin, 'lager', false);

    expect(($this->nav)($this->childA))->not->toContain(($this->lager)());
});
