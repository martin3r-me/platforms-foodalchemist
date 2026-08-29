<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — die Speisekarte-Bauansicht (Board) rechnet VK/EK/WE je Position gegen die
 * Betriebsbrille. `boardDaten`/`positionEkVk` reichen den Betrieb an das outlet-fähige
 * `positionPreis` durch (vorher team-baseline, Brille wirkte nur in der Präsentation).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->childA);

    app(TeamSettingsService::class)->update($this->childA, ['target_food_cost_pct' => 25]);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Betrieb Nord']);
    app(OutletSettingsService::class)->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 20]);   // Basissatz 5,0

    $this->gericht = $this->makeRecipe($this->childA, 'HG Zander', ['is_sales_recipe' => true, 'sales_net' => 99.0, 'ek_total_eur' => 10.0]);
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10, 'sales_net' => 99.0,
    ]);
    $karteId = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $this->kontext)->data['speisekarte']['id'];
    $rubrikId = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute([
        'speisekarte_id' => $karteId, 'title' => 'Fisch', 'art' => 'speisen',
    ], $this->kontext)->data['rubrik']['id'];
    $this->posId = (int) $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
        'rubrik_id' => $rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id,
    ], $this->kontext)->data['position']['id'];
    $this->karte = FoodAlchemistSpeisekarte::find($karteId);
});

it('boardDaten: Positions-VK folgt der Brille (Baseline 99 vs Betrieb 50)', function () {
    $svc = app(SpeisekarteService::class);

    $baseline = $svc->boardDaten($this->childA, $this->karte);
    $mitBetrieb = $svc->boardDaten($this->childA, $this->karte, $this->betrieb);

    expect((float) $baseline['positionen'][$this->posId]['vk'])->toBe(99.0)
        ->and((float) $mitBetrieb['positionen'][$this->posId]['vk'])->toBe(50.0)
        // EK bleibt kostenseitig gleich; WE folgt dem VK (10/50 = 20 % beim Betrieb).
        ->and($mitBetrieb['positionen'][$this->posId]['we'])->toBe(20.0);
});
