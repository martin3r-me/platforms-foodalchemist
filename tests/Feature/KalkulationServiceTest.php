<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\KalkulationService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M12-01: HK2-Zuschlagskalkulation — HK1 (Wareneinsatz) → HK2 = HK1×(1+Zuschlag)+
 * Nebenkosten; Vollkosten-Deckungsbeitrag gegen VK. Gericht + Concept.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->kalk = app(KalkulationService::class);
    app(TeamSettingsService::class)->update($this->rootTeam, ['hk2_surcharge_pct' => 20]); // 20 % Gemeinkosten
});

it('M12: recipeHk — HK1/HK2 pro Portion + Vollkosten-DB', function () {
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g1', 'name' => 'HG: Filet', 'status' => 'approved',
        'is_sales_recipe' => true, 'ek_total_eur' => 10.00, 'sales_unit_count' => 5,
        'additional_costs_eur' => 2.50, 'sales_net' => 8.00,
    ]);

    $hk = $this->kalk->recipeHk($this->rootTeam, $r);
    expect($hk['hk1_pro_portion'])->toBe(2.00)                       // 10,00 / 5
        ->and($hk['hk2_total'])->toBe(14.5)                          // 10 × 1,2 + 2,5
        ->and($hk['hk2_pro_portion'])->toBe(2.90)                    // 14,50 / 5
        ->and($hk['zuschlag_pct'])->toBe(20.0)
        ->and($hk['db_eur'])->toBe(5.10)                             // 8,00 − 2,90
        ->and($hk['db_pct'])->toBe(63.8)                             // 5,10 / 8,00
        ->and($hk['wareneinsatz_pct'])->toBe(25.0);                  // 2,00 / 8,00
});

it('M12: conceptHk — HK2 pro Person + DB gegen Concept-€/Person', function () {
    $paket = app(PaketService::class)->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise', 'price_mode' => 'manuell']);
    app(PaketService::class)->update($this->rootTeam, $paket->id, ['price_per_person' => 4.50, 'ek_per_person' => 1.35]);
    $concept = app(ConceptService::class)->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = app(ConceptService::class)->addSlot($this->rootTeam, $concept->id, ['role' => 'Vorspeise']);
    app(ConceptService::class)->fillSlot($this->rootTeam, $slot->id, ['package_id' => $paket->id]);

    $hk = $this->kalk->conceptHk($this->rootTeam, $concept->refresh());
    expect($hk['hk1_pro_person'])->toBe(1.35)
        ->and($hk['hk2_pro_person'])->toBe(1.62)                     // 1,35 × 1,2
        ->and($hk['vk_pro_person'])->toBe(4.50)
        ->and($hk['db_eur'])->toBe(2.88)                             // 4,50 − 1,62
        ->and($hk['db_pct'])->toBe(64.0);
});

it('M12: ohne Zuschlag/Nebenkosten ist HK2 = HK1', function () {
    app(TeamSettingsService::class)->update($this->childA, ['hk2_surcharge_pct' => 0]);
    expect($this->kalk->hk2($this->childA, 5.0))->toBe(5.0);
});
