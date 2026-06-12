<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M4-07/08: Zutaten-Editor — syncIngredients (Voll-Sync, EIN Recompute,
 * XOR/Tiefen-Guards), Reorder via Array-Reihenfolge, Picker (GPs + Subs der
 * Team-Kette, ohne self), Modal-Roundtrip.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeService::class);
    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->mkGpMitPreis = function (string $name, float $preis) use ($supplier) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };
    $this->rezept = $this->svc->create($this->rootTeam, ['name' => 'Fond: Test']);
});

it('syncIngredients: anlegen, ändern, löschen, Reorder = Array-Reihenfolge — EIN Recompute (DoD M4-07)', function () {
    $karotte = ($this->mkGpMitPreis)('Karotte', 2.00);
    $zwiebel = ($this->mkGpMitPreis)('Zwiebel', 1.00);

    $r = $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['id' => null, 'gp_id' => $karotte->id, 'raw_text' => '500 g Karotte', 'menge' => '500', 'einheit_vocab_id' => $this->g->id],
        ['id' => null, 'gp_id' => $zwiebel->id, 'raw_text' => '250 g Zwiebel', 'menge' => '250', 'einheit_vocab_id' => $this->g->id],
    ]);

    expect($r->ingredients()->count())->toBe(2)
        ->and((float) $r->yield_kg)->toBe(0.75)
        ->and((float) $r->ek_total_eur)->toBe(round(0.5 * 2 + 0.25 * 1, 2));    // 1.25

    // Reorder (Zwiebel zuerst) + Karotte ändern + implizit nichts löschen
    $ids = $r->ingredients()->orderBy('position')->pluck('id', 'position')->all();
    $r = $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['id' => $ids[2], 'gp_id' => $zwiebel->id, 'raw_text' => '250 g Zwiebel', 'menge' => '250', 'einheit_vocab_id' => $this->g->id],
        ['id' => $ids[1], 'gp_id' => $karotte->id, 'raw_text' => '1000 g Karotte', 'menge' => '1000', 'einheit_vocab_id' => $this->g->id],
    ]);
    $sortiert = $r->ingredients()->orderBy('position')->get();
    expect($sortiert->first()->gp_id)->toBe($zwiebel->id)                        // Reorder persistiert (DoD M4-08)
        ->and((float) $sortiert->last()->menge)->toBe(1000.0)
        ->and((float) $r->yield_kg)->toBe(1.25);

    // Zeile weglassen ⇒ gelöscht
    $r = $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['id' => $sortiert->last()->id, 'gp_id' => $karotte->id, 'raw_text' => '1000 g Karotte', 'menge' => '1000', 'einheit_vocab_id' => $this->g->id],
    ]);
    expect($r->ingredients()->count())->toBe(1)->and((float) $r->yield_kg)->toBe(1.0);
});

it('syncIngredients erzwingt XOR, Menge > 0 und die GL-02-Verknüpfungs-Guards', function () {
    $gp = ($this->mkGpMitPreis)('Karotte', 2.00);
    $sub = $this->svc->create($this->rootTeam, ['name' => 'Sub: Fond']);

    expect(fn () => $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['gp_id' => $gp->id, 'referenced_recipe_id' => $sub->id, 'raw_text' => 'x', 'menge' => '1', 'einheit_vocab_id' => $this->g->id],
    ]))->toThrow(RuntimeException::class, 'XOR');

    expect(fn () => $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['gp_id' => $gp->id, 'raw_text' => 'x', 'menge' => '0', 'einheit_vocab_id' => $this->g->id],
    ]))->toThrow(RuntimeException::class, 'Menge');

    expect(fn () => $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['referenced_recipe_id' => $this->rezept->id, 'raw_text' => 'x', 'menge' => '1', 'einheit_vocab_id' => $this->g->id],
    ]))->toThrow(RuntimeException::class, 'Selbstreferenz');
});

it('Picker findet GPs der Team-Kette + Basisrezepte ohne self (DoD M4-08) und liefert ek_pro_g', function () {
    ($this->mkGpMitPreis)('Limette', 4.00);
    $this->svc->create($this->rootTeam, ['name' => 'Limetten-Fond']);

    // Kind erbt lesend (D1) — Service direkt (Livewire-Test-API hat kein Rückgabe-Assert)
    $treffer = $this->svc->sucheZutatenZiel($this->childA, 'limette', $this->rezept->id);

    expect(collect($treffer)->pluck('typ')->sort()->values()->all())->toBe(['gp', 'sub'])
        ->and(collect($treffer)->firstWhere('typ', 'gp')['ek_pro_g'])->toBe(0.004)  // 4 €/kg
        ->and(collect($treffer)->pluck('name'))->not->toContain('Fond: Test');      // self raus
});

it('Modal-Roundtrip: speichern synct + dispatcht Events; D1-Gate blockt fremde Teams', function () {
    $gp = ($this->mkGpMitPreis)('Karotte', 2.00);
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(IngredientEditor::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('speichern', [
            ['id' => null, 'gp_id' => $gp->id, 'raw_text' => '300 g Karotte', 'menge' => '300', 'einheit_vocab_id' => $this->g->id],
        ])
        ->assertSet('fehler', null)
        ->assertDispatched('recipe-gespeichert');

    expect((float) $this->rezept->fresh()->yield_kg)->toBe(0.3);

    // Kind-Team darf das geerbte Rezept nicht bearbeiten
    $this->actingAs($this->makeUser($this->childA, 'Kind User'));
    Livewire::test(IngredientEditor::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('speichern', [])
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Besitzer-Team'));
});

it('gpArtikel (GP-Peek): liefert LAs hinter dem GP mit ★-Lead zuerst, VPE, Preis und Vergleichspreis', function () {
    $gp = ($this->mkGpMitPreis)('Pflaumenmarmelade', 3.50);                      // Lead: Necta, 1 kg
    $hanos = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $la2 = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $hanos->id,
        'designation' => 'PFLAUMENMARMELADE GROSS', 'article_number' => '23438031',
        'qty' => 0.75, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la2->id, 'gp_id' => $gp->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la2->id, 'price' => 4.20, 'status' => '0']);

    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $artikel = Livewire::test(IngredientEditor::class)->instance()->gpArtikel($gp->id);

    expect($artikel)->toHaveCount(2)
        ->and($artikel[0]['lead'])->toBeTrue()                                   // Lead sortiert nach oben
        ->and($artikel[0]['lieferant'])->toBe('Necta')
        ->and($artikel[0]['preis'])->toBe('3,50 €')
        ->and($artikel[0]['vergleichspreis'])->toBe('3,50 €/kg')
        ->and($artikel[1]['lead'])->toBeFalse()
        ->and($artikel[1]['lieferant'])->toBe('Hanos')
        ->and($artikel[1]['artikelnr'])->toBe('23438031')
        ->and($artikel[1]['vpe'])->toBe('0,75 kg')
        ->and($artikel[1]['vergleichspreis'])->toBe('5,60 €/kg');                // 4,20 € / 0,75 kg

    // Editor-Markup: Drag-Handle + Peek-Hooks im (eingebetteten) Editor vorhanden
    $html = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true])->html();
    expect($html)->toContain('data-drag-handle')->toContain('data-gp-peek');
});
