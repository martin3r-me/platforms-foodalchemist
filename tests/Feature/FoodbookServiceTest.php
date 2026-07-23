<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Praesentation;
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
        'is_sales_recipe' => true, 'sales_net' => 2.50, 'ek_total_eur' => 0.75,
    ]);
    // Concept „Grill-Buffet" mit einem Paket (manuell 4,50 €/P) → preis_pro_person_cache
    $paket = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise', 'price_mode' => 'manuell']);
    $this->pakete->update($this->rootTeam, $paket->id, ['price_per_person' => 4.50, 'ek_per_person' => 1.35]);
    $this->paket = $paket;
    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['package_id' => $paket->id]);
});

it('M11-02: Kapitel-Aggregat (concept_ref + Paket-Preisblock) + Pax-Gesamtpreis — KEINE Einzelgerichte', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'Angebot Adler', 'customer' => 'Hotel Adler', 'personen' => 100]);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Menü']);
    // Foodbook komponiert Concepts (4,50 €/P) + strukturierte Preis-Blöcke — kein Gericht-Picker
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'header_frei_preis', 'price_basis' => 'person', 'price_value' => 2.50, 'label' => 'Aperitif-Paket']);

    $agg = $this->foodbooks->kapitelAggregat($this->rootTeam, $kap->refresh());
    expect($agg['vk_pro_person'])->toBe(7.00)                         // 4,50 Concept + 2,50 Paket-Preis
        ->and($agg['ek_per_person'])->toBe(1.35);                     // nur Concept-EK (Preisblock ohne EK)

    $gesamt = $this->foodbooks->gesamt($this->rootTeam, $fb->refresh());
    expect($gesamt['vk_pro_person'])->toBe(7.00)
        ->and($gesamt['personen'])->toBe(100)
        ->and($gesamt['gesamt_vk'])->toBe(700.00);                    // 7,00 × 100 Pax

    // Spec 19 E1.1: recipe_ref ist wieder ein angebotener Block-Typ (Einzel-Gericht direkt am Kapitel)
    expect(\Platform\FoodAlchemist\Services\FoodbookService::BLOCK_TYPES)->toContain('recipe_ref');
});

it('Spec 19 E1.1: recipe_ref-Schreibpfad validiert das VK-Gericht (verkauf-Scope, keine Slot-Variante)', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Einzel']);

    // gültiges VK-Gericht → Block wird als recipe_ref persistiert
    $block = $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);
    expect($block->type)->toBe('recipe_ref')
        ->and($block->sales_recipe_id)->toBe($this->gericht->id);

    // ohne sales_recipe_id → wirft
    expect(fn () => $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref']))
        ->toThrow(\RuntimeException::class);

    // Basis-Rezept (kein VK-Gericht) → wirft
    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'b1', 'name' => 'Basis: Fond', 'status' => 'approved',
        'is_sales_recipe' => false,
    ]);
    expect(fn () => $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $basis->id]))
        ->toThrow(\RuntimeException::class);

    // konzept-lokale Slot-Variante → wirft
    $variante = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'v1', 'name' => 'Gruß: Amuse (Variante)', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 2.50, 'variant_source_recipe_id' => $this->gericht->id,
    ]);
    expect(fn () => $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $variante->id]))
        ->toThrow(\RuntimeException::class);
});

it('M11-02: Kapitel-Baum + Move mit Zyklus-Schutz', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB']);
    $top = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Top']);
    $sub = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Sub'], $top->id);

    $tree = $this->foodbooks->kapitelTree($this->rootTeam, $fb->id);
    expect(collect($tree)->firstWhere('title', 'Sub')['depth'])->toBe(1);

    // Zyklus: Top unter den eigenen Nachfahren Sub → wirft
    expect(fn () => $this->foodbooks->moveKapitel($this->rootTeam, $top->id, $sub->id))
        ->toThrow(\RuntimeException::class);

    // gültig: Sub auf Wurzel
    $this->foodbooks->moveKapitel($this->rootTeam, $sub->id, null);
    expect(FoodAlchemistFoodbookKapitel::find($sub->id)->parent_id)->toBeNull();
});

it('M11-02: Owner-Guard — Kind-Team kann geerbtes Foodbook nicht pflegen (D1)', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'Root-FB']);
    expect(fn () => $this->foodbooks->update($this->childA, $fb->id, ['label' => 'Hack']))
        ->toThrow(\RuntimeException::class)
        ->and(fn () => $this->foodbooks->addKapitel($this->childA, $fb->id, ['title' => 'X']))
        ->toThrow(\RuntimeException::class);
});

it('M11 Jarvis: header_frei_preis person/pauschal/staffel im Gesamtpreis (Pax-aufgelöst)', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'Buffet-Angebot', 'personen' => 100]);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Pakete']);

    // Person: 38 €/P
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'header_frei_preis', 'price_basis' => 'person', 'price_value' => 38, 'label' => 'Menü-Paket']);
    // Pauschal: 200 € flach
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'header_frei_preis', 'price_basis' => 'pauschal', 'price_value' => 200, 'label' => 'Servicepauschale']);
    // Staffel: ab 50 = 36, ab 100 = 32 → bei 100 Pax = 32
    $staffelBlock = $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'header_frei_preis', 'price_basis' => 'staffel', 'label' => 'Staffel']);
    $this->foodbooks->setStaffel($this->rootTeam, $staffelBlock->id, [
        ['min_persons' => 50, 'price' => 36], ['min_persons' => 100, 'price' => 32],
    ]);

    $agg = $this->foodbooks->kapitelAggregat($this->rootTeam, $kap->refresh(), 100);
    expect($agg['vk_pro_person'])->toBe(70.00)                       // 38 (person) + 32 (staffel@100)
        ->and($agg['pauschal'])->toBe(200.00);

    $gesamt = $this->foodbooks->gesamt($this->rootTeam, $fb->refresh());
    expect($gesamt['vk_pro_person'])->toBe(70.00)
        ->and($gesamt['gesamt_vk'])->toBe(7200.00);                  // 70 × 100 + 200 pauschal
});

it('M11 Jarvis: Wahl-Gruppe (A|B|C) zwischen Concepts setzen', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Hauptgang']);
    $a = $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);
    $b = $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    $gid = $this->foodbooks->nextVariantGroupId($this->rootTeam, $kap->id);
    $this->foodbooks->setVariantGroup($this->rootTeam, [$a->id, $b->id], $gid);

    expect($a->refresh()->variant_group_id)->toBe($gid)
        ->and($b->refresh()->variant_group_id)->toBe($gid);
});

// ── Golden-Tests M11 (Abnahme-Gates der Foodbook-Roadmap) ────────────────────

it('GT-FB-2: Live-Referenz — Concept-Preisänderung zieht im Foodbook live mit (keine Kopie)', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB', 'personen' => 10]);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Menü']);
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    expect($this->foodbooks->gesamt($this->rootTeam, $fb->refresh())['vk_pro_person'])->toBe(4.50);

    // Paket-Preis am Concept ändern → Foodbook-Summe zieht live mit (Live-Referenz, kein Snapshot).
    $this->pakete->update($this->rootTeam, $this->paket->id, ['price_per_person' => 6.00]);
    expect($this->foodbooks->gesamt($this->rootTeam, $fb->refresh())['vk_pro_person'])->toBe(6.00);
});

it('GT-FB-3: n:m — gleiches Concept in zwei Foodbooks, Änderung wirkt in beiden', function () {
    $fb1 = $this->foodbooks->create($this->rootTeam, ['label' => 'FB1', 'personen' => 10]);
    $fb2 = $this->foodbooks->create($this->rootTeam, ['label' => 'FB2', 'personen' => 10]);
    $k1 = $this->foodbooks->addKapitel($this->rootTeam, $fb1->id, ['title' => 'K']);
    $k2 = $this->foodbooks->addKapitel($this->rootTeam, $fb2->id, ['title' => 'K']);
    $this->foodbooks->addBlock($this->rootTeam, $k1->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);
    $this->foodbooks->addBlock($this->rootTeam, $k2->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    expect($this->foodbooks->gesamt($this->rootTeam, $fb1->refresh())['vk_pro_person'])->toBe(4.50)
        ->and($this->foodbooks->gesamt($this->rootTeam, $fb2->refresh())['vk_pro_person'])->toBe(4.50);

    $this->pakete->update($this->rootTeam, $this->paket->id, ['price_per_person' => 5.00]);
    expect($this->foodbooks->gesamt($this->rootTeam, $fb1->refresh())['vk_pro_person'])->toBe(5.00)
        ->and($this->foodbooks->gesamt($this->rootTeam, $fb2->refresh())['vk_pro_person'])->toBe(5.00); // keine Kopie
});

it('GT-FB-4: Lösch-Guard — referenziertes Concept löschen wirft typisierte Exception (V-06)', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'K']);
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    expect(fn () => $this->concepts->delete($this->rootTeam, $this->concept->id))
        ->toThrow(\RuntimeException::class, 'Foodbook');

    // Nach Entfernen des Blocks ist Löschen wieder erlaubt.
    $block = $kap->refresh()->blocks->first();
    $this->foodbooks->deleteBlock($this->rootTeam, $block->id);
    $this->concepts->delete($this->rootTeam, $this->concept->id);
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistConcept::find($this->concept->id))->toBeNull();
});

it('GT-FB-7: Concept-Picker filtert nach Concept-Kategorie (FB-1)', function () {
    $cat = $this->concepts->createCategory($this->rootTeam, 'Buffets');
    $this->concept->update(['category_id' => $cat->id]);                              // Grill-Buffet → „Buffets"
    $this->concepts->create($this->rootTeam, ['name' => 'Fingerfood-Konzept']);       // ohne Kategorie

    $inKat = $this->foodbooks->conceptKandidaten($this->rootTeam, '', $cat->id);
    expect($inKat->pluck('name')->all())
        ->toContain('Grill-Buffet')->not->toContain('Fingerfood-Konzept');

    $alle = $this->foodbooks->conceptKandidaten($this->rootTeam, '', null);
    expect($alle->pluck('name')->all())
        ->toContain('Grill-Buffet')->toContain('Fingerfood-Konzept');
});

it('M11-09: Block-Notiz (interne_bemerkung) persistiert via updateBlock — auch auf concept_ref', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'K']);
    $block = $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    $this->foodbooks->updateBlock($this->rootTeam, $block->id, ['interne_bemerkung' => 'Allergiker-Hinweis intern']);
    expect($block->refresh()->interne_bemerkung)->toBe('Allergiker-Hinweis intern');
});

it('M11-08: kiAndockKontext assembliert Kunde + Briefing + Concept-Liste (kein LLM-Call)', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB', 'customer' => 'Hotel Adler', 'description' => 'Sommerliches Gartenfest', 'personen' => 80]);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Menü']);
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    $ctx = $this->foodbooks->kiAndockKontext($this->rootTeam, $fb->id);
    expect($ctx['customer'])->toBe('Hotel Adler')
        ->and($ctx['briefing'])->toBe('Sommerliches Gartenfest')
        ->and($ctx['personen'])->toBe(80)
        ->and($ctx['concepts'])->toContain('Grill-Buffet')
        ->and($ctx['kapitel'])->toContain('Menü');
});

it('R3.1 intern: dokumentDaten(intern) liefert EK/W% + Anker pro Kapitel; Kundensicht ohne EK', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'Angebot Adler', 'personen' => 100]);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Menü']);
    $kap->update(['consumer_title' => 'Unser Menü']); // Kunden-Titel separat (nicht über addKapitel)
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    // Intern: Marge (Concept 4,50 €/P VK, 1,35 €/P EK → W 30 %) + Sprungziel-Anker + interner Titel
    $intern = $this->foodbooks->dokumentDaten($this->rootTeam, $fb->refresh(), true);
    $row = $intern['kapitel'][0];
    expect($intern['intern'])->toBeTrue()
        ->and($row['vk_pro_person'])->toBe(4.50)
        ->and($row['ek_pro_person'])->toBe(1.35)
        ->and($row['food_cost_percent'])->toBe(30.0)
        ->and($row['anker'])->toBe('k' . $kap->id)
        ->and($row['title_intern'])->toBe('Menü');

    // Kundensicht: KEIN EK/W% in den Daten, Konsumententitel
    $kunde = $this->foodbooks->dokumentDaten($this->rootTeam, $fb->refresh(), false);
    expect($kunde['intern'])->toBeFalse()
        ->and($kunde['kapitel'][0])->not->toHaveKey('ek_pro_person')
        ->and($kunde['kapitel'][0]['title'])->toBe('Unser Menü');
});

it('R3.1 intern-Dokument-Blade zeigt Marge + Navleiste; Kundensicht-Blade nicht', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'Angebot Adler', 'personen' => 100]);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Menü']);
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    $internHtml = view('foodalchemist::dokumente.foodbook',
        $this->foodbooks->dokumentDaten($this->rootTeam, $fb->refresh(), true) + ['istPdf' => false])->render();
    expect($internHtml)->toContain('INTERN')->toContain('Wareneinsatz')->toContain('Inhaltsverzeichnis')
        ->toContain('id="k' . $kap->id . '"');

    $kundeHtml = view('foodalchemist::dokumente.foodbook',
        $this->foodbooks->dokumentDaten($this->rootTeam, $fb->refresh(), false) + ['istPdf' => false])->render();
    expect($kundeHtml)->not->toContain('Wareneinsatz pro Person')->not->toContain('INTERN');
});

it('R3.2 Präsentation (Block C): rendert Kundensicht + Preis pro Person, KEINE Marge/Interna', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'Sommerfest Adler', 'personen' => 100]);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Menü']);
    $this->foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);

    Livewire::test(Praesentation::class, ['id' => $fb->id])
        ->assertSee('Sommerfest Adler')
        ->assertSee('pro Person')
        ->assertSee('Kulinarisches Angebot')
        ->assertDontSee('Wareneinsatz')   // EK-Leak-Guard: interne Marge darf NIE erscheinen
        ->assertDontSee('INTERN');
});
