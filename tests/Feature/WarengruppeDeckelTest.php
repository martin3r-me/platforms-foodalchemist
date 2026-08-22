<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Posten;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Warengruppen-Topf-Deckel je Recipe-Hauptgruppe — feinere Fallback-Ebene VOR dem Team-Default.
 * Nur kg, Basisrezept-Achse (category.main_group_id); greift nur ohne Rezept-/Posten-Deckel.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(TeamSettingsService::class);

    $this->mg = FoodAlchemistRecipeMainGroup::create([
        'team_id' => $this->rootTeam->id, 'code' => 'FOND', 'label' => 'Fonds & Suppen',
    ]);
    $this->cat = FoodAlchemistRecipeCategory::create([
        'team_id' => $this->rootTeam->id, 'main_group_id' => $this->mg->id, 'code' => 'fond', 'label' => 'Fond',
    ]);
    $this->rezept = fn (?int $catId) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'r'.uniqid(), 'name' => 'Testrezept',
        'status' => 'draft', 'is_sales_recipe' => false, 'yield_kg' => 4.0, 'work_time_min' => 300,
        'category_id' => $catId,
    ]);
});

it('nutzt den Warengruppen-Deckel vor dem Team-Default (nur bei passender Hauptgruppe)', function () {
    $this->svc->update($this->rootTeam, [
        'default_batch_max_kg' => 20.0,
        'warengruppe_batch_max_kg' => [(int) $this->mg->id => 40.0],
    ]);

    $mitWg = ($this->rezept)($this->cat->id);   // Hauptgruppe FOND → 40
    $ohneWg = ($this->rezept)(null);            // keine Hauptgruppe → Team-Default 20

    expect($this->svc->topfDeckelFuer($this->rootTeam, $mitWg))->toBe(40.0)
        ->and($this->svc->topfDeckelFuer($this->rootTeam, $ohneWg))->toBe(20.0);
});

it('fällt auf den Team-Default zurück, wenn die Hauptgruppe keinen eigenen Deckel hat', function () {
    $this->svc->update($this->rootTeam, [
        'default_batch_max_kg' => 25.0,
        'warengruppe_batch_max_kg' => [999 => 40.0],   // eine ANDERE Hauptgruppe
    ]);

    expect($this->svc->topfDeckelFuer($this->rootTeam, ($this->rezept)($this->cat->id)))->toBe(25.0);
});

it('speichert die Warengruppen-Matrix über die Posten-Einstellungen und wirkt', function () {
    Livewire::test(Posten::class)
        ->set("warengruppenDeckel.{$this->mg->id}", '40')
        ->call('warengruppenDeckelSpeichern')
        ->assertSet('fehler', null)
        ->assertSet('meldung', fn ($m) => str_contains((string) $m, 'gespeichert'));

    // Effektiv wirksam: ein Rezept dieser Hauptgruppe bekommt den 40-kg-Deckel.
    expect($this->svc->topfDeckelFuer($this->rootTeam, ($this->rezept)($this->cat->id)))->toBe(40.0);
});
