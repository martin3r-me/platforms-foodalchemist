<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Produktion\Editor;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #2 (Bug-Runde 2026-08): „Produktion wird doppelt übergeben bei erneutem Hinzufügen."
 *
 * Ursache: der Produktions-Editor hängte jedes Ziel mit einem `@uniqid()`-Suffix im source_ref an
 * (`zielHinzufuegen` / `zielEinfuegen`). Die Dedup matcht auf exakten source_ref → durch das uniqid
 * griff sie nie, dasselbe Rezept/Concept landete als zweites Ziel und `recomputeOrder` verdoppelte
 * die Menge. Das widersprach der eigenen Doku am `zielBearbeiten` („Re-Add ersetzt es").
 *
 * Fix: identitäts-stabiler source_ref (`recipe:<id>` / `concept:<id>`) + Identitäts-Dedup vor dem
 * Anhängen. Kapitel-/Angebots-Teilziele („…:c<idx>") bleiben unangetastet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('#2: dasselbe Basisrezept zweimal hinzufügen ergibt EIN Ziel (Re-Add ersetzt)', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Dedup-Basis', ['status' => 'draft', 'is_sales_recipe' => false]);

    $targets = Livewire::test(Editor::class)
        ->call('zielEinfuegen', 'basisrezept', $r->id, 50)
        ->call('zielEinfuegen', 'basisrezept', $r->id, 30)
        ->get('targets');

    expect($targets)->toHaveCount(1)
        ->and((int) $targets[0]['recipe_id'])->toBe($r->id)
        // Re-Add ERSETZT die Menge (kein Merge, keine Verdopplung) …
        ->and((float) ($targets[0]['portions'] ?? 0))->toBe(30.0)
        // … über einen identitäts-stabilen source_ref (kein uniqid).
        ->and($targets[0]['source_ref'])->toBe('recipe:' . $r->id);
});

it('#2: zwei verschiedene Rezepte bleiben zwei getrennte Ziele', function () {
    $a = $this->makeRecipe($this->rootTeam, 'Basis A', ['status' => 'draft', 'is_sales_recipe' => false]);
    $b = $this->makeRecipe($this->rootTeam, 'Basis B', ['status' => 'draft', 'is_sales_recipe' => false]);

    $targets = Livewire::test(Editor::class)
        ->call('zielEinfuegen', 'basisrezept', $a->id, 10)
        ->call('zielEinfuegen', 'basisrezept', $b->id, 20)
        ->get('targets');

    expect($targets)->toHaveCount(2);
});

it('#2: dasselbe Concept zweimal hinzufügen ergibt EIN Ziel', function () {
    $k = $this->makeConcept($this->rootTeam, 'Dedup-Concept');

    $targets = Livewire::test(Editor::class)
        ->call('zielEinfuegen', 'concept', $k->id, 100)
        ->call('zielEinfuegen', 'concept', $k->id, 80)
        ->get('targets');

    expect($targets)->toHaveCount(1)
        ->and($targets[0]['source_ref'])->toBe('concept:' . $k->id)
        ->and((float) ($targets[0]['persons'] ?? 0))->toBe(80.0);
});
