<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\BenchmarkService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R2.7 — Portfolio-Benchmark (BHG-intern): Team-KPIs vs. anonymisierter Peer-Median
 * derselben Root-Kette. Datenschutz: nur Aggregat, keine Fremd-Gericht-Details.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(BenchmarkService::class);

    $mk = function ($team, $key, $name, $vk, $ek, $conf) {
        return FoodAlchemistRecipe::create([
            'team_id' => $team->id, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved',
            'is_sales_recipe' => true, 'sales_net' => $vk, 'ek_total_eur' => $ek, 'allergens_confidence' => $conf,
        ]);
    };
    // childB-Portfolio: bekannte Werte → n=2, EK-Cov 100%, Allergen-hoch 50%, Ø-W% = (30+40)/2 = 35
    $mk($this->childB, 'b1', 'GEHEIM-Lachstatar', 10.0, 3.0, 'high');
    $mk($this->childB, 'b2', 'GEHEIM-Rinderfilet', 20.0, 8.0, 'low');
    // childA-Portfolio: n=1, EK-Cov 100%, Allergen-hoch 100%, Ø-W% = 50
    $mk($this->childA, 'a1', 'Eigen-Suppe', 4.0, 2.0, 'high');
});

it('kpisFuerTeam rechnet Portfolio-Kennzahlen korrekt', function () {
    $k = $this->svc->kpisFuerTeam($this->childB->id);
    expect($k['n_dishes'])->toBe(2)
        ->and($k['ek_coverage_pct'])->toBe(100.0)
        ->and($k['allergen_high_pct'])->toBe(50.0)
        ->and($k['avg_w_pct'])->toBe(35.0)
        ->and($k['forms_complete_pct'])->toBe(0.0);   // keine Darreichungen angelegt
});

it('benchmark: childA vs. Peer-Median (nur childB hat Portfolio; root leer → kein Peer)', function () {
    $b = $this->svc->benchmark($this->childA);

    expect($b['n_peers'])->toBe(1)                     // childB; root hat 0 Gerichte → gefiltert
        ->and($b['team_kpis']['avg_w_pct'])->toBe(50.0)
        ->and($b['peer_median']['avg_w_pct'])->toBe(35.0)      // = childB
        ->and($b['peer_median']['allergen_high_pct'])->toBe(50.0)
        ->and($b['peer_median']['n_dishes'])->toBe(2.0);
});

it('benchmark: root sieht 2 Peers (childA+childB), Median korrekt', function () {
    // Ø-W%: childA=50, childB=35 → Median (2 Werte) = 42.5
    $b = $this->svc->benchmark($this->rootTeam);
    expect($b['n_peers'])->toBe(2)
        ->and($b['peer_median']['avg_w_pct'])->toBe(42.5)
        ->and($b['peer_median']['n_dishes'])->toBe(1.5);       // (1+2)/2
});

it('Datenschutz: Benchmark-Response enthält KEINE Fremd-Gericht-Namen (Leak-Grep)', function () {
    $b = $this->svc->benchmark($this->childA);
    $json = json_encode($b);
    expect($json)->not->toContain('GEHEIM-Lachstatar')
        ->and($json)->not->toContain('GEHEIM-Rinderfilet')
        ->and($json)->not->toContain('childB');
});

it('Einzel-Gastronom ohne Peers: n_peers=0, Median null', function () {
    // frisches Team ohne Geschwister-Portfolio: childA-Portfolio löschen wäre nötig;
    // stattdessen childB leeren → childA hat keinen Peer mit Portfolio mehr
    FoodAlchemistRecipe::where('team_id', $this->childB->id)->forceDelete();
    $b = $this->svc->benchmark($this->childA);
    expect($b['n_peers'])->toBe(0)
        ->and($b['peer_median']['avg_w_pct'])->toBeNull()
        ->and($b['team_kpis']['n_dishes'])->toBe(1);            // eigene Zahlen bleiben
});
