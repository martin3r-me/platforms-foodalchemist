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

    // Rezept mit Zutaten in wählbaren Einheiten (an $this gebunden → Zugriff auf rootTeam).
    $this->mkRezept = function (array $zutaten) {
        $this->seedTeamHierarchy();
        $units = [];
        foreach ([
            ['slug' => 'g', 'dimension' => 'mass', 'default_in_g' => 1],
            ['slug' => 'kg', 'dimension' => 'mass', 'default_in_g' => 1000],
            ['slug' => 'l', 'dimension' => 'volume', 'default_in_ml' => 1000],
            ['slug' => 'stk', 'dimension' => 'count'],
        ] as $u) {
            $units[$u['slug']] = FoodAlchemistVocabEinheit::create([
                'team_id' => $this->rootTeam->id, 'display_de' => $u['slug'], ...$u,
            ]);
        }
        $recipe = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => 'r_' . uniqid(), 'name' => 'Testrezept', 'status' => 'draft',
        ]);
        foreach (array_values($zutaten) as $i => [$name, $menge, $unitSlug]) {
            $recipe->ingredients()->create([
                'team_id' => $this->rootTeam->id, 'position' => $i + 1, 'raw_text' => $name, 'display_name' => $name,
                'quantity' => $menge, 'unit_vocab_id' => $units[$unitSlug]->id, 'match_method' => 'unmatched',
            ]);
        }

        return [$recipe->refresh(), $units];
    };
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

it('Modus A — rescaleRecipe: alle Mengen × Faktor, %-Verhältnis bleibt', function () {
    [$recipe] = ($this->mkRezept)([['Mehl', 1000, 'g'], ['Wasser', 650, 'g']]);

    $res = $this->svc->rescaleRecipe($this->rootTeam, $recipe->id, 2.0);

    $mengen = $recipe->refresh()->ingredients()->pluck('quantity', 'display_name');
    expect((float) $mengen['Mehl'])->toBe(2000.0)
        ->and((float) $mengen['Wasser'])->toBe(1300.0)
        ->and($res['factor'])->toBe(2.0);
    // %-Sicht unverändert (65 %) — Batch-Skalierung ändert Verhältnisse nicht
    $sicht = $this->svc->bakerPercentagesForRecipe($this->rootTeam, $recipe->id);
    expect(collect($sicht['lines'])->firstWhere('name', 'Wasser')['baker_percent'])->toBe(65.0);
});

it('Modus A — rescaleToReferenceMass: Referenzzutat auf Zielmasse, Rest proportional', function () {
    [$recipe] = ($this->mkRezept)([['Mehl', 1000, 'g'], ['Wasser', 650, 'g']]);

    $this->svc->rescaleToReferenceMass($this->rootTeam, $recipe->id, 1500);   // Mehl (schwerste) → 1500

    $mengen = $recipe->refresh()->ingredients()->pluck('quantity', 'display_name');
    expect((float) $mengen['Mehl'])->toBe(1500.0)
        ->and((float) $mengen['Wasser'])->toBe(975.0);   // 650 × 1,5
});

it('Modus B — setIngredientBakerPercent: eine Zutat übers % justieren (Masse-Einheit)', function () {
    [$recipe] = ($this->mkRezept)([['Mehl', 1000, 'g'], ['Wasser', 650, 'g']]);
    $wasser = $recipe->ingredients()->where('display_name', 'Wasser')->first();

    $res = $this->svc->setIngredientBakerPercent($this->rootTeam, $recipe->id, $wasser->id, 70);

    expect($res['new_mass_g'])->toBe(700.0)                 // 70 % von 1000 g Mehl
        ->and((float) $wasser->refresh()->quantity)->toBe(700.0)
        ->and((float) $recipe->refresh()->ingredients()->where('display_name', 'Mehl')->value('quantity'))->toBe(1000.0);   // Ref unangetastet
});

it('Modus B — kg-Zutat: Gramm korrekt in kg-Menge zurückgerechnet', function () {
    [$recipe] = ($this->mkRezept)([['Mehl', 1000, 'g'], ['Butter', 0.2, 'kg']]);
    $butter = $recipe->ingredients()->where('display_name', 'Butter')->first();

    $this->svc->setIngredientBakerPercent($this->rootTeam, $recipe->id, $butter->id, 30);   // 30 % von 1000 g = 300 g

    expect((float) $butter->refresh()->quantity)->toBe(0.3);   // 300 g → 0,3 kg
});

it('Modus B — Einheiten-Guard: Stück/Liter sind read-only (% ist massebasiert)', function () {
    [$recipe] = ($this->mkRezept)([['Mehl', 1000, 'g'], ['Eier', 6, 'stk'], ['Milch', 0.5, 'l']]);
    $eier = $recipe->ingredients()->where('display_name', 'Eier')->first();
    $milch = $recipe->ingredients()->where('display_name', 'Milch')->first();

    expect(fn () => $this->svc->setIngredientBakerPercent($this->rootTeam, $recipe->id, $eier->id, 50))
        ->toThrow(RuntimeException::class, 'nicht massebasiert');
    expect(fn () => $this->svc->setIngredientBakerPercent($this->rootTeam, $recipe->id, $milch->id, 50))
        ->toThrow(RuntimeException::class, 'read-only');
    // Mengen unverändert
    expect((float) $eier->refresh()->quantity)->toBe(6.0)->and((float) $milch->refresh()->quantity)->toBe(0.5);
});

it('MCP proportion.APPLY: rescale + set_baker_percent schreiben zurück; Guard meldet Fehler', function () {
    [$recipe] = ($this->mkRezept)([['Mehl', 1000, 'g'], ['Wasser', 650, 'g'], ['Eier', 6, 'stk']]);
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $ctx = new ToolContext($user, $this->rootTeam);
    $tool = app(ToolRegistry::class)->get('foodalchemist.proportion.APPLY');

    expect($tool)->not->toBeNull()->and($tool->getMetadata()['read_only'])->toBeFalse();

    $r = $tool->execute(['operation' => 'rescale', 'recipe_id' => $recipe->id, 'factor' => 3], $ctx);
    expect($r->success)->toBeTrue()->and($r->data['changed'])->toBe(3)
        ->and((float) $recipe->refresh()->ingredients()->where('display_name', 'Mehl')->value('quantity'))->toBe(3000.0);

    $wasser = $recipe->ingredients()->where('display_name', 'Wasser')->first();   // jetzt 1950 g, Mehl 3000 g
    $b = $tool->execute(['operation' => 'set_baker_percent', 'recipe_id' => $recipe->id, 'ingredient_id' => $wasser->id, 'pct' => 50], $ctx);
    expect($b->success)->toBeTrue()->and($b->data['new_mass_g'])->toBe(1500.0);   // 50 % von 3000 g

    $eier = $recipe->ingredients()->where('display_name', 'Eier')->first();
    $guard = $tool->execute(['operation' => 'set_baker_percent', 'recipe_id' => $recipe->id, 'ingredient_id' => $eier->id, 'pct' => 20], $ctx);
    expect($guard->success)->toBeFalse();   // Stück → Guard
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
