<?php

use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 41 P3 / FIX-4 (DF-1): modus-orthogonaler Dedup-Guard — nie STILL duplizieren.
 * dedupGate ist ein read-only Post-Check → per Reflection deterministisch getestet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->recipes = app(RecipeService::class);
    $this->gen = app(RecipeGeneratorService::class);

    $this->gate = function ($recipe, string $bestand) {
        $m = new ReflectionMethod(RecipeGeneratorService::class, 'dedupGate');
        $m->setAccessible(true);
        $result = ['recipe' => $recipe, 'statistik' => [], 'offene' => []];

        return $m->invoke($this->gen, $this->rootTeam, $result, ['bestand' => $bestand]);
    };

    $this->mk = fn (string $name, bool $sales = false) => $this->recipes->create($this->rootTeam, [
        'name' => $name, 'is_sales_recipe' => $sales,
    ]);
});

it('flaggt Kollision bei Voll kreativ statt still zu duplizieren', function () {
    $erst = ($this->mk)('Kürbispüree');
    $neu = ($this->mk)('Kürbispüree');   // zweite Anlage (Klarname bleibt, nur recipe_key bekommt _2)

    $out = ($this->gate)($neu, 'komplett_neu');

    expect($out['statistik']['dedup']['kollision'])->toBeTrue()
        ->and($out['statistik']['dedup']['existing_id'])->toBe($erst->id)
        ->and($out['offene'])->toHaveCount(1)
        ->and($out['offene'][0]['primaer'])->toBe('dedup_kollision')
        ->and($out['offene'][0]['dedup_kollision']['existing_id'])->toBe($erst->id);
});

it('empfiehlt Bestand übernehmen bei nur_bestand/hybrid', function () {
    ($this->mk)('Rinderjus');
    $neu = ($this->mk)('Rinderjus');

    expect(($this->gate)($neu, 'nur_bestand')['offene'][0]['primaer'])->toBe('bestand_uebernehmen')
        ->and(($this->gate)($neu, 'hybrid')['offene'][0]['primaer'])->toBe('bestand_uebernehmen');
});

it('flaggt NICHT ohne Dublette', function () {
    $neu = ($this->mk)('Ganz eigenes Rezept XYZ');

    $out = ($this->gate)($neu, 'komplett_neu');

    expect($out['statistik']['dedup']['kollision'])->toBeFalse()
        ->and($out['statistik']['dedup']['geprueft'])->toBeTrue()
        ->and($out['offene'])->toBeEmpty();
});

it('trennt nach Typ: Basisrezept-Dublette kollidiert nicht mit gleichnamigem Verkaufsgericht', function () {
    ($this->mk)('Rinderjus', sales: false);   // Basisrezept
    $neuVk = ($this->mk)('Rinderjus', sales: true);   // Gericht — anderer Typ

    expect(($this->gate)($neuVk, 'komplett_neu')['statistik']['dedup']['kollision'])->toBeFalse();
});
