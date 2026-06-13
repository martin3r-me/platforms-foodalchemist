<?php

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M11-02: FoodbookService — Kapitel-Baum (Move/Zyklus), Blöcke, rekursives
 * Aggregat (concept_ref + recipe_ref), Pax-Gesamtpreis (F-12).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->pakete = app(PaketService::class);
    $this->concepts = app(ConceptService::class);
    $this->foodbooks = app(FoodbookService::class);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g1', 'name' => 'Gruß: Amuse', 'status' => 'approved',
        'ist_verkaufsrezept' => true, 'vk_netto' => 2.50, 'ek_total_eur' => 0.75,
    ]);
    // Concept „Grill-Buffet" mit einem Paket (manuell 4,50 €/P) → preis_pro_person_cache
    $paket = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->pakete->update($this->rootTeam, $paket->id, ['preis_pro_person' => 4.50, 'ek_pro_person' => 1.35]);
    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $paket->id]);
});

it('M11-02: Kapitel-Aggregat (concept_ref + Paket-Preisblock) + Pax-Gesamtpreis — KEINE Einzelgerichte', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['bezeichnung' => 'Angebot Adler', 'kunde' => 'Hotel Adler', 'personen' => 100]);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['titel' => 'Menü']);
    // Foodbook komponiert Concepts (4,50 €/P) + strukturierte Preis-Blöcke — kein Gericht-Picker
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'header_frei_preis', 'preis_basis' => 'person', 'preis_wert' => 2.50, 'bezeichnung' => 'Aperitif-Paket']);

    $agg = $this->foodbooks->kapitelAggregat($this->rootTeam, $kap->refresh());
    expect($agg['vk_pro_person'])->toBe(7.00)                         // 4,50 Concept + 2,50 Paket-Preis
        ->and($agg['ek_pro_person'])->toBe(1.35);                     // nur Concept-EK (Preisblock ohne EK)

    $gesamt = $this->foodbooks->gesamt($this->rootTeam, $fb->refresh());
    expect($gesamt['vk_pro_person'])->toBe(7.00)
        ->and($gesamt['personen'])->toBe(100)
        ->and($gesamt['gesamt_vk'])->toBe(700.00);                    // 7,00 × 100 Pax

    // recipe_ref ist KEIN angebotener Block-Typ mehr (wird zu 'text' degradiert)
    expect(\Platform\FoodAlchemist\Services\FoodbookService::BLOCK_TYPES)->not->toContain('recipe_ref');
});

it('M11-02: Kapitel-Baum + Move mit Zyklus-Schutz', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['bezeichnung' => 'FB']);
    $top = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['titel' => 'Top']);
    $sub = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['titel' => 'Sub'], $top->id);

    $tree = $this->foodbooks->kapitelTree($this->rootTeam, $fb->id);
    expect(collect($tree)->firstWhere('titel', 'Sub')['depth'])->toBe(1);

    // Zyklus: Top unter den eigenen Nachfahren Sub → wirft
    expect(fn () => $this->foodbooks->moveKapitel($this->rootTeam, $top->id, $sub->id))
        ->toThrow(\RuntimeException::class);

    // gültig: Sub auf Wurzel
    $this->foodbooks->moveKapitel($this->rootTeam, $sub->id, null);
    expect(FoodAlchemistFoodbookKapitel::find($sub->id)->parent_id)->toBeNull();
});

it('M11-02: Owner-Guard — Kind-Team kann geerbtes Foodbook nicht pflegen (D1)', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['bezeichnung' => 'Root-FB']);
    expect(fn () => $this->foodbooks->update($this->childA, $fb->id, ['bezeichnung' => 'Hack']))
        ->toThrow(\RuntimeException::class)
        ->and(fn () => $this->foodbooks->addKapitel($this->childA, $fb->id, ['titel' => 'X']))
        ->toThrow(\RuntimeException::class);
});

it('M11 Jarvis: header_frei_preis person/pauschal/staffel im Gesamtpreis (Pax-aufgelöst)', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['bezeichnung' => 'Buffet-Angebot', 'personen' => 100]);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['titel' => 'Pakete']);

    // Person: 38 €/P
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'header_frei_preis', 'preis_basis' => 'person', 'preis_wert' => 38, 'bezeichnung' => 'Menü-Paket']);
    // Pauschal: 200 € flach
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'header_frei_preis', 'preis_basis' => 'pauschal', 'preis_wert' => 200, 'bezeichnung' => 'Servicepauschale']);
    // Staffel: ab 50 = 36, ab 100 = 32 → bei 100 Pax = 32
    $staffelBlock = $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'header_frei_preis', 'preis_basis' => 'staffel', 'bezeichnung' => 'Staffel']);
    $this->foodbooks->setStaffel($this->rootTeam, $staffelBlock->id, [
        ['min_personen' => 50, 'preis' => 36], ['min_personen' => 100, 'preis' => 32],
    ]);

    $agg = $this->foodbooks->kapitelAggregat($this->rootTeam, $kap->refresh(), 100);
    expect($agg['vk_pro_person'])->toBe(70.00)                       // 38 (person) + 32 (staffel@100)
        ->and($agg['pauschal'])->toBe(200.00);

    $gesamt = $this->foodbooks->gesamt($this->rootTeam, $fb->refresh());
    expect($gesamt['vk_pro_person'])->toBe(70.00)
        ->and($gesamt['gesamt_vk'])->toBe(7200.00);                  // 70 × 100 + 200 pauschal
});

it('M11 Jarvis: Wahl-Gruppe (A|B|C) zwischen Concepts setzen', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['bezeichnung' => 'FB']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['titel' => 'Hauptgang']);
    $a = $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);
    $b = $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    $gid = $this->foodbooks->nextVariantGroupId($this->rootTeam, $kap->id);
    $this->foodbooks->setVariantGroup($this->rootTeam, [$a->id, $b->id], $gid);

    expect($a->refresh()->variant_group_id)->toBe($gid)
        ->and($b->refresh()->variant_group_id)->toBe($gid);
});
