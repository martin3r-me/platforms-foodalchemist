<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Kueche;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M7-07: Küchen-Profil als Team-Einstellung — Soft-Default-Schicht VOR den
 * Hooks im Generator-Prompt (explizite Parameter gewinnen, steht im Block).
 * DoD: Profil ändert den Generator-Prompt nachweisbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    $this->spy = new class extends FakeAiProvider
    {
        public array $messages = [];

        public function chat(array $messages, array $options = []): array
        {
            $this->messages = $messages;

            return ['content' => '{"werte": {}}', 'model' => 'fake-spy', 'usage' => []];
        }
    };
    app()->instance(FakeAiProvider::class, $this->spy);
});

it('DoD: gesetztes Profil ändert den Generator-Prompt; Vorrang-Hinweis steht im Block', function () {
    $lauf = function () {
        try {
            app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Schmorgericht für 80 Personen');
        } catch (RuntimeException) {
            // Spy liefert kein Rezept — uns interessiert nur der Prompt
        }

        return collect($this->spy->messages)->pluck('content')->implode("\n");
    };

    $ohne = $lauf();
    app(TeamSettingsService::class)->update($this->rootTeam, ['kuechen_typ' => 'catering']);
    $mit = $lauf();

    expect($ohne)->not->toContain('kuechen_profil')
        ->and($mit)->toContain('kuechen_profil')
        ->and($mit)->toContain('transportstabil')                     // Catering-Tendenz
        ->and($mit)->toContain('VORRANG');                            // explizite Hooks gewinnen
});

it('Settings-Sektion speichert; ungültiger Slug wird genullt', function () {
    Livewire::test(Kueche::class)
        ->set('kuechenTyp', 'grosskueche')
        ->call('speichern')
        ->assertSet('meldung', fn ($m) => str_contains((string) $m, 'Gespeichert'));
    expect(app(TeamSettingsService::class)->kuechenTyp($this->rootTeam))->toBe('grosskueche');

    Livewire::test(Kueche::class)->set('kuechenTyp', 'quatsch')->call('speichern');
    expect(app(TeamSettingsService::class)->kuechenTyp($this->rootTeam))->toBeNull();
});
