<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\RegenerationCascadeService as Kaskade;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 51 Etappe D — das Gericht liest die Kaskade und speichert nur Abweichungen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->nr = 0;

    $this->rezept = fn (string $name, bool $vk = false) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'd'.(++$this->nr),
        'name' => $name, 'status' => 'approved', 'is_sales_recipe' => $vk,
    ]);

    $this->hinein = fn (FoodAlchemistRecipe $ziel, FoodAlchemistRecipe $sub) => FoodAlchemistRecipeIngredient::create([
        'team_id' => $ziel->team_id, 'recipe_id' => $ziel->id, 'referenced_recipe_id' => $sub->id,
        'raw_text' => $sub->name, 'quantity' => '100',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);

    $this->komponente = ($this->rezept)('Ratatouille');
    FoodAlchemistRecipeRegeneration::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->komponente->id,
        'component_label' => 'Ratatouille', 'temp_c' => 150, 'duration_min' => 10, 'sort_order' => 0,
    ]);

    $this->gericht = ($this->rezept)('Teller: Beilage warm', true);
    $this->zutat = ($this->hinein)($this->gericht, $this->komponente);
});

it('das Gericht zeigt den Stand der Komponente als »geerbt«', function () {
    $zeile = (new Kaskade)->fuerRezept($this->gericht)['komponenten'][0];

    expect($zeile['herkunft'])->toBe(Kaskade::HERKUNFT_GEERBT)
        ->and($zeile['temp_c'])->toBe(150)
        ->and($zeile['regeneration_id'])->toBeNull();     // nichts gespeichert — es wird gelesen
});

it('eine geerbte Zeile anfassen legt einen Override an, kein Duplikat am Gericht', function () {
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->gericht->id)
        ->call('regenKomponenteBearbeiten', $this->zutat->id)
        ->assertSet('regenForm.temp_c', 150)              // vorbefüllt mit dem geerbten Stand
        ->set('regenForm.temp_c', 180)
        ->call('regenSpeichern');

    $gespeichert = DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $this->gericht->id)->whereNull('deleted_at')->get();

    expect($gespeichert)->toHaveCount(1)
        ->and((int) $gespeichert[0]->ingredient_id)->toBe((int) $this->zutat->id)
        ->and((int) $gespeichert[0]->temp_c)->toBe(180);

    $zeile = (new Kaskade)->fuerRezept($this->gericht->fresh())['komponenten'][0];
    expect($zeile['herkunft'])->toBe(Kaskade::HERKUNFT_OVERRIDE)->and($zeile['temp_c'])->toBe(180);
});

it('zurücksetzen gibt der Komponente wieder den Ton an', function () {
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->gericht->id)
        ->call('regenKomponenteBearbeiten', $this->zutat->id)
        ->set('regenForm.temp_c', 180)
        ->call('regenSpeichern');

    $overrideId = (int) DB::table('foodalchemist_recipe_regenerations')
        ->where('recipe_id', $this->gericht->id)->whereNull('deleted_at')->value('id');

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->gericht->id)
        ->call('regenOverrideZuruecksetzen', $overrideId);

    $zeile = (new Kaskade)->fuerRezept($this->gericht->fresh())['komponenten'][0];
    expect($zeile['herkunft'])->toBe(Kaskade::HERKUNFT_GEERBT)->and($zeile['temp_c'])->toBe(150);
});

it('ein Komponenten-Tausch löst den Override, statt ihn stumm umzuhängen', function () {
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->gericht->id)
        ->call('regenKomponenteBearbeiten', $this->zutat->id)
        ->set('regenForm.temp_c', 180)
        ->call('regenSpeichern');

    $andere = ($this->rezept)('Ratatouille (neu)');
    FoodAlchemistRecipeRegeneration::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $andere->id,
        'component_label' => 'Ratatouille (neu)', 'temp_c' => 165, 'sort_order' => 0,
    ]);

    // Der Tausch haengt DIESELBE Zeilen-Id auf ein anderes Rezept um. Ein ueberlebender
    // Override wuerde danach etwas beschreiben, das nie jemand entschieden hat.
    $bilanz = app(RecipeService::class)->ersetzeInVerwendungen(
        $this->rootTeam, (int) $this->komponente->id, (int) $andere->id
    );

    expect($bilanz['regen_overrides_geloest'])->toBe(1);

    $zeile = (new Kaskade)->fuerRezept($this->gericht->fresh())['komponenten'][0];
    expect($zeile['herkunft'])->toBe(Kaskade::HERKUNFT_GEERBT)
        ->and($zeile['temp_c'])->toBe(165)
        ->and($zeile['von_recipe_name'])->toBe('Ratatouille (neu)');
});
