<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #8 (Dominique 2026-08-27): „GP in allen Rezepten tauschen" ist aus dem Detail-Panel in den
 * GP-Editor (GpModal) gezogen. Hängt alle Rezept-Zeilen des GP auf ein Ziel um (Vorstufe zum Löschen).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('GpModal: GP in allen Rezepten tauschen hängt die Zutat-Zeilen um', function () {
    $quelle = $this->makeGp($this->rootTeam, 'Aalfilet');
    $ziel = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $rezept = $this->makeRecipe($this->rootTeam, 'Fischsuppe');
    $zutat = FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $rezept->id, 'gp_id' => $quelle->id,
        'raw_text' => '200 g Aalfilet', 'quantity' => '200',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);

    Livewire::test(GpModal::class)
        ->call('oeffnen', $quelle->id)
        ->set('tauschSuche', 'Zander')
        ->call('gpErsetzen', $ziel->id)
        ->assertHasNoErrors()
        ->assertSet('hinweis', fn ($h) => is_string($h) && str_contains($h, 'umgehängt'))
        ->assertSet('tauschSuche', '');

    expect(FoodAlchemistRecipeIngredient::find($zutat->id)->gp_id)->toBe($ziel->id);
});

it('GpModal: Tausch auf sich selbst wird abgewiesen (Fehler, keine Änderung)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Aalfilet');

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->call('gpErsetzen', $gp->id)
        ->assertSet('fehler', fn ($f) => is_string($f) && $f !== '');
});
