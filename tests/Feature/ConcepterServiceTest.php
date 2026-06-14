<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->pakete = app(PaketService::class);
    $this->concepts = app(ConceptService::class);

    $mk = fn (string $key, string $name, float $vk, float $ek) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
        'status' => 'approved', 'ist_verkaufsrezept' => true, 'vk_netto' => $vk, 'ek_total_eur' => $ek,
    ]);
    $this->green = $mk('g', 'Salat: Green Power', 2.00, 0.60);
    $this->sunny = $mk('s', 'Salat: Sunny Kick', 3.00, 0.90);
    $this->dessert = $mk('d', 'Dessert: Cool Down', 5.50, 1.50);
});

it('PaketService auto-Preis = Σ der Gerichte (vk/ek), W% via MargeService', function () {
    $b = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'auto']);
    $this->pakete->syncGerichte($this->rootTeam, $b->id, [
        ['vk_recipe_id' => $this->green->id], ['vk_recipe_id' => $this->sunny->id],
    ]);

    $b->refresh();
    expect((float) $b->preis_pro_person)->toBe(5.00)              // 2,00 + 3,00
        ->and((float) $b->ek_pro_person)->toBe(1.50)              // 0,60 + 0,90
        ->and((float) $b->wareneinsatz_prozent)->toBe(30.0)       // 1,50 / 5,00
        ->and($b->preis_stale)->toBeFalse()
        ->and($b->preis_berechnet_am)->not->toBeNull();
});

it('PaketService manuell: gesetzter Per-Person-Preis bleibt trotz Gerichten', function () {
    $b = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->pakete->update($this->rootTeam, $b->id, ['preis_pro_person' => 4.50, 'ek_pro_person' => 1.41, 'wareneinsatz_prozent' => 31.3]);
    $this->pakete->syncGerichte($this->rootTeam, $b->id, [['vk_recipe_id' => $this->green->id], ['vk_recipe_id' => $this->sunny->id]]);

    expect((float) $b->refresh()->preis_pro_person)->toBe(4.50);  // NICHT auf 5,00 überschrieben
});

it('K-07: recomputeAndPropagate markiert Auto-Pakete mit dem Gericht als stale', function () {
    $auto = $this->pakete->create($this->rootTeam, ['name' => 'Auto', 'rolle' => 'Vorspeise', 'preis_modus' => 'auto']);
    $this->pakete->syncGerichte($this->rootTeam, $auto->id, [['vk_recipe_id' => $this->green->id]]);
    expect($auto->refresh()->preis_stale)->toBeFalse();             // syncGerichte hat gerade gerechnet

    app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class)->recomputeAndPropagate($this->green->id);
    expect($auto->refresh()->preis_stale)->toBeTrue();              // Preis-Basis neu → Paket veraltet
});

it('markStaleForRecipe markiert nur Auto-Pakete mit dem Gericht', function () {
    $auto = $this->pakete->create($this->rootTeam, ['name' => 'Auto', 'rolle' => 'Vorspeise', 'preis_modus' => 'auto']);
    $manuell = $this->pakete->create($this->rootTeam, ['name' => 'Manuell', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->pakete->syncGerichte($this->rootTeam, $auto->id, [['vk_recipe_id' => $this->green->id]]);
    $this->pakete->syncGerichte($this->rootTeam, $manuell->id, [['vk_recipe_id' => $this->green->id]]);

    $betroffen = $this->pakete->markStaleForRecipe($this->green->id);
    expect($betroffen)->toBe(1)
        ->and($auto->refresh()->preis_stale)->toBeTrue()
        ->and($manuell->refresh()->preis_stale)->toBeFalse();
});

it('B2: fillSlot pflegt type (paket|gericht|basisrezept); basisKandidaten findet nur Basisrezepte', function () {
    $paket = $this->pakete->create($this->rootTeam, ['name' => 'P', 'rolle' => 'Vorspeise']);
    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'b1', 'name' => 'Fond: Hell',
        'status' => 'approved', 'ist_verkaufsrezept' => false, 'ek_total_eur' => 0.40,
    ]);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Menü']);
    $s = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Vorspeise']);

    $this->concepts->fillSlot($this->rootTeam, $s->id, ['paket_id' => $paket->id]);
    expect($s->refresh()->type)->toBe('paket');

    $this->concepts->fillSlot($this->rootTeam, $s->id, ['vk_recipe_id' => $this->green->id]);
    expect($s->refresh()->type)->toBe('gericht');

    $this->concepts->fillSlot($this->rootTeam, $s->id, ['vk_recipe_id' => $basis->id, 'type' => 'basisrezept']);
    expect($s->refresh()->type)->toBe('basisrezept')->and($s->vk_recipe_id)->toBe($basis->id);

    // Basisrezept-Suche findet das Basisrezept, NICHT die VK-Gerichte
    expect($this->pakete->basisKandidaten($this->rootTeam, 'Fond')->pluck('name')->all())->toContain('Fond: Hell');
    expect($this->pakete->basisKandidaten($this->rootTeam, 'Green')->pluck('name')->all())->not->toContain('Salat: Green Power');
});

it('B3: Struktur-Blöcke (text/spacer/header/header_preis) zählen nicht zum Concept-Preis', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Menü']);
    $s = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'HG']);
    $this->concepts->fillSlot($this->rootTeam, $s->id, ['vk_recipe_id' => $this->dessert->id]); // 5,50

    $header = $this->concepts->addBlock($this->rootTeam, $concept->id, 'header_preis', ['titel' => 'Vorspeisen', 'preis_wert' => 12.00]);
    $this->concepts->addBlock($this->rootTeam, $concept->id, 'text', ['text_inhalt' => 'Hinweis']);
    $this->concepts->addBlock($this->rootTeam, $concept->id, 'spacer');

    expect($header->type)->toBe('header_preis')->and((float) $header->preis_wert)->toBe(12.0);

    $cockpit = $this->concepts->preisCockpit($concept->refresh());
    expect($cockpit['preis_pro_person'])->toBe(5.50)   // nur das Gericht — Struktur zählt nicht
        ->and($cockpit['zeilen'])->toHaveCount(1)       // Struktur-Blöcke nicht im Cockpit
        ->and($cockpit['hat_leer'])->toBeFalse();

    expect(fn () => $this->concepts->addBlock($this->rootTeam, $concept->id, 'image'))->toThrow(RuntimeException::class);
});

it('B4: bildePaketAusPositionen — markierte Gerichte → 1 wiederverwendbares Paket, Summe unverändert', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $s1 = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'HG']);
    $s2 = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'HG']);
    $s3 = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Dessert']);
    $this->concepts->fillSlot($this->rootTeam, $s1->id, ['vk_recipe_id' => $this->green->id]);   // 2,00
    $this->concepts->fillSlot($this->rootTeam, $s2->id, ['vk_recipe_id' => $this->sunny->id]);   // 3,00
    $this->concepts->fillSlot($this->rootTeam, $s3->id, ['vk_recipe_id' => $this->dessert->id]); // 5,50
    expect($this->concepts->preisCockpit($concept->refresh())['preis_pro_person'])->toBe(10.50);

    $neu = $this->concepts->bildePaketAusPositionen($this->rootTeam, $concept->id, [$s1->id, $s2->id], 'Grill-HG', 'HG');

    expect($neu->type)->toBe('paket')
        ->and($neu->paket->gerichte()->count())->toBe(2)
        ->and($concept->refresh()->slots()->count())->toBe(2)                                       // 2 Gerichte → 1 Paket, + Dessert
        ->and($this->concepts->preisCockpit($concept->refresh())['preis_pro_person'])->toBe(10.50); // Summe unverändert

    expect(\Platform\FoodAlchemist\Models\FoodAlchemistPaket::where('name', 'Grill-HG')->where('rolle', 'HG')->exists())->toBeTrue();
});

it('ConceptService fillSlot erzwingt GENAU EINES (Paket XOR Gericht)', function () {
    $b = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Vorspeise']);

    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $b->id]);
    expect($slot->refresh()->paket_id)->toBe($b->id)->and($slot->vk_recipe_id)->toBeNull();

    // Auf festes Gericht umstellen → Paket wird geleert
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['vk_recipe_id' => $this->dessert->id, 'menge' => 1]);
    expect($slot->refresh()->vk_recipe_id)->toBe($this->dessert->id)->and($slot->paket_id)->toBeNull();
});

it('M10-04: Concept-Preis = Σ gespeicherte Paket-Preise + feste Gerichte', function () {
    $salad = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->pakete->update($this->rootTeam, $salad->id, ['preis_pro_person' => 4.50, 'ek_pro_person' => 1.41]);

    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $sVor = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Vorspeise']);
    $sDess = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Dessert']);
    $this->concepts->fillSlot($this->rootTeam, $sVor->id, ['paket_id' => $salad->id]);
    $this->concepts->fillSlot($this->rootTeam, $sDess->id, ['vk_recipe_id' => $this->dessert->id]);

    $cockpit = $this->concepts->preisCockpit($concept->refresh());
    expect($cockpit['preis_pro_person'])->toBe(10.00)             // 4,50 + 5,50
        ->and($cockpit['zeilen'])->toHaveCount(2)
        ->and($cockpit['zeilen'][0]['typ'])->toBe('paket')
        ->and($cockpit['zeilen'][1]['typ'])->toBe('gericht')
        ->and((float) $concept->refresh()->preis_pro_person_cache)->toBe(10.00);
});

it('M10-04: Paket-Tausch ändert nur die Differenz (kein Kaskaden-Recompute)', function () {
    $billig = $this->pakete->create($this->rootTeam, ['name' => 'Vorspeise A', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $teuer = $this->pakete->create($this->rootTeam, ['name' => 'Vorspeise B', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->pakete->update($this->rootTeam, $billig->id, ['preis_pro_person' => 4.50]);
    $this->pakete->update($this->rootTeam, $teuer->id, ['preis_pro_person' => 6.00]);

    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Vorspeise']);
    $sDess = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Dessert']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $billig->id]);
    $this->concepts->fillSlot($this->rootTeam, $sDess->id, ['vk_recipe_id' => $this->dessert->id]);
    expect($this->concepts->preisCockpit($concept->refresh())['preis_pro_person'])->toBe(10.00);

    // Tauschbare Pakete = gleiche Rolle
    expect($this->concepts->tauschbarePakete($this->rootTeam, $slot->refresh())->pluck('name')->all())
        ->toBe(['Vorspeise A', 'Vorspeise B']);

    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $teuer->id]);
    expect($this->concepts->preisCockpit($concept->refresh())['preis_pro_person'])->toBe(11.50); // 6,00 + 5,50
});

it('M10-05: Vorlage-Fork kopiert Slots, Paket bleibt Referenz, Concept ist eigenständig', function () {
    $salad = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);
    $vorlage = $this->concepts->create($this->rootTeam, ['name' => '3-Gang', 'is_vorlage' => true]);
    $s1 = $this->concepts->addSlot($this->rootTeam, $vorlage->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $s1->id, ['paket_id' => $salad->id]);
    $this->concepts->addSlot($this->rootTeam, $vorlage->id, ['rolle' => 'Dessert']);

    $fork = $this->concepts->forkVonVorlage($this->rootTeam, $vorlage->id, 'Event Mai');
    expect($fork->is_vorlage)->toBeFalse()
        ->and($fork->vorlage_quelle_id)->toBe($vorlage->id)
        ->and($fork->slots()->count())->toBe(2)
        ->and($fork->slots()->where('rolle', 'Vorspeise')->first()->paket_id)->toBe($salad->id); // Referenz erhalten

    // Fork ist eigenständig: Slot löschen ändert die Vorlage nicht
    $this->concepts->removeSlot($this->rootTeam, $fork->slots()->first()->id);
    expect($fork->slots()->count())->toBe(1)->and($vorlage->refresh()->slots()->count())->toBe(2);
});

it('Owner-Guard: Kind-Team kann geerbten Paket/Concept nicht pflegen (D1)', function () {
    $b = $this->pakete->create($this->rootTeam, ['name' => 'Root-Paket', 'rolle' => 'Vorspeise']);
    expect(fn () => $this->pakete->update($this->childA, $b->id, ['name' => 'Hack']))
        ->toThrow(\RuntimeException::class);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Root-Concept']);
    expect(fn () => $this->concepts->update($this->childA, $c->id, ['name' => 'Hack']))
        ->toThrow(\RuntimeException::class);
});

it('M10c-A: Concept ist person-unabhängig — Pax kommt erst beim Aufruf der Hochrechnung', function () {
    $b = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->pakete->update($this->rootTeam, $b->id, ['preis_pro_person' => 4.50, 'ek_pro_person' => 1.41]);
    $this->pakete->syncGerichte($this->rootTeam, $b->id, [['vk_recipe_id' => $this->green->id, 'menge' => 120]]); // 120 g/Person

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $b->id]);

    // Cockpit kennt KEINE Pax mehr (person-unabhängig) — nur €/Person
    $cockpit = $this->concepts->preisCockpit($c->refresh());
    expect($cockpit['preis_pro_person'])->toBe(4.50)
        ->and($cockpit)->not->toHaveKey('personen')
        ->and($cockpit)->not->toHaveKey('gesamt_preis');

    // Pax kommt vom Aufruf (Foodbook/Angebot, M11)
    $hr = $this->concepts->mengenHochrechnung($c->refresh(), 80);
    expect($hr)->toHaveCount(1)
        ->and($hr[0]['menge_pro_person'])->toBe(120.0)
        ->and($hr[0]['gesamt_menge'])->toBe(9600.0);                  // 120 g × 80
    // ohne Pax: keine Gesamtmenge
    expect($this->concepts->mengenHochrechnung($c->refresh())[0]['gesamt_menge'])->toBeNull();
});

it('M10c-B: Kategorie-Baum — flat mit Tiefe, Nachfahren-Filter, Löschen rückt zum Eltern', function () {
    $sommer = $this->concepts->createCategory($this->rootTeam, 'Sommer');
    $grill = $this->concepts->createCategory($this->rootTeam, 'Grill-Linie', $sommer->id);

    $flat = $this->concepts->categoriesFlat($this->rootTeam);
    expect($flat)->toHaveCount(2)
        ->and($flat[0]['name'])->toBe('Sommer')->and($flat[0]['depth'])->toBe(0)
        ->and($flat[1]['name'])->toBe('Grill-Linie')->and($flat[1]['depth'])->toBe(1)
        ->and($this->concepts->descendantIds($this->rootTeam, $sommer->id))->toContain($sommer->id)->toContain($grill->id);

    // Concept in der Unterkategorie → Filter auf die OBERkategorie findet es (inkl. Nachfahren)
    $c = $this->concepts->create($this->rootTeam, ['name' => 'Buffet']);
    $this->concepts->update($this->rootTeam, $c->id, ['category_id' => $grill->id]);
    expect($this->concepts->paginateBrowser(['category' => (string) $sommer->id], $this->rootTeam)->pluck('name')->all())->toBe(['Buffet']);

    // Sommer löschen → Grill-Linie rückt auf parent null; Concept bleibt an Grill-Linie
    $this->concepts->deleteCategory($this->rootTeam, $sommer->id);
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory::find($grill->id)->parent_id)->toBeNull()
        ->and($c->refresh()->category_id)->toBe($grill->id);
});

it('M10p C-09: Allergen-/Diät-Rollup — all-Flags vs enthält-Flags, Konfidenz = schwächstes Glied', function () {
    $veg1 = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'v1', 'name' => 'Salat A', 'status' => 'approved', 'ist_verkaufsrezept' => true,
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'spec_is_gluten_free' => true, 'allergene_konfidenz' => 'high']);
    $veg2 = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'v2', 'name' => 'Salat B', 'status' => 'approved', 'ist_verkaufsrezept' => true,
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'spec_is_gluten_free' => true, 'allergene_konfidenz' => 'medium']);
    $pork = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'p1', 'name' => 'Pulled Pork', 'status' => 'approved', 'ist_verkaufsrezept' => true,
        'spec_is_vegan' => false, 'spec_is_vegetarian' => false, 'spec_contains_pork' => true, 'allergene_konfidenz' => 'low']);

    $vorspeise = $this->pakete->create($this->rootTeam, ['name' => 'Salate', 'rolle' => 'Vorspeise']);
    $this->pakete->syncGerichte($this->rootTeam, $vorspeise->id, [['vk_recipe_id' => $veg1->id], ['vk_recipe_id' => $veg2->id]]);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Buffet']);
    $sVor = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Vorspeise']);
    $sHg = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $sVor->id, ['paket_id' => $vorspeise->id]);
    $this->concepts->fillSlot($this->rootTeam, $sHg->id, ['vk_recipe_id' => $pork->id]);

    $rollup = $this->concepts->allergenRollup($c->refresh());
    expect($rollup['n_gerichte'])->toBe(3)
        ->and($rollup['is_vegan'])->toBeFalse()                       // Pork nicht vegan
        ->and($rollup['contains_pork'])->toBeTrue()                   // mind. eines
        ->and($rollup['is_gluten_free'])->toBeFalse()                 // Pork nicht als glutenfrei markiert
        ->and($rollup['konfidenz'])->toBe('low');                     // schwächstes Glied
});
