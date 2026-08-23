<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGeschirrItem;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Regression: trägt die Standard-Darreichung eines VK-Gerichts ein Geschirr-Item, muss das
 * Produktionsblatt dessen Label auflösen. Vor dem Fix degenerierte der
 * DB::table('foodalchemist_tableware_items')->whereKey(...)-Lookup (whereKey existiert nur auf
 * dem Eloquent-, nicht dem Query-Builder) zu `where key = ?` → 500 „Unknown column 'key'"
 * auf der Produktions-Editor-Seite (PlanungsblattService::darreichungsInfo).
 */
it('Produktionsblatt löst das Geschirr-Label der Standard-Darreichung auf (kein where-key-Crash)', function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));

    $sf = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'teller', 'team_id' => $this->rootTeam->id],
        ['label' => 'Teller']
    );
    $geschirr = FoodAlchemistGeschirrItem::create([
        'team_id' => $this->rootTeam->id,
        'label' => 'Coupteller weiß 28 cm',
        'category' => 'Teller',
    ]);
    $gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'geschirr-vk', 'name' => 'Tellergericht',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 18.00,
    ]);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'tableware_item_id' => $geschirr->id,
    ]);

    $blatt = app(PlanungsblattService::class)
        ->produktionsblatt($this->rootTeam, ['recipe_id' => $gericht->id, 'persons' => 10]);

    $top = collect($blatt['rezepte'])->firstWhere('recipe_id', $gericht->id);
    expect($top)->not->toBeNull();
    expect(data_get($top, 'darreichung.geschirr'))->toBe('Coupteller weiß 28 cm');
});
