<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\GeneratorModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * Kontext-Inspektor (2026-08-07): die VOLLE Modal-Render-Kette — Livewire-Komponente mit
 * gesetztem $ergebnis['kontext'] muss das Panel „Verwendetes Wissen" tatsächlich ausgeben.
 * Diese Schicht fehlte (isolierter Blade-Render + contextFor-Gruppierung waren getestet, aber
 * nicht das Zusammenspiel im echten Modal → genau da hakte es auf demo).
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('GeneratorModal rendert das Kontext-Panel, wenn ergebnis[kontext] gesetzt ist', function () {
    Livewire::test(GeneratorModal::class)
        ->set('ergebnis', [
            'recipe_id' => 1,
            'name' => 'Suppe: Tomate',
            'statistik' => ['bestand_gp' => 8, 'bestand_sub' => 0, 'stub_neu' => 0, 'offen' => 0, 'stubs' => []],
            'offene' => [],
            'kontext' => [
                'wissen' => [
                    'cross_cutting' => ['substitutionen@v1', 'mengen_defaults@v1'],
                    'domain' => ['gemuese@v1'],
                ],
                'chars' => 4321,
                'templates' => [],
            ],
        ])
        ->assertSee('Verwendetes Wissen')
        ->assertSee('Cross-Cutting')
        ->assertSee('Domänen')
        ->assertSee('substitutionen')
        ->assertSee('gemuese');
});

it('GeneratorModal zeigt KEIN Panel, wenn kontext null ist (Alt-Ergebnisse / Override-Pfad)', function () {
    Livewire::test(GeneratorModal::class)
        ->set('ergebnis', [
            'recipe_id' => 1, 'name' => 'X',
            'statistik' => ['bestand_gp' => 0, 'bestand_sub' => 0, 'stub_neu' => 0, 'offen' => 0, 'stubs' => []],
            'offene' => [], 'kontext' => null,
        ])
        ->assertDontSee('Verwendetes Wissen');
});
