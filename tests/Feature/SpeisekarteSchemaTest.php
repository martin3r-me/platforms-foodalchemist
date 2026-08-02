<?php

use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Speisekarte (Stufe A): Schema-Absicherung der drei Tabellen + Kern-Spalten. */
beforeEach(function () {
    $this->seedTeamHierarchy(); // triggert die Migration der :memory:-Test-DB
});

it('Stufe A: Speisekarte-Tabellen + Kern-Spalten existieren', function () {
    expect(Schema::hasTable('foodalchemist_menu_cards'))->toBeTrue()
        ->and(Schema::hasTable('foodalchemist_menu_card_sections'))->toBeTrue()
        ->and(Schema::hasTable('foodalchemist_menu_card_items'))->toBeTrue();

    expect(Schema::hasColumns('foodalchemist_menu_cards', [
        'uuid', 'team_id', 'name', 'status', 'outlet_id', 'karten_typ', 'gueltig_von', 'gueltig_bis',
        'preis_anzeige_brutto', 'brand_color', 'logo_path', 'footer_text', 'default_niveau', 'phase', 'writing_style_id',
    ]))->toBeTrue();

    expect(Schema::hasColumns('foodalchemist_menu_card_sections', [
        'uuid', 'team_id', 'menu_card_id', 'parent_id', 'position', 'title', 'consumer_title', 'art', 'preis_anzeige',
    ]))->toBeTrue();

    expect(Schema::hasColumns('foodalchemist_menu_card_items', [
        'uuid', 'team_id', 'section_id', 'position', 'type', 'sales_recipe_id', 'concept_id',
        'presentation_id', 'wording', 'price_mode', 'price_value', 'payload_json',
    ]))->toBeTrue();
});
