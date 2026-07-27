<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 22·H1 / V-048 — der Ancestry-Cache-Flush ist keine Handliste mehr.
 *
 * Die Fehlerklasse, gegen die diese Tests stehen, ist unauffällig: eine fehlende Zeile in
 * der alten 14-Klassen-Liste ließ einen D1-/Leak-Test NICHT rot werden, sondern grün aus
 * dem falschen Grund — er prüfte eine Sichtbarkeit gegen eine stale Team-Kette, die es zur
 * Laufzeit nicht gibt. Darum wird hier beides festgenagelt: dass sich eine cachende Klasse
 * selbst einträgt (also niemand mehr aufzählen muss) und dass der Flush wirklich greift.
 */
it('registriert jede cachende Model-Klasse selbst — ohne Handliste', function () {
    $this->seedTeamHierarchy();

    FoodAlchemistGp::teamAncestryIds($this->childA);

    expect(TeamAncestryRegistry::registered())->toContain(FoodAlchemistGp::class);
});

it('leert die Ketten aller registrierten Klassen', function () {
    $this->seedTeamHierarchy();

    expect(FoodAlchemistGp::teamAncestryIds($this->childA))
        ->toBe([(int) $this->childA->id, (int) $this->rootTeam->id]);

    // Team umhängen (Root wird zum Fremd-Team): der Cache kennt die alte Kette weiter.
    Team::whereKey($this->childA->id)->update(['parent_team_id' => null]);
    $frisch = Team::findOrFail($this->childA->id);

    expect(FoodAlchemistGp::teamAncestryIds($frisch))
        ->toBe([(int) $this->childA->id, (int) $this->rootTeam->id], 'Beleg, dass wirklich gecacht wird');

    TeamAncestryRegistry::flushAll();

    expect(FoodAlchemistGp::teamAncestryIds($frisch))->toBe([(int) $this->childA->id]);
});
