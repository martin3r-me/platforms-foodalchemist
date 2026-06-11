<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M0-06: Leak-Test gegen foodalchemist_gps (D1-Risiko aus 08_ENTSCHEIDUNGEN).
 *
 * D1-Sichtbarkeitsmodell: sichtbar = eigenes Team + Eltern-Kette aufwärts.
 * Geschwister sehen einander NICHTS, Eltern sehen Kind-Daten NICHT,
 * editierbar ist nur, was dem eigenen Team gehört.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->katalogGp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $this->eigenesGpA = $this->makeGp($this->childA, 'Hauslimonade A');
});

it('Kind sieht den Eltern-Katalog plus Eigenes', function () {
    $sichtbar = FoodAlchemistGp::visibleToTeam($this->childA)->pluck('id');

    expect($sichtbar)->toHaveCount(2)
        ->and($sichtbar)->toContain($this->katalogGp->id)
        ->and($sichtbar)->toContain($this->eigenesGpA->id);
});

it('Geschwister sieht nichts vom anderen Kind', function () {
    $sichtbar = FoodAlchemistGp::visibleToTeam($this->childB)->pluck('id');

    expect($sichtbar)->toHaveCount(1)
        ->and($sichtbar)->toContain($this->katalogGp->id)
        ->and($sichtbar)->not->toContain($this->eigenesGpA->id);
});

it('Eltern-Team sieht Kind-Daten nicht', function () {
    $sichtbar = FoodAlchemistGp::visibleToTeam($this->rootTeam)->pluck('id');

    expect($sichtbar)->toHaveCount(1)
        ->and($sichtbar)->toContain($this->katalogGp->id);
});

it('isOwnedBy erlaubt Edit nur dem Besitzer-Team', function () {
    // Eltern-Katalog: für das Kind lesbar (Test oben), aber nicht editierbar
    expect($this->katalogGp->isOwnedBy($this->rootTeam))->toBeTrue()
        ->and($this->katalogGp->isOwnedBy($this->childA))->toBeFalse()
        ->and($this->eigenesGpA->isOwnedBy($this->childA))->toBeTrue()
        ->and($this->eigenesGpA->isOwnedBy($this->childB))->toBeFalse();
});
