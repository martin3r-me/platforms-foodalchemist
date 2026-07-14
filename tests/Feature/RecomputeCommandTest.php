<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * P4 — Bulk-Recompute-Command: --apply-Gate + korrekte EK-Berechnung aus der
 * geheilten GP→LA→Preis-Kette.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // bepreiste Kette: GP mit Lead-LA (10 €/kg) → 0,01 €/g
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->gp = $this->makeGp($this->rootTeam, 'Butter');
    $this->gp->update(['status' => 'approved']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Butter 1 kg', 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 10.0, 'status' => '0', 'valid_to' => null]);
    $this->gp->update(['lead_la_supplier_item_id' => $la->id]);

    $g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $this->recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'r-butter', 'name' => 'Buttersauce', 'status' => 'approved', 'is_sales_recipe' => false,
    ]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id, 'gp_id' => $this->gp->id,
        'raw_text' => 'Butter', 'quantity' => '100', 'unit_vocab_id' => $g->id, 'position' => 1,
    ]);

    // sauberer „Vorher"-Zustand ohne Model-Hooks
    DB::table('foodalchemist_recipes')->where('id', $this->recipe->id)->update(['ek_total_eur' => null]);
});

it('dry-run rechnet nicht (EK bleibt leer)', function () {
    $this->artisan('foodalchemist:recompute', ['--all' => true])->assertSuccessful();
    expect($this->recipe->refresh()->ek_total_eur)->toBeNull();
});

it('--apply rechnet die EK aus der bepreisten Kette (100 g × 0,01 €/g = 1,00 €)', function () {
    $this->artisan('foodalchemist:recompute', ['--all' => true, '--apply' => true])->assertSuccessful();
    expect(round((float) $this->recipe->refresh()->ek_total_eur, 2))->toBe(1.0);
});

it('--recipe ohne --all rechnet gezielt', function () {
    $this->artisan('foodalchemist:recompute', ['--recipe' => $this->recipe->id, '--apply' => true])->assertSuccessful();
    expect(round((float) $this->recipe->refresh()->ek_total_eur, 2))->toBe(1.0);
});
