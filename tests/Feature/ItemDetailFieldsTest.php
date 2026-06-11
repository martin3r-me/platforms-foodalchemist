<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M2-16: nachgezogene Detail-Felder (Screen-3-Abgleich) + Vorbestellzeiten (V-29).
 */
it('persistiert alle nachgezogenen Felder inkl. Vorbestellzeit', function () {
    $this->seedTeamHierarchy();
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS Food']);

    $item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Sauerkraut 10l',
        'zusatztext' => 'Fass-Ware', 'vat' => 7.0, 'origin_country' => 'Deutschland',
        'organic_control_number' => 'DE-ÖKO-001', 'is_halal' => true, 'is_gmo_free' => true,
        'is_preorder' => true, 'preorder_days' => 89, 'ingredients_lieferant' => 'Weißkohl, Salz',
    ]);

    $frisch = $item->fresh();
    expect($frisch->is_preorder)->toBeTrue()
        ->and((int) $frisch->preorder_days)->toBe(89)
        ->and((float) $frisch->vat)->toBe(7.0)
        ->and($frisch->origin_country)->toBe('Deutschland')
        ->and($frisch->ingredients_lieferant)->toBe('Weißkohl, Salz')
        ->and($frisch->zusatztext)->toBe('Fass-Ware');
});
