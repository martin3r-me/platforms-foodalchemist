<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 51 — was die KI bei Behältern darf und was nicht.
 *
 * DARF: Dichteklasse und Skalierung — Produkteigenschaften.
 * DARF NICHT: die Anzahl Behälter. Das ist eine Rechnung, und die Datenbank kennt die Kilogramm
 * exakt. Genau deshalb ist `vk.behaelter` ersatzlos entfallen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'ki1', 'name' => 'Sauce: Pfeffer',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 10,
    ]);

    $this->mockKi = function (array $werte) {
        $this->mock(AiGatewayService::class, function ($mock) use ($werte) {
            $mock->shouldReceive('propose')->andReturn(new AiProposal($werte, 0.8));
        });
    };
});

it('übernimmt Dichteklasse und Skalierung — aber schreibt nichts in die DB', function () {
    ($this->mockKi)(['dichteklasse' => 'fluessig', 'skalierung' => 'tiefer_fuellbar']);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('tabLaden', 'regeneration')
        ->call('kiDichteklasse')
        ->assertSet('dichteklasse', 'fluessig')
        ->assertSet('behaelterForm.abfuellen.skalierung', 'tiefer_fuellbar')
        ->assertSet('regenMeldung', fn ($m) => str_contains((string) $m, 'Noch nicht gespeichert'));

    // GL-07: die Übernahme ist eine Entscheidung des Menschen — der Vorschlag allein schreibt nicht.
    expect(FoodAlchemistRecipe::find($this->rezept->id)->dichteklasse)->toBeNull();
});

it('überschreibt keine gepflegte Skalierung — Override-First', function () {
    ($this->mockKi)(['dichteklasse' => 'locker', 'skalierung' => 'hoehe_gebunden']);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('tabLaden', 'regeneration')
        ->set('behaelterForm.abfuellen.skalierung', 'lagenware')     // von Hand entschieden
        ->call('kiDichteklasse')
        ->assertSet('behaelterForm.abfuellen.skalierung', 'lagenware')
        ->assertSet('behaelterForm.regenerieren.skalierung', 'hoehe_gebunden');   // leer war frei
});

it('verwirft Unsinn statt ihn zu übernehmen', function () {
    ($this->mockKi)(['dichteklasse' => 'schwerelos', 'skalierung' => 'irgendwas']);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('tabLaden', 'regeneration')
        ->call('kiDichteklasse')
        ->assertSet('dichteklasse', '')
        ->assertSet('regenMeldung', fn ($m) => str_contains((string) $m, 'nichts übernommen'));
});

it('die KI hat keinen Weg mehr, eine Behälterzahl zu setzen', function () {
    // `vk.behaelter` ist aus der Gateway-Whitelist entfernt — ein Aufruf muss scheitern,
    // nicht still durchlaufen.
    $erlaubt = (new ReflectionClass(AiGatewayService::class))->getConstants();
    $slugs = collect($erlaubt)->flatten()->all();

    expect($slugs)->not->toContain('vk.behaelter')
        ->and(config('foodalchemist.prompts'))->not->toHaveKey('vk.behaelter')
        ->and(config('foodalchemist.prompts'))->toHaveKey('recipe.dichteklasse');
});

it('der Prompt fordert genau die zwei Felder — und keine Anzahl', function () {
    $task = (string) (config('foodalchemist.prompts')['recipe.dichteklasse']['task'] ?? '');

    expect($task)->toContain('dichteklasse')
        ->and($task)->toContain('skalierung')
        ->and($task)->toContain('PRODUKT')                    // die Abgrenzung steht im Prompt selbst
        ->and(mb_strtolower($task))->not->toContain('anzahl')
        ->and(mb_strtolower($task))->not->toContain('behaelterzahl');
});
