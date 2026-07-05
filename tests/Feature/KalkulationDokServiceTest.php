<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\KalkulationDokService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M-K10 / Doc 16 §11: Standalone Kalkulations-Composer — Positionen (Gericht/
 * Basisrezept/GP/frei) → HK1 (Σ Wareneinsatz) + Arbeitszeit-Rollup → HK2 (Settings-
 * Zuschläge) → VK-Vorschlag (Marge bzw. Override).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(KalkulationDokService::class);
    app(TeamSettingsService::class)->update($this->rootTeam, [
        'hk2_surcharge_pct' => 20, 'stundensatz_eur' => 30, 'marge_pct' => 15,
    ]);
});

it('Gericht-Position zieht Wareneinsatz + Arbeitszeit pro Portion als Snapshot', function () {
    // ek_total 20 € auf 4 Portionen = 5 €/Portion; 40 min / 4 = 10 min/Portion.
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'HG: Filet', 'status' => 'approved',
        'is_sales_recipe' => true, 'ek_total_eur' => 20.00, 'sales_unit_count' => 4, 'work_time_min' => 40,
    ]);
    $k = $this->svc->create($this->rootTeam, 'Test');
    $p = $this->svc->addPosition($this->rootTeam, $k->id, 'gericht', $g->id);

    expect($p->unit)->toBe('Portion')
        ->and((float) $p->einzel_ek)->toBe(5.0)
        ->and((int) $p->work_time_min)->toBe(10)
        ->and($p->label)->toBe('HG: Filet');
});

it('Basisrezept-Position zieht €/kg, GP-Position rechnet in kg', function () {
    $b = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'b', 'name' => 'Basis-Sauce', 'status' => 'approved',
        'is_sales_recipe' => false, 'ek_per_kg_eur' => 8.00,
    ]);
    $k = $this->svc->create($this->rootTeam, 'Test');
    $p = $this->svc->addPosition($this->rootTeam, $k->id, 'basisrezept', $b->id);

    expect($p->unit)->toBe('kg')->and((float) $p->einzel_ek)->toBe(8.0)
        ->and($p->work_time_min)->toBeNull();
});

it('berechne: HK1 = Σ Wareneinsatz, Lohn aus Arbeitszeit-Rollup, HK2 + Marge-Override', function () {
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'Gericht', 'status' => 'approved',
        'is_sales_recipe' => true, 'ek_total_eur' => 20.00, 'sales_unit_count' => 4, 'work_time_min' => 40,
    ]);
    $b = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'b', 'name' => 'Basis', 'status' => 'approved',
        'is_sales_recipe' => false, 'ek_per_kg_eur' => 8.00,
    ]);
    $k = $this->svc->create($this->rootTeam, 'Menü');
    // 10 Portionen Gericht (WE 50, az 100 min) + 2 kg Basis (WE 16) + freie Zeile (WE 3).
    $this->svc->addPosition($this->rootTeam, $k->id, 'gericht', $g->id, ['quantity' => 10]);
    $this->svc->addPosition($this->rootTeam, $k->id, 'basisrezept', $b->id, ['quantity' => 2]);
    $this->svc->addPosition($this->rootTeam, $k->id, 'frei', null, ['label' => 'Deko', 'einzel_ek' => 3, 'quantity' => 1]);

    $r = $this->svc->berechne($this->rootTeam, $k->refresh());

    // HK1 = 50 + 16 + 3 = 69. FEK = 100 min @ 30 €/h = 50. Material-GK = 20 % × 69 = 13,80.
    // HK2 = 69 + 50 + 13,80 = 132,80.
    expect($r['hk1'])->toBe(69.0)
        ->and($r['work_time_min'])->toBe(100.0)
        ->and($r['hk2'])->toBe(132.8)
        ->and($r['vk_vorschlag'])->toBe(152.72);                  // 132,80 × 1,15 (Team-Marge)

    // Marge-Override 25 % → 132,80 × 1,25 = 166,00.
    $this->svc->update($this->rootTeam, $k->id, ['marge_override_pct' => 25]);
    $r2 = $this->svc->berechne($this->rootTeam, $k->refresh());
    expect($r2['vk_vorschlag'])->toBe(166.0)->and($r2['marge_pct'])->toBe(25.0);
});

it('Position aktualisieren + entfernen', function () {
    $k = $this->svc->create($this->rootTeam, 'T');
    $p = $this->svc->addPosition($this->rootTeam, $k->id, 'frei', null, ['label' => 'X', 'einzel_ek' => 10, 'quantity' => 1]);

    $this->svc->updatePosition($this->rootTeam, $p->id, ['quantity' => '3', 'einzel_ek' => '4,50']);
    expect($this->svc->berechne($this->rootTeam, $k->refresh())['hk1'])->toBe(13.5);

    $this->svc->removePosition($this->rootTeam, $p->id);
    expect($k->refresh()->positionen()->count())->toBe(0);
});

it('quellen: Gerichte vs Basisrezepte getrennt, nach Name filterbar', function () {
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'v', 'name' => 'Verkauf A', 'status' => 'approved', 'is_sales_recipe' => true]);
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'x', 'name' => 'Basis B', 'status' => 'approved', 'is_sales_recipe' => false]);

    $gerichte = collect($this->svc->quellen($this->rootTeam, 'gericht'))->pluck('label');
    $basen = collect($this->svc->quellen($this->rootTeam, 'basisrezept'))->pluck('label');

    expect($gerichte)->toContain('Verkauf A')->not->toContain('Basis B')
        ->and($basen)->toContain('Basis B')->not->toContain('Verkauf A');
});
