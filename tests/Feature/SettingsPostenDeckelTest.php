<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Posten;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Standard-Topf-Deckel (Team-Setting) in der „Posten & Kapazität"-Sektion — Fallback für die
 * Produktionszeit, wenn WEDER Rezept noch Posten einen eigenen Deckel pflegen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('speichert den Team-Standard-Topf-Deckel (kg + Stück)', function () {
    Livewire::test(Posten::class)
        ->set('standardTopfKg', '40')
        ->set('standardTopfStueck', '150')
        ->call('standardDeckelSpeichern')
        ->assertSet('fehler', null)
        ->assertSet('meldung', fn ($m) => str_contains((string) $m, 'gespeichert'));

    $svc = app(TeamSettingsService::class);
    expect($svc->defaultTopfDeckelKg($this->rootTeam))->toBe(40.0)
        ->and($svc->defaultTopfDeckelStueck($this->rootTeam))->toBe(150.0);
});

it('lädt gepflegte Werte beim Mount; leeres Feld = System-Standard (Code-Konstante)', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['default_batch_max_kg' => 12.5]);

    Livewire::test(Posten::class)
        ->assertSet('standardTopfKg', '12.5')
        ->assertSet('standardTopfStueck', '');

    // Stück nie gepflegt ⇒ Getter fällt auf die Code-Konstante zurück.
    expect(app(TeamSettingsService::class)->defaultTopfDeckelStueck($this->rootTeam))
        ->toBe((float) FoodAlchemistRecipe::DEFAULT_BATCH_MAX_PIECES);
});
