<?php

use Platform\FoodAlchemist\Support\Curate;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M1-08 (Kern) / D1: canCurate — Edit nur für Mitglieder des Besitzer-Teams.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->katalogGp = $this->makeGp($this->rootTeam, 'Zanderfilet');

    $this->rootUser = (object) ['current_team_id' => $this->rootTeam->id];
    $this->kindUser = (object) ['current_team_id' => $this->childA->id];
});

it('erlaubt Edit nur dem Besitzer-Team (Model-Variante)', function () {
    expect(Curate::canCurate($this->rootUser, $this->katalogGp))->toBeTrue()
        ->and(Curate::canCurate($this->kindUser, $this->katalogGp))->toBeFalse(); // geerbt = read-only
});

it('erlaubt Edit nur dem Besitzer-Team (Team-Variante)', function () {
    expect(Curate::canCurate($this->rootUser, $this->rootTeam))->toBeTrue()
        ->and(Curate::canCurate($this->kindUser, $this->rootTeam))->toBeFalse()
        ->and(Curate::canCurate($this->kindUser, $this->childA))->toBeTrue(); // Eigenes bleibt editierbar
});

it('verweigert ohne User, ohne Team-Zuordnung oder ohne Ziel', function () {
    expect(Curate::canCurate(null, $this->katalogGp))->toBeFalse()
        ->and(Curate::canCurate((object) ['current_team_id' => null], $this->katalogGp))->toBeFalse()
        ->and(Curate::canCurate($this->rootUser, null))->toBeFalse();
});
