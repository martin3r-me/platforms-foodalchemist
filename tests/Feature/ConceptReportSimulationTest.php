<?php

use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->concepts = app(ConceptService::class);
    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Druckbares Menü']);
    $this->dish = $this->makeRecipe($this->rootTeam, 'Druckbares Gericht', [
        'is_sales_recipe' => true,
        'sales_net' => 12.50,
        'ek_total_eur' => 3.00,
        'sales_unit_count' => 1,
        'setup_time_min' => 10,
        'work_time_min' => 5,
    ]);
    $this->makeIngredient($this->dish, 'Testzutat', null, '25');
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->dish->id]);
    $this->concepts->recomputeCache($this->concept->refresh());
});

it('nimmt Pax nur im Concept-Report als druckbare Auftragssimulation auf', function () {
    $report = app(ReportExportService::class);
    $options = $report->optionen(['profil' => 'kurz', 'simulation' => 1, 'pax' => 100], 'concept');
    $data = $report->conceptDaten($this->rootTeam, $this->concept->id, $options);

    expect($options['pax'])->toBe(100)
        ->and($data['concept']['order_simulation']['pax'])->toBe(100)
        ->and($data['concept']['order_simulation']['cost_breakdown'])->not->toBeEmpty();
});

it('rendert Wasserfall vor Zeitschlüssel im druckbaren Concept-Report', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Report User'));

    $html = $this->get(route('foodalchemist.concepts.dokument', [
        'id' => $this->concept->id,
        'profil' => 'kurz',
        'simulation' => 1,
        'pax' => 100,
    ]))->assertOk()
        ->assertSee('Auftragssimulation · 100 Pax')
        ->assertSee('Simulation')
        ->assertSee('Auftragskosten')
        ->assertSee('HK2 (Vollkosten)')
        ->assertSee('Aktive Produktionszeit je Rezept')
        ->assertSee('Auftragsbedarf')
        ->assertSee('Produktion')
        ->assertSee('2.500 g')
        ->assertSee('für Auftrag')
        ->getContent();

    expect(strpos($html, 'Auftragskosten'))->toBeLessThan(strpos($html, 'Aktive Produktionszeit je Rezept'));
});

it('setzt die Simulation vor Profile und Filter und bewahrt die Pax in deren Links', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Report User'));

    $html = $this->get(route('foodalchemist.concepts.dokument', [
        'id' => $this->concept->id,
        'profil' => 'produktion',
        'simulation' => 1,
        'pax' => 100,
    ]))->assertOk()
        ->assertSee('Auftragssimulation:')
        ->assertSee('100 Pax aktiv')
        ->getContent();

    expect(strpos($html, 'data-report-simulation-control'))->toBeLessThan(strpos($html, 'Report-Profile:'))
        ->and($html)->toContain('simulation=1')
        ->and($html)->toContain('pax=100');
});

it('zeigt ohne Pax keine fingierte Auftragssimulation', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Report User'));

    $this->get(route('foodalchemist.concepts.dokument', [
        'id' => $this->concept->id,
        'profil' => 'kalkulation',
    ]))->assertOk()->assertDontSee('Auftragssimulation ·');
});
