<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Suppliers\Index;
use Platform\FoodAlchemist\Livewire\Suppliers\Marken;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lieferanten → Marken (Dominique 27.10.): der Weg zur Marken-Seite auch mit ausgewähltem Lieferanten — Eintrag „Marken“ im „…“-Menü, gefiltert auf
 * diesen Lieferanten (?lieferant=X). Lesen für alle Teams wie die Seite selbst.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.bearbeitungssperre' => false, 'foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();
    $this->mandant = $this->makeUser($this->childA, 'Mandant Inhaber');               // anderes Team, kein Admin
    $this->cc = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Chefs Culinar West Weeze', 'rolle' => 'haendler']);
    $this->hh = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Handelshof Mönchengladbach', 'rolle' => 'haendler']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->hh->id, 'kuerzel' => 'heng', 'marke' => 'Henglein', 'typ' => 'herstellermarke', 'position' => 'ende']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->cc->id, 'kuerzel' => 'bc', 'marke' => 'Base Culinar', 'typ' => 'eigenmarke']);
    app(MarkeAusArtikel::class)->vergessen();
    $this->actingAs($this->mandant);
});

it('Lieferanten-Index: ohne Auswahl der Knopf „Marken“, mit Auswahl der Eintrag „Marken“ im „…“-Menü, gefiltert auf den Lieferanten — auch ohne Bearbeiten-Recht', function () {
    Livewire::test(Index::class)->assertSeeHtml('data-link-marken')->assertDontSeeHtml('data-link-marken-lieferant');

    $url = route('foodalchemist.suppliers.marken', ['lieferant' => $this->hh->id]);
    $html = Livewire::withQueryParams(['lieferant' => $this->hh->id])->test(Index::class)->html();

    expect($html)->toContain('data-link-marken-lieferant')
        ->and($html)->toContain('href="' . e($url) . '"');
});

it('Marken-Seite mit ?lieferant=X zeigt nur die Kürzel dieses Lieferanten', function () {
    Livewire::withQueryParams(['lieferant' => $this->hh->id])->test(Marken::class)
        ->assertSet('lieferant', $this->hh->id)
        ->assertSee('Henglein')
        ->assertDontSee('Base Culinar');

    Livewire::test(Marken::class)->assertSee('Henglein')->assertSee('Base Culinar');   // ohne Filter beide
});
