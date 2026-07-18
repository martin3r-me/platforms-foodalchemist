<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ProportionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * #513 Tier 1 / Punkt 1 — Grammaturen-Rechner: exakte Formeln gegen hand-
 * gerechnete Fälle (Modernist Cuisine), Bäckerprozent-Sicht eines Rezepts,
 * MCP-Tool. Grammatur bleibt Master, Prozente = abgeleitete Sicht.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->svc = app(ProportionService::class);
});

it('Bäckerprozent: pct = m/ref×100 und Rückweg', function () {
    expect($this->svc->bakerPercent(200, 1000))->toBe(20.0)
        ->and($this->svc->bakerMass(20, 1000))->toBe(200.0)
        ->and($this->svc->bakerPercent(650, 1000))->toBe(65.0)
        ->and($this->svc->bakerPercent(5, 0))->toBeNull();   // keine Basis → null (kein Div/0)
});

it('Extraprozent: m = pct/100×Σandere (Hydrokolloid/Salz) + Rückweg', function () {
    // 0,3 % Xanthan auf 800 g übrige Masse = 2,4 g
    expect($this->svc->extraMass(0.3, 800))->toBe(2.4)
        ->and($this->svc->extraPercent(2.4, 800))->toBe(0.3)
        ->and($this->svc->extraPercent(1, 0))->toBeNull();
});

it('Brining: Lake-Masse d·M/S + Zielgewicht; geliefertes Salz = d% von M', function () {
    // 1000 g Fleisch, Ziel 1 %, Lake 5 % → 200 g Lake, T = 1200 g
    expect($this->svc->briningBrineMassG(1000, 1, 5))->toBe(200.0)
        ->and($this->svc->briningTotalG(1000, 1, 5))->toBe(1200.0)
        ->and($this->svc->briningBrineMassG(1000, 1, 0))->toBeNull();   // Lake 0 % → null

    // Kontrolle: 200 g Lake × 5 % Salz = 10 g = 1 % von 1000 g
    expect($this->svc->briningBrineMassG(1000, 1, 5) * 0.05)->toBe(10.0);
});

it('Gelatine-Bloom: M_B = M_A·BloomA/BloomB (Marke + Sorte)', function () {
    // 8 g Gold (200) → Silber (160): schwächere Sorte braucht mehr = 10 g
    expect($this->svc->bloomConvert(8, 200, 160))->toBe(10.0)
        ->and($this->svc->bloomConvertBySorte(8, 'gold', 'silber'))->toBe(10.0)
        ->and($this->svc->bloomConvert(8, 200, 0))->toBeNull()          // Ziel-Bloom 0 → null
        ->and($this->svc->bloomConvertBySorte(8, 'gold', 'unbekannt'))->toBeNull();
});

it('Bäckerprozent-Sicht eines Rezepts: schwerste Zutat = 100 %', function () {
    $this->seedTeamHierarchy();
    $g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'brotteig', 'name' => 'Brotteig', 'status' => 'draft',
    ]);
    foreach ([['Mehl', 1000], ['Wasser', 650], ['Salz', 18]] as $i => [$name, $menge]) {
        $recipe->ingredients()->create([
            'team_id' => $this->rootTeam->id, 'position' => $i + 1, 'raw_text' => $name, 'display_name' => $name,
            'quantity' => $menge, 'unit_vocab_id' => $g->id, 'match_method' => 'unmatched',
        ]);
    }

    $sicht = $this->svc->bakerPercentagesForRecipe($this->rootTeam, $recipe->id);

    expect($sicht['ref_mass_g'])->toBe(1000.0);
    $byName = collect($sicht['lines'])->keyBy('name');
    expect($byName['Mehl']['baker_percent'])->toBe(100.0)
        ->and($byName['Wasser']['baker_percent'])->toBe(65.0)
        ->and($byName['Salz']['baker_percent'])->toBe(1.8);
});

it('MCP proportion.CALC: registriert + rechnet je operation (read-only)', function () {
    $this->seedTeamHierarchy();
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($user, $this->rootTeam);
    $tool = $registry->get('foodalchemist.proportion.CALC');

    expect($tool)->not->toBeNull()
        ->and($tool->getMetadata()['read_only'])->toBeTrue();

    expect($tool->execute(['operation' => 'extra_mass', 'pct' => 0.3, 'sum_other_g' => 800], $ctx)->data['mass_g'])->toBe(2.4);
    expect($tool->execute(['operation' => 'bloom', 'mass_g' => 8, 'sorte_a' => 'gold', 'sorte_b' => 'silber'], $ctx)->data['mass_g'])->toBe(10.0);

    $brining = $tool->execute(['operation' => 'brining', 'start_mass_g' => 1000, 'target_salinity' => 1, 'brine_salt' => 5], $ctx);
    expect($brining->data['brine_mass_g'])->toBe(200.0)->and($brining->data['target_total_g'])->toBe(1200.0);

    // Fehlerpfad: recipe_baker_percent ohne recipe_id
    expect($tool->execute(['operation' => 'recipe_baker_percent'], $ctx)->success)->toBeFalse();
});
