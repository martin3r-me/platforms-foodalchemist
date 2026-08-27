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
        ->assertSee('Zeitaufschlüsselung')
        ->getContent();

    expect(strpos($html, 'Auftragskosten'))->toBeLessThan(strpos($html, 'Zeitaufschlüsselung'));
});

it('zeigt ohne Pax keine fingierte Auftragssimulation', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Report User'));

    $this->get(route('foodalchemist.concepts.dokument', [
        'id' => $this->concept->id,
        'profil' => 'kalkulation',
    ]))->assertOk()->assertDontSee('Auftragssimulation');
});
