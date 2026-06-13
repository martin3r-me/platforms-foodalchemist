<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\DetailPanel;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10R-Politur: Verwendungsnachweis (Wo verwendet?), Park-Flow Menge-bei-Einfügen,
 * Sektor-Eignung-Pflege am Concept.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->pakete = app(PaketService::class);
    $this->concepts = app(ConceptService::class);
    $this->foodbooks = app(FoodbookService::class);

    $this->green = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'Green Power',
        'status' => 'approved', 'ist_verkaufsrezept' => true, 'vk_netto' => 2.00, 'ek_total_eur' => 0.60,
    ]);
    $this->paket = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);
    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $this->paket->id]);
});

it('Wo verwendet?: Paket → Concepts, die es referenzieren', function () {
    $treffer = $this->pakete->verwendetInConcepts($this->rootTeam, $this->paket->id);

    expect($treffer)->toHaveCount(1)
        ->and($treffer->first()->id)->toBe($this->concept->id);
});

it('Wo verwendet?: Concept → Foodbooks, die es referenzieren', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['bezeichnung' => 'Portfolio 2026']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['name' => 'Buffets']);
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    $treffer = $this->concepts->verwendetInFoodbooks($this->rootTeam, $this->concept->id);

    expect($treffer)->toHaveCount(1)
        ->and($treffer->first()->bezeichnung)->toBe('Portfolio 2026');

    // Anderes Concept ist in keinem Foodbook.
    $leer = $this->concepts->create($this->rootTeam, ['name' => 'Ungenutzt']);
    expect($this->concepts->verwendetInFoodbooks($this->rootTeam, $leer->id))->toHaveCount(0);
});

it('DetailPanel rendert „Wo verwendet?" für ein ausgewähltes Paket', function () {
    Livewire::test(DetailPanel::class)
        ->call('zeige', 'pakete', $this->paket->id)
        ->assertSee('Wo verwendet')
        ->assertSee('Grill-Buffet');                                  // das nutzende Concept
});

it('Park-Flow: gerichtHinzu setzt die Menge/Person beim Einfügen', function () {
    Livewire::test(Editor::class)
        ->call('oeffnen', 'pakete', $this->paket->id)
        ->call('gerichtHinzu', $this->green->id, 1.5);

    $row = $this->paket->gerichte()->first();
    expect((float) $row->menge)->toBe(1.5);
});

it('Sektor-Eignung: zuweisen, reaktivieren, entfernen', function () {
    $this->concepts->setzeSektorEignung($this->rootTeam, $this->concept->id, 'Kita');
    $this->concepts->setzeSektorEignung($this->rootTeam, $this->concept->id, 'Klinik');
    expect($this->concepts->sektorEignungSlugs($this->concept->refresh()))->toMatchArray(['Kita', 'Klinik']);

    // Entfernen (soft-delete) + erneutes Zuweisen reaktiviert dieselbe Zeile (kein Dublett).
    $this->concepts->entferneSektorEignung($this->rootTeam, $this->concept->id, 'Kita');
    expect($this->concepts->sektorEignungSlugs($this->concept->refresh()))->toBe(['Klinik']);

    $this->concepts->setzeSektorEignung($this->rootTeam, $this->concept->id, 'Kita');
    expect($this->concept->sektorEignungen()->count())->toBe(2);          // reaktiviert, nicht doppelt
});

it('Editor: Sektor über die UI hinzufügen/entfernen', function () {
    Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->set('neuerSektor', 'Catering')
        ->call('sektorHinzu')
        ->assertSet('neuerSektor', '');

    expect($this->concepts->sektorEignungSlugs($this->concept->refresh()))->toContain('Catering');
});
