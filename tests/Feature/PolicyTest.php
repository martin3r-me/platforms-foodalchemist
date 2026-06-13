<?php

use Illuminate\Support\Facades\Gate;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M8-02: Policy-Vertrag (D1 als Gate) + statischer Trait-Vertrag über ALLE
 * Models (LogsActivity/BelongsToTeamHierarchy/HasUuidV7/SoftDeletes — der
 * LogsActivity-Stub ist in der Sandbox no-op, deshalb statisch statt
 * verhaltensbasiert; CLAUDE.md-Wissensbasis).
 */
it('Policy: Kind sieht Eltern-Daten (view), darf sie aber nie kuratieren (update/delete)', function () {
    $this->seedTeamHierarchy();
    $rootGp = $this->makeGp($this->rootTeam, 'Root-Katalog-GP');
    $rootUser = $this->makeUser($this->rootTeam, 'Root');
    $kindUser = $this->makeUser($this->childA, 'Kind A');
    $geschwisterGp = $this->makeGp($this->childB, 'B-GP');

    expect(Gate::forUser($rootUser)->allows('view', $rootGp))->toBeTrue()
        ->and(Gate::forUser($rootUser)->allows('update', $rootGp))->toBeTrue()
        ->and(Gate::forUser($kindUser)->allows('view', $rootGp))->toBeTrue()      // Kette aufwärts
        ->and(Gate::forUser($kindUser)->allows('update', $rootGp))->toBeFalse()   // Curate nur Besitzer
        ->and(Gate::forUser($kindUser)->allows('delete', $rootGp))->toBeFalse()
        ->and(Gate::forUser($kindUser)->allows('view', $geschwisterGp))->toBeFalse();  // Geschwister nie
});

it('Policy gilt auch für Rezepte (geteiltes Modell, beide Sichten)', function () {
    $this->seedTeamHierarchy();
    $kindUser = $this->makeUser($this->childA, 'Kind A');
    $rootRezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'r', 'name' => 'Fond: Root', 'status' => 'approved']);

    expect(Gate::forUser($kindUser)->allows('view', $rootRezept))->toBeTrue()
        ->and(Gate::forUser($kindUser)->allows('update', $rootRezept))->toBeFalse();
});

it('Trait-Vertrag: ALLE Models tragen LogsActivity + BelongsToTeamHierarchy + HasUuidV7 + SoftDeletes', function () {
    // Satelliten scopen BEWUSST über ihr Eltern-Aggregat (Zugriff nur via
    // GP-/Rezept-Relation bzw. TeamSettingsService) — kein eigener Team-Scope
    $satelliten = [
        'FoodAlchemistGpCountUnitDefault', 'FoodAlchemistGpLaPreference', 'FoodAlchemistMatchProposal',
        'FoodAlchemistRecipeIngredient', 'FoodAlchemistRecipeNiveauEignung', 'FoodAlchemistRecipeSektorEignung',
        'FoodAlchemistConceptSektorEignung', 'FoodAlchemistTeamSetting',
    ];
    $modelDir = dirname((new ReflectionClass(FoodAlchemistRecipe::class))->getFileName());
    $fehlend = [];
    foreach (glob($modelDir . '/*.php') as $datei) {
        $klasse = 'Platform\\FoodAlchemist\\Models\\' . basename($datei, '.php');
        if (! class_exists($klasse) || (new ReflectionClass($klasse))->isAbstract()) {
            continue;
        }
        $traits = collect(class_uses_recursive($klasse))->keys()->map(fn ($t) => class_basename($t));
        $pflichten = in_array(class_basename($klasse), $satelliten, true)
            ? ['LogsActivity', 'HasUuidV7', 'SoftDeletes']
            : ['LogsActivity', 'BelongsToTeamHierarchy', 'HasUuidV7', 'SoftDeletes'];
        if (class_basename($klasse) === 'FoodAlchemistPrice') {
            // Bewusst ohne LogsActivity: Massendaten (221k), Audit über Import-Reports (M2-Entscheid im Model)
            $pflichten = array_diff($pflichten, ['LogsActivity']);
        }
        foreach ($pflichten as $pflicht) {
            if (! $traits->contains($pflicht)) {
                $fehlend[] = class_basename($klasse) . " ohne {$pflicht}";
            }
        }
    }

    expect($fehlend)->toBe([]);
});
