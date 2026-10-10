<?php

use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Hausstandard Dominique: Jus/Fond/Brühe aus Knochen UND Abschnitten — der Generator soll dafür das GP
 * „Fleischabschnitte / Parueren: frisch" (demo 10466) treffen. Demo 10.10.: „Parüren" nur fuzzy_low 0,5,
 * „Kalbsparüren" kein Treffer (0,33). Ursache: der Kopf-Floor (Name == Query) sah „A / B" nur als Ganzes.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->gp = $this->makeGp($this->rootTeam, 'Fleischabschnitte / Parueren: frisch');
    $this->makeGp($this->rootTeam, 'Rinderknochen: frisch');
    $this->m = fn (string $q) => app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $q);
});

it('Parüren und Fleischabschnitte treffen das Schrägstrich-GP sicher', function () {
    foreach (['Parüren', 'Parüren: frisch', 'Fleischabschnitte'] as $q) {
        $m = ($this->m)($q);
        expect($m['gp_id'])->toBe($this->gp->id, $q)
            ->and(IngredientMatchService::istAutomatischVerdrahtbar($m))->toBeTrue($q);
    }
});

it('Tier-Parüren bleiben §11.2-Derivate ihrer Mutter — kein Alias auf das allgemeine Parüren-GP', function () {
    // Regelwerk GP §11.2: „Kalbsparüren" = derivat_typ paruere, Name „Kalb: frisch, Parüren", Allergene LIVE von der Mutter.
    foreach (['Kalbsparüren', 'Rinderparüren', 'Lammparüren'] as $q) {
        $m = ($this->m)($q);
        expect(IngredientMatchService::istAutomatischVerdrahtbar($m) && $m['gp_id'] === $this->gp->id)->toBeFalse($q);
    }
});

it('Gegenprobe: Knochen bleibt Knochen, ein Schrägstrich-Teil allein reicht nicht für fremde Wörter', function () {
    expect(($this->m)('Rinderknochen')['gp_name'])->toBe('Rinderknochen: frisch')
        ->and(app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class)->headMatchesQuery('Fleischabschnitte / Parueren: frisch', ['kalb']))->toBeFalse();
});
