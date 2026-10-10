<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\RecipeKomponentenPlanService;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Haus-Zuordnung (Dominique 10.10.): „Habe ich eine Jus, die fertig ist, wird die auch benutzt — je nach Kontext.“
 * Die Regel verbindet Komponente und Bestandsrezept, die der Namensvergleich nie zusammenbringt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->regel = FoodAlchemistRule::where('schluessel', 'basisrezept.bestand.haus_zuordnung')->firstOrFail();
    $this->setze = fn (array $eintraege) => app(RegelService::class)->speichere(
        [...$this->regel->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'notiz']),
            'params' => ['vergleich' => 'text', 'eintraege' => $eintraege]], null, true);
    $this->plan = app(RecipeKomponentenPlanService::class);
});

it('startet aus — ohne aktive Regel findet „Jus: Rind“ den „Jus: Standard“ nicht', function () {
    $this->makeRecipe($this->rootTeam, 'Jus: Standard', ['status' => 'approved']);
    $ab = [];

    expect($this->regel->aktiv)->toBeFalse()
        ->and($this->plan->bestandFuer($this->rootTeam, 'Jus: Rind', [], $ab))->toBeNull();
});

it('aktiv: „Jus: Rind“ → freigegebener „Jus: Standard“; nicht freigegeben wird mit Grund abgelehnt', function () {
    $std = $this->makeRecipe($this->rootTeam, 'Jus: Standard', ['status' => 'approved']);
    ($this->setze)([['begriff' => 'Jus: Rind', 'ziel_typ' => 'rezept', 'ziel_name' => 'Jus: Standard']]);
    $ab = [];

    expect($this->plan->bestandFuer($this->rootTeam, 'Jus: Rind', [], $ab))->toBe(['recipe_id' => $std->id, 'name' => 'Jus: Standard']);

    $std->update(['status' => 'review']);
    $ab = [];
    expect($this->plan->bestandFuer($this->rootTeam, 'Jus: Rind', [], $ab))->toBeNull()
        ->and(collect($ab)->pluck('grund')->implode(' '))->toContain('noch nicht freigegeben');
});

it('je nach Kontext: ein Eintrag mit Sektor schlägt den allgemeinen', function () {
    $std = $this->makeRecipe($this->rootTeam, 'Jus: Standard', ['status' => 'approved']);
    $event = $this->makeRecipe($this->rootTeam, 'Jus: Rind Event', ['status' => 'approved']);
    ($this->setze)([
        ['begriff' => 'Jus: Rind', 'ziel_typ' => 'rezept', 'ziel_name' => 'Jus: Standard'],
        ['begriff' => 'Jus: Rind', 'ziel_typ' => 'rezept', 'ziel_name' => 'Jus: Rind Event', 'kontext' => ['sektor' => ['event']]],
    ]);
    $ab = [];

    expect($this->plan->bestandFuer($this->rootTeam, 'Jus: Rind', [], $ab, null, ['sektor' => 'event'])['recipe_id'])->toBe($event->id)
        ->and($this->plan->bestandFuer($this->rootTeam, 'Jus: Rind', [], $ab, null, ['sektor' => 'restaurant'])['recipe_id'])->toBe($std->id);
});
