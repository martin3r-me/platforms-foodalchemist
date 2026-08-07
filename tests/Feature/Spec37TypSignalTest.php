<?php

use Platform\FoodAlchemist\Services\RecipeGenerationContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * Spec 37 (2026-08-07): der Rezept-Typ (Gericht vs. Basisrezept) geht als eigenes Kontext-Feld
 * an die KI (Gürtel & Hosenträger zur Prompt-Einleitung) und steuert die typ-abhängige
 * Niveau-Auswahl. Hier nur das Kontext-Feld — die Niveau-Wahl ist in KnowledgeContextTest.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(fn () => $this->seedTeamHierarchy());

it('build() setzt rezept_typ typ-abhängig', function () {
    $svc = app(RecipeGenerationContextService::class);

    $basis = $svc->build($this->rootTeam, 'Tomatensuppe', [], false);
    expect($basis['prompt'])->toHaveKey('rezept_typ')
        ->and($basis['prompt']['rezept_typ'])->toContain('BASISREZEPT');

    $gericht = $svc->build($this->rootTeam, 'Tomatensuppe', [], true);
    expect($gericht['prompt']['rezept_typ'])->toContain('GERICHT');
});
