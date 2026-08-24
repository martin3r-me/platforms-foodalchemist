<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Browser;
use Platform\FoodAlchemist\Livewire\Concepter\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kaskade (2026-08-24): Paket = kind=paket-Concept (eigener Preis, einbettbar, gleiche Dimensionen
 * wie Concept). Deckt den NEUEN Modellpfad ab (ersetzt die Alt-packages-Tests aus #5): Browser-Reiter
 * mit Facetten-Filtern, Detail-Panel paket-aware (Paketpreis, Einzel-VK aus), Embed-Verwendung.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->concepts = app(ConceptService::class);

    $this->green = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g', 'name' => 'Salat: Green Power',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 2.00, 'ek_total_eur' => 0.60,
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'allergens_confidence' => 'high',
    ]);

    // Paket = kind=paket-Concept mit manuellem Gesamtpreis + einer Gericht-Position.
    $this->paket = $this->concepts->createPaket($this->rootTeam, ['name' => 'Salad Wall', 'class' => 'Buffet']);
    $this->concepts->update($this->rootTeam, $this->paket->id, ['price_mode' => 'manuell', 'price_per_person_manual' => 4.50]);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->paket->id, ['role' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->green->id]);
});

it('createPaket legt ein kind=paket-Concept an', function () {
    expect($this->paket->kind)->toBe('paket');
    expect(FoodAlchemistConcept::pakete()->whereKey($this->paket->id)->exists())->toBeTrue();
    // taucht NICHT im Concepts-Reiter auf (konzepte-Scope)
    expect(FoodAlchemistConcept::konzepte()->whereKey($this->paket->id)->exists())->toBeFalse();
});

it('Pakete-Reiter listet das Paket und trägt die geteilten Facetten-Filter', function () {
    Livewire::test(Browser::class)
        ->call('wechselTab', 'pakete')
        ->assertSet('tab', 'pakete')
        ->assertSee('Salad Wall')
        ->assertSee('Eventtyp · Servierform')   // Facetten-Spalte (§3, geteilte Dimension)
        ->assertSee('Servierform');             // Facetten-Filter-Kopf jetzt auch im Pakete-Reiter
});

it('Facetten-Filter greift auch im Pakete-Reiter (paginatePakete)', function () {
    $sf = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create([
        'team_id' => $this->rootTeam->id, 'code' => 'buffet', 'label' => 'Buffet', 'is_inactive' => false, 'sort_order' => 1,
    ]);
    $this->concepts->update($this->rootTeam, $this->paket->id, ['serving_form_id' => $sf->id]);

    // gesetzter Filter → Treffer; ein nicht-existenter Filter → 0
    expect($this->concepts->paginatePakete(['servierform' => (string) $sf->id], $this->rootTeam)->total())->toBe(1);
    expect($this->concepts->paginatePakete(['servierform' => '999999'], $this->rootTeam)->total())->toBe(0);
});

it('DetailPanel rendert das Paket paket-aware (Paketpreis, Positionen, kein Einzel-VK-Score)', function () {
    Livewire::test(DetailPanel::class)
        ->call('zeige', 'pakete', $this->paket->id)
        ->assertOk()
        ->assertSee('Salad Wall')
        ->assertSee('Paketpreis / Person')   // §4: paket-aware Label statt „€/Person"
        ->assertSee('4,50')                  // manueller Paketpreis
        ->assertSee('Positionen im Paket')   // Aufbau-Titel im Paket-Modus
        ->assertDontSee('Menü-Bewertung');   // Bewertung nur für echte Concepts
});

it('DetailPanel Duplizieren erhält kind=paket', function () {
    Livewire::test(DetailPanel::class)
        ->call('zeige', 'pakete', $this->paket->id)
        ->call('dupliziere');

    $kopie = FoodAlchemistConcept::where('name', 'Salad Wall (Kopie)')->first();
    expect($kopie)->not->toBeNull();
    expect($kopie->kind)->toBe('paket');                       // Kaskade: Duplikat bleibt Paket
    expect((float) $kopie->price_per_person_manual)->toBe(4.50); // Preis-Metadaten mitkopiert
});

it('Embed: Paket in ein Concept → eingebettetInConcepts + Wo-verwendet', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Sommer-Menü']);
    $slot = $this->concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['embedded_concept_id' => $this->paket->id]);

    $emb = $this->concepts->eingebettetInConcepts($this->rootTeam, $this->paket->id);
    expect($emb->pluck('name')->all())->toContain('Sommer-Menü');

    Livewire::test(DetailPanel::class)
        ->call('zeige', 'pakete', $this->paket->id)
        ->assertSee('Sommer-Menü');   // Wo verwendet? → Concept
});

// ── Status berücksichtigt (2026-08-24): Picker zeigt keine Entwürfe; Browser bekommt Status-Filter ──

it('Picker (paketKandidaten) zeigt nur aktive Pakete — kein Entwurf', function () {
    $draft = $this->concepts->createPaket($this->rootTeam, ['name' => 'Entwurf-Paket']);
    $this->concepts->update($this->rootTeam, $draft->id, ['status' => 'draft']);

    $namen = $this->concepts->paketKandidaten($this->rootTeam)->pluck('name')->all();
    expect($namen)->toContain('Salad Wall')            // aktiv (beforeEach)
        ->and($namen)->not->toContain('Entwurf-Paket'); // draft ausgeblendet
});

it('Picker (gerichtKandidaten) zeigt keine Entwurf-Gerichte, aber review/approved', function () {
    FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'dd', 'name' => 'Entwurf-Gericht',
        'status' => 'draft', 'is_sales_recipe' => true, 'sales_net' => 3.00,
    ]);
    FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rd', 'name' => 'Review-Gericht',
        'status' => 'review', 'is_sales_recipe' => true, 'sales_net' => 3.00,
    ]);
    $namen = app(\Platform\FoodAlchemist\Services\PaketService::class)
        ->gerichtKandidaten($this->rootTeam, '')->pluck('name')->all();
    expect($namen)->toContain('Review-Gericht')
        ->and($namen)->not->toContain('Entwurf-Gericht');
});

it('Browser-Status-Filter grenzt die Pakete-Liste auf einen Status ein', function () {
    $draft = $this->concepts->createPaket($this->rootTeam, ['name' => 'Entwurf-Paket']);
    $this->concepts->update($this->rootTeam, $draft->id, ['status' => 'draft']);

    Livewire::test(Browser::class)
        ->call('wechselTab', 'pakete')
        ->call('waehleStatus', 'draft')
        ->assertSet('statusFilter', 'draft')
        ->assertSee('Entwurf-Paket')
        ->assertDontSee('Salad Wall');   // aktiv → ausgefiltert
});
