<?php

use Platform\FoodAlchemist\Models\FoodAlchemistBaustein;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\BausteinService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->bausteine = app(BausteinService::class);
    $this->concepts = app(ConceptService::class);

    $mk = fn (string $key, string $name, float $vk, float $ek) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
        'status' => 'approved', 'ist_verkaufsrezept' => true, 'vk_netto' => $vk, 'ek_total_eur' => $ek,
    ]);
    $this->green = $mk('g', 'Salat: Green Power', 2.00, 0.60);
    $this->sunny = $mk('s', 'Salat: Sunny Kick', 3.00, 0.90);
    $this->dessert = $mk('d', 'Dessert: Cool Down', 5.50, 1.50);
});

it('BausteinService auto-Preis = Σ der Gerichte (vk/ek), W% via MargeService', function () {
    $b = $this->bausteine->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'auto']);
    $this->bausteine->syncGerichte($this->rootTeam, $b->id, [
        ['vk_recipe_id' => $this->green->id], ['vk_recipe_id' => $this->sunny->id],
    ]);

    $b->refresh();
    expect((float) $b->preis_pro_person)->toBe(5.00)              // 2,00 + 3,00
        ->and((float) $b->ek_pro_person)->toBe(1.50)              // 0,60 + 0,90
        ->and((float) $b->wareneinsatz_prozent)->toBe(30.0)       // 1,50 / 5,00
        ->and($b->preis_stale)->toBeFalse()
        ->and($b->preis_berechnet_am)->not->toBeNull();
});

it('BausteinService manuell: gesetzter Per-Person-Preis bleibt trotz Gerichten', function () {
    $b = $this->bausteine->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->bausteine->update($this->rootTeam, $b->id, ['preis_pro_person' => 4.50, 'ek_pro_person' => 1.41, 'wareneinsatz_prozent' => 31.3]);
    $this->bausteine->syncGerichte($this->rootTeam, $b->id, [['vk_recipe_id' => $this->green->id], ['vk_recipe_id' => $this->sunny->id]]);

    expect((float) $b->refresh()->preis_pro_person)->toBe(4.50);  // NICHT auf 5,00 überschrieben
});

it('markStaleForRecipe markiert nur Auto-Bausteine mit dem Gericht', function () {
    $auto = $this->bausteine->create($this->rootTeam, ['name' => 'Auto', 'rolle' => 'Vorspeise', 'preis_modus' => 'auto']);
    $manuell = $this->bausteine->create($this->rootTeam, ['name' => 'Manuell', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->bausteine->syncGerichte($this->rootTeam, $auto->id, [['vk_recipe_id' => $this->green->id]]);
    $this->bausteine->syncGerichte($this->rootTeam, $manuell->id, [['vk_recipe_id' => $this->green->id]]);

    $betroffen = $this->bausteine->markStaleForRecipe($this->green->id);
    expect($betroffen)->toBe(1)
        ->and($auto->refresh()->preis_stale)->toBeTrue()
        ->and($manuell->refresh()->preis_stale)->toBeFalse();
});

it('ConceptService fillSlot erzwingt GENAU EINES (Baustein XOR Gericht)', function () {
    $b = $this->bausteine->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Vorspeise']);

    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['baustein_id' => $b->id]);
    expect($slot->refresh()->baustein_id)->toBe($b->id)->and($slot->vk_recipe_id)->toBeNull();

    // Auf festes Gericht umstellen → Baustein wird geleert
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['vk_recipe_id' => $this->dessert->id, 'menge' => 1]);
    expect($slot->refresh()->vk_recipe_id)->toBe($this->dessert->id)->and($slot->baustein_id)->toBeNull();
});

it('M10-04: Concept-Preis = Σ gespeicherte Baustein-Preise + feste Gerichte', function () {
    $salad = $this->bausteine->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->bausteine->update($this->rootTeam, $salad->id, ['preis_pro_person' => 4.50, 'ek_pro_person' => 1.41]);

    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $sVor = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Vorspeise']);
    $sDess = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Dessert']);
    $this->concepts->fillSlot($this->rootTeam, $sVor->id, ['baustein_id' => $salad->id]);
    $this->concepts->fillSlot($this->rootTeam, $sDess->id, ['vk_recipe_id' => $this->dessert->id]);

    $cockpit = $this->concepts->preisCockpit($concept->refresh());
    expect($cockpit['preis_pro_person'])->toBe(10.00)             // 4,50 + 5,50
        ->and($cockpit['zeilen'])->toHaveCount(2)
        ->and($cockpit['zeilen'][0]['typ'])->toBe('baustein')
        ->and($cockpit['zeilen'][1]['typ'])->toBe('gericht')
        ->and((float) $concept->refresh()->preis_pro_person_cache)->toBe(10.00);
});

it('M10-04: Baustein-Tausch ändert nur die Differenz (kein Kaskaden-Recompute)', function () {
    $billig = $this->bausteine->create($this->rootTeam, ['name' => 'Vorspeise A', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $teuer = $this->bausteine->create($this->rootTeam, ['name' => 'Vorspeise B', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->bausteine->update($this->rootTeam, $billig->id, ['preis_pro_person' => 4.50]);
    $this->bausteine->update($this->rootTeam, $teuer->id, ['preis_pro_person' => 6.00]);

    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Vorspeise']);
    $sDess = $this->concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Dessert']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['baustein_id' => $billig->id]);
    $this->concepts->fillSlot($this->rootTeam, $sDess->id, ['vk_recipe_id' => $this->dessert->id]);
    expect($this->concepts->preisCockpit($concept->refresh())['preis_pro_person'])->toBe(10.00);

    // Tauschbare Bausteine = gleiche Rolle
    expect($this->concepts->tauschbareBausteine($this->rootTeam, $slot->refresh())->pluck('name')->all())
        ->toBe(['Vorspeise A', 'Vorspeise B']);

    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['baustein_id' => $teuer->id]);
    expect($this->concepts->preisCockpit($concept->refresh())['preis_pro_person'])->toBe(11.50); // 6,00 + 5,50
});

it('M10-05: Vorlage-Fork kopiert Slots, Baustein bleibt Referenz, Concept ist eigenständig', function () {
    $salad = $this->bausteine->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise']);
    $vorlage = $this->concepts->create($this->rootTeam, ['name' => '3-Gang', 'is_vorlage' => true]);
    $s1 = $this->concepts->addSlot($this->rootTeam, $vorlage->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $s1->id, ['baustein_id' => $salad->id]);
    $this->concepts->addSlot($this->rootTeam, $vorlage->id, ['rolle' => 'Dessert']);

    $fork = $this->concepts->forkVonVorlage($this->rootTeam, $vorlage->id, 'Event Mai');
    expect($fork->is_vorlage)->toBeFalse()
        ->and($fork->vorlage_quelle_id)->toBe($vorlage->id)
        ->and($fork->slots()->count())->toBe(2)
        ->and($fork->slots()->where('rolle', 'Vorspeise')->first()->baustein_id)->toBe($salad->id); // Referenz erhalten

    // Fork ist eigenständig: Slot löschen ändert die Vorlage nicht
    $this->concepts->removeSlot($this->rootTeam, $fork->slots()->first()->id);
    expect($fork->slots()->count())->toBe(1)->and($vorlage->refresh()->slots()->count())->toBe(2);
});

it('Owner-Guard: Kind-Team kann geerbten Baustein/Concept nicht pflegen (D1)', function () {
    $b = $this->bausteine->create($this->rootTeam, ['name' => 'Root-Baustein', 'rolle' => 'Vorspeise']);
    expect(fn () => $this->bausteine->update($this->childA, $b->id, ['name' => 'Hack']))
        ->toThrow(\RuntimeException::class);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Root-Concept']);
    expect(fn () => $this->concepts->update($this->childA, $c->id, ['name' => 'Hack']))
        ->toThrow(\RuntimeException::class);
});

it('M10p C-08: Personenzahl → Gesamtpreis ×N + Mengen-Hochrechnung', function () {
    $b = $this->bausteine->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $this->bausteine->update($this->rootTeam, $b->id, ['preis_pro_person' => 4.50, 'ek_pro_person' => 1.41]);
    $this->bausteine->syncGerichte($this->rootTeam, $b->id, [['vk_recipe_id' => $this->green->id, 'menge' => 120]]); // 120 g/Person

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $this->concepts->update($this->rootTeam, $c->id, ['personen' => 80]);
    $slot = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['baustein_id' => $b->id]);

    $cockpit = $this->concepts->preisCockpit($c->refresh());
    expect($cockpit['preis_pro_person'])->toBe(4.50)
        ->and($cockpit['personen'])->toBe(80)
        ->and($cockpit['gesamt_preis'])->toBe(360.00);                // 4,50 × 80

    $hr = $this->concepts->mengenHochrechnung($c->refresh());
    expect($hr)->toHaveCount(1)
        ->and($hr[0]['menge_pro_person'])->toBe(120.0)
        ->and($hr[0]['gesamt_menge'])->toBe(9600.0);                  // 120 g × 80
});

it('M10p C-09: Allergen-/Diät-Rollup — all-Flags vs enthält-Flags, Konfidenz = schwächstes Glied', function () {
    $veg1 = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'v1', 'name' => 'Salat A', 'status' => 'approved', 'ist_verkaufsrezept' => true,
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'spec_is_gluten_free' => true, 'allergene_konfidenz' => 'high']);
    $veg2 = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'v2', 'name' => 'Salat B', 'status' => 'approved', 'ist_verkaufsrezept' => true,
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'spec_is_gluten_free' => true, 'allergene_konfidenz' => 'medium']);
    $pork = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'p1', 'name' => 'Pulled Pork', 'status' => 'approved', 'ist_verkaufsrezept' => true,
        'spec_is_vegan' => false, 'spec_is_vegetarian' => false, 'spec_contains_pork' => true, 'allergene_konfidenz' => 'low']);

    $vorspeise = $this->bausteine->create($this->rootTeam, ['name' => 'Salate', 'rolle' => 'Vorspeise']);
    $this->bausteine->syncGerichte($this->rootTeam, $vorspeise->id, [['vk_recipe_id' => $veg1->id], ['vk_recipe_id' => $veg2->id]]);

    $c = $this->concepts->create($this->rootTeam, ['name' => 'Buffet']);
    $sVor = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Vorspeise']);
    $sHg = $this->concepts->addSlot($this->rootTeam, $c->id, ['rolle' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $sVor->id, ['baustein_id' => $vorspeise->id]);
    $this->concepts->fillSlot($this->rootTeam, $sHg->id, ['vk_recipe_id' => $pork->id]);

    $rollup = $this->concepts->allergenRollup($c->refresh());
    expect($rollup['n_gerichte'])->toBe(3)
        ->and($rollup['is_vegan'])->toBeFalse()                       // Pork nicht vegan
        ->and($rollup['contains_pork'])->toBeTrue()                   // mind. eines
        ->and($rollup['is_gluten_free'])->toBeFalse()                 // Pork nicht als glutenfrei markiert
        ->and($rollup['konfidenz'])->toBe('low');                     // schwächstes Glied
});
