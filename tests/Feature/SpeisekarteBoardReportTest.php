<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Speisekarte-Ausbau (Dominique 2026-08-27): Konzept/Paket-Picker-Split, Board-Sicht (EK/VK/WE je
 * Position + Σ-Rollup je Rubrik inkl. Unter-Rubriken) und der technische Report — Parität zum Foodbook.
 * Der Voll-Kaskade-Knopf ⇒ Leitstelle-Sprung ist in SpeisekarteLeitstelleTest gepinnt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->karten = app(SpeisekarteService::class);
});

it('conceptKandidaten trennt Konzept und Paket nach kind (Picker-Reiter)', function () {
    $this->makeConcept($this->rootTeam, 'Sommer-Menü', ['kind' => 'concept', 'status' => 'active']);
    app(ConceptService::class)->createPaket($this->rootTeam, ['name' => 'Grill-Paket']); // Default active

    $konzepte = $this->karten->conceptKandidaten($this->rootTeam, '', 50, 'concept')->pluck('name')->all();
    $pakete = $this->karten->conceptKandidaten($this->rootTeam, '', 50, 'paket')->pluck('name')->all();

    expect($konzepte)->toContain('Sommer-Menü')->not->toContain('Grill-Paket')
        ->and($pakete)->toContain('Grill-Paket')->not->toContain('Sommer-Menü');
});

it('boardDaten: VK/EK/WE je Gericht-Position + Σ-Rollup je Rubrik (inkl. Unter-Rubrik)', function () {
    $g1 = $this->makeRecipe($this->rootTeam, 'Filet', ['is_sales_recipe' => true, 'sales_net' => 24.0, 'ek_total_eur' => 8.0]);
    $g2 = $this->makeRecipe($this->rootTeam, 'Suppe', ['is_sales_recipe' => true, 'sales_net' => 10.0, 'ek_total_eur' => 2.0]);

    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $ober = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $unter = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Fleisch'], $ober->id);
    $p1 = $this->karten->addPosition($this->rootTeam, $ober->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g1->id]);
    $this->karten->addPosition($this->rootTeam, $unter->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g2->id]);

    $board = $this->karten->boardDaten($this->rootTeam, $karte->fresh());

    // Position: VK 24, EK 8, WE 33,3 %
    expect((float) $board['positionen'][$p1->id]['vk'])->toBe(24.0)
        ->and((float) $board['positionen'][$p1->id]['ek'])->toBe(8.0)
        ->and((float) $board['positionen'][$p1->id]['we'])->toBe(33.3);

    // Ober-Rubrik-Rollup zieht die Unter-Rubrik mit: VK 34, EK 10, n 2, WE = 10/34 ≈ 29,4 %.
    $agg = $board['rubriken'][$ober->id];
    expect((float) $agg['vk'])->toBe(34.0)
        ->and((float) $agg['ek'])->toBe(10.0)
        ->and($agg['n'])->toBe(2)
        ->and((float) $agg['we'])->toBe(29.4);
});

it('speisekarteDaten: technischer Report — typ speisekarte, Rubriken × Positionen, Preise-Default an', function () {
    $g = $this->makeRecipe($this->rootTeam, 'Filet', ['is_sales_recipe' => true, 'sales_net' => 24.0, 'ek_total_eur' => 8.0]);
    $karte = $this->karten->create($this->rootTeam, ['name' => 'Sommerkarte']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g->id]);

    $svc = app(ReportExportService::class);
    $optionen = $svc->optionen(['profil' => 'produktion'], 'speisekarte');
    $data = $svc->speisekarteDaten($this->rootTeam, $karte->id, $optionen);

    expect($data['typ'])->toBe('speisekarte')
        ->and($data['name'])->toBe('Sommerkarte')
        ->and($data['foodbook'])->toBeNull()
        ->and($data['speisekarte']['rubriken'])->toHaveCount(1)
        ->and($data['speisekarte']['rubriken'][0]['title'])->toBe('Hauptgänge')
        ->and(collect($data['speisekarte']['rubriken'][0]['positionen'])->pluck('kind')->all())->toContain('recipe')
        // produktion-Profil schaltet auf Speisekarte die Preise an (Parität zu Concept/Format/Foodbook).
        ->and($optionen['preise'])->toBeTrue();
});

it('Planung-Leitstelle nimmt den sk_owner-Handoff an (skOwnerId + Panel auf)', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'Handoff-Karte']);

    Livewire::withQueryParams(['sk_owner' => $karte->id])
        ->test(PlanungIndex::class)
        ->assertSet('skOwnerId', $karte->id)
        ->assertSet('skPanelAuf', true);
});
