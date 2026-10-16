<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Regression: trägt die Standard-Darreichung eines VK-Gerichts ein Gefäß (Non-Food-GP, WG 16), muss das
 * Produktionsblatt dessen Namen und den wirksamen Servier-Typ auflösen (Planungsblatt darreichungsInfo;
 * früher das Label des Geschirr-Artikels, der mit der Geschirr-DB entfallen ist).
 */
it('Produktionsblatt löst Gefäß und Servier-Typ der Standard-Darreichung auf', function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));

    $sf = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'teller', 'team_id' => $this->rootTeam->id],
        ['label' => 'Teller']
    );
    $typ = \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_serving_vehicles')->insertGetId(['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'slug' => 'teller-flach', 'name' => 'Teller flach', 'sort_order' => 1, 'is_inactive' => false, 'created_at' => now(), 'updated_at' => now()]);
    $gefaess = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'pb|coupteller', 'name' => 'Coupteller: Porzellan, Ø 28 cm', 'status' => 'approved',
        'commodity_group_code' => '16', 'serving_vehicle_vocab_id' => $typ]);
    $gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'geschirr-vk', 'name' => 'Tellergericht',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 18.00,
    ]);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'vessel_gp_id' => $gefaess->id,
    ]);

    $blatt = app(PlanungsblattService::class)
        ->produktionsblatt($this->rootTeam, ['recipe_id' => $gericht->id, 'persons' => 10]);

    $top = collect($blatt['rezepte'])->firstWhere('recipe_id', $gericht->id);
    expect($top)->not->toBeNull();
    expect(data_get($top, 'darreichung.geschirr'))->toBe('Coupteller: Porzellan, Ø 28 cm')
        ->and(data_get($top, 'darreichung.vehikel'))->toBe('Teller flach');   // Typ aus dem Gefäß, nichts kopiert
});
