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

it('am Gericht wählt man den Behälter-TYP, nicht die Anzahl', function () {
    // Befund aus dem Browser: der alte Block hatte zwei Skalare (warm/kalt) und ein
    // handgetipptes »n«. Warm/kalt ist eine Temperatur-Achse, und die Anzahl ist eine Rechnung.
    $gn = DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) \Illuminate\Support\Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'gn_11_65mm', 'name' => 'GN 1/1 65mm', 'sort_order' => 1, 'familie' => 'GN',
        'laenge_mm' => 530, 'breite_mm' => 325, 'tiefe_mm' => 65, 'volumen_l' => 8.8,
        'nutzfaktor' => 0.85, 'eignung' => json_encode(['abfuellen', 'regenerieren', 'ausgabe']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->gericht->id)
        ->set('behaelterForm.ausgabe.container_vocab_id', (string) $gn)
        ->set('behaelterForm.ausgabe.referenz_menge_kg', '6')
        ->set('behaelterForm.ausgabe.skalierung', 'hoehe_gebunden')
        ->call('speichern');

    $zeile = DB::table('foodalchemist_recipe_containers')
        ->where('recipe_id', $this->gericht->id)->where('zweck', 'ausgabe')->whereNull('deleted_at')->first();

    expect((int) $zeile->container_vocab_id)->toBe($gn)
        ->and((float) $zeile->referenz_menge_kg)->toBe(6.0);

    // Es gibt kein Feld mehr, in das eine Anzahl getippt werden koennte.
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeContainer::query()->first()->getAttributes())
        ->not->toHaveKey('anzahl');
});

it('leerer Behälter am Gericht heisst »wie die Komponente« — und löscht den Override', function () {
    $gn = DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) \Illuminate\Support\Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'gn_11_100mm', 'name' => 'GN 1/1 100mm', 'sort_order' => 2, 'familie' => 'GN',
        'volumen_l' => 13.7, 'nutzfaktor' => 0.85,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->gericht->id)
        ->set('behaelterForm.ausgabe.container_vocab_id', (string) $gn)
        ->call('speichern');

    expect(DB::table('foodalchemist_recipe_containers')
        ->where('recipe_id', $this->gericht->id)->whereNull('deleted_at')->count())->toBe(1);

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->gericht->id)
        ->set('behaelterForm.ausgabe.container_vocab_id', '')
        ->call('speichern');

    expect(DB::table('foodalchemist_recipe_containers')
        ->where('recipe_id', $this->gericht->id)->whereNull('deleted_at')->count())->toBe(0);
});
