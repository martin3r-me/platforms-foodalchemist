<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plural-Dubletten zusammenlegen — Vorarbeit für den Schätzlauf („nur einmal schätzen").
 * Die Zusicherung ist nicht „es wird umgehängt", sondern: es verschiebt sich dabei KEINE
 * Menge, und bei ungleichen Gramm-Defaults verweigert der Befehl den Dienst statt zu wählen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $nutzer = $this->makeUser($this->rootTeam, 'Root User');
    $this->rootTeam->users()->attach($nutzer->id, ['role' => 'owner']);
    $this->svc = app(RecipeService::class);
    FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'],
        ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->zweig = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'zweig'],
        ['display_de' => 'Zweig', 'dimension' => 'count', 'default_in_g' => 2]);
    $this->zweige = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'zweige'],
        ['display_de' => 'Zweige', 'dimension' => 'count', 'default_in_g' => 2]);
});

it('hängt Plural-Zeilen auf die Einzahl um und setzt den Plural inaktiv — ohne Mengen zu verschieben', function () {
    $gp = $this->makeGp($this->rootTeam, 'Thymian: frisch');
    $r = $this->svc->create($this->rootTeam, ['name' => 'Fond: Thymian']);
    $this->svc->syncIngredients($this->rootTeam, $r->id, [[
        'id' => null, 'gp_id' => $gp->id, 'raw_text' => '3 zweige',
        'quantity' => '3', 'unit_vocab_id' => $this->zweige->id,
    ]]);
    FoodAlchemistGpForm::create(['gp_id' => $gp->id, 'form_slug' => 'zweige', 'gramm' => 4.0, 'source' => 'manual']);

    $this->artisan('foodalchemist:vocab-units-dedupe', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    $zutat = FoodAlchemistRecipeIngredient::where('recipe_id', $r->id)->first();
    expect($zutat->unit_vocab_id)->toBe($this->zweig->id)
        ->and((float) $zutat->quantity)->toBe(3.0)                       // Menge unverändert
        ->and(FoodAlchemistGpForm::where('gp_id', $gp->id)->where('form_slug', 'zweig')->value('gramm'))
            ->not->toBeNull()                                            // Gewicht mitgenommen
        ->and((bool) $this->zweige->fresh()->is_inactive)->toBeTrue();    // Plural raus, nicht gelöscht
});

it('verweigert das Zusammenlegen bei verschiedenen Gramm-Defaults', function () {
    $this->zweige->update(['default_in_g' => 5]);                        // 5 g vs. 2 g

    $this->artisan('foodalchemist:vocab-units-dedupe', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertFailed();

    expect((bool) $this->zweige->fresh()->is_inactive)->toBeFalse();     // nichts angefasst
});

it('lässt die Einzahl gewinnen, wenn beide ein Formgewicht tragen', function () {
    $gp = $this->makeGp($this->rootTeam, 'Rosmarin: frisch');
    FoodAlchemistGpForm::create(['gp_id' => $gp->id, 'form_slug' => 'zweig', 'gramm' => 3.0, 'source' => 'manual']);
    FoodAlchemistGpForm::create(['gp_id' => $gp->id, 'form_slug' => 'zweige', 'gramm' => 9.0, 'source' => 'ki']);

    $this->artisan('foodalchemist:vocab-units-dedupe', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    expect((float) FoodAlchemistGpForm::where('gp_id', $gp->id)->where('form_slug', 'zweig')->value('gramm'))->toBe(3.0)
        ->and(FoodAlchemistGpForm::withTrashed()->where('gp_id', $gp->id)->where('form_slug', 'zweige')->first()->deleted_at)
            ->not->toBeNull();
});
