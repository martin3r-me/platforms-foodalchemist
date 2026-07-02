<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ersatz-Logik (make-or-buy + Artikel-Ersatz) im Zutaten-Editor:
 * ersatzHinweise (Batch, richtungsaufgelöster Faktor), ersatzFuer (Renderless,
 * Client-Nachlader), tauscheZutat (Server-Weg mit Recompute) + Markup-Hook.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeService::class);
    $this->equiv = app(ComponentEquivalentService::class);
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

it('ersatzHinweise: EINE Batch-Abfrage, Faktor je Richtung aufgeloest, tote Ziele fallen raus', function () {
    $frisch = ($this->mkGpMitPreis)('Tomate frisch', 2.00);
    $konserve = ($this->mkGpMitPreis)('Tomate Konserve', 1.00);
    $sub = $this->svc->create($this->rootTeam, ['name' => 'Sub: Tomatensugo']);

    // 1 kg frisch ≙ 0,8 kg Konserve → source→alt ×0,8, alt→source ×1,25
    $this->equiv->verknuepfe($this->rootTeam, 'gp', $frisch->id, 'gp', $konserve->id, 0.8);
    $this->equiv->verknuepfe($this->rootTeam, 'recipe', $sub->id, 'gp', $frisch->id, 2.0);

    $h = $this->equiv->ersatzHinweise($this->rootTeam, [
        ['gp', $frisch->id], ['gp', $konserve->id], ['recipe', $sub->id],
    ]);

    expect($h['gp:' . $frisch->id]->id)->toBe($konserve->id)
        ->and($h['gp:' . $frisch->id]->faktor)->toBe(0.8)                        // von source aus: × f
        ->and($h['gp:' . $konserve->id]->id)->toBe($frisch->id)
        ->and($h['gp:' . $konserve->id]->faktor)->toBe(1.25)                     // von alt aus: × 1/f
        ->and($h['recipe:' . $sub->id]->kind)->toBe('gp')
        ->and($h['recipe:' . $sub->id]->name)->toBe('Tomate frisch');

    // Gegenseite gelöscht → Fallback auf die nächste hinterlegte Äquivalenz (Sub-Rezept)
    $konserve->delete();
    $h2 = $this->equiv->ersatzHinweise($this->rootTeam, [['gp', $frisch->id]]);
    expect($h2['gp:' . $frisch->id]->kind)->toBe('recipe')
        ->and($h2['gp:' . $frisch->id]->id)->toBe($sub->id)
        ->and($h2['gp:' . $frisch->id]->faktor)->toBe(0.5);                      // von alt aus: × 1/2

    // … und ohne lebende Gegenseite verschwindet der Hinweis ganz
    $sub->delete();
    expect($this->equiv->ersatzHinweise($this->rootTeam, [['gp', $frisch->id]]))
        ->not->toHaveKey('gp:' . $frisch->id);
});

it('Editor: Zeilen tragen den Ersatz-Hinweis, ersatzFuer laedt fuer Client-Zeilen nach, Markup-Hook vorhanden', function () {
    $frisch = ($this->mkGpMitPreis)('Tomate frisch', 2.00);
    $konserve = ($this->mkGpMitPreis)('Tomate Konserve', 1.00);
    $ohne = ($this->mkGpMitPreis)('Zwiebel', 1.00);
    $this->equiv->verknuepfe($this->rootTeam, 'gp', $frisch->id, 'gp', $konserve->id, 0.8);

    $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['gp_id' => $frisch->id, 'raw_text' => '500 g Tomate frisch', 'menge' => '500', 'einheit_vocab_id' => $this->g->id],
        ['gp_id' => $ohne->id, 'raw_text' => '100 g Zwiebel', 'menge' => '100', 'einheit_vocab_id' => $this->g->id],
    ]);

    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $lw = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezept->id, 'eingebettet' => true]);

    $zeilen = $lw->viewData('zeilenJson');
    expect($zeilen[0]['ersatz']['id'])->toBe($konserve->id)
        ->and($zeilen[0]['ersatz']['name'])->toBe('Tomate Konserve')
        ->and($zeilen[0]['ersatz']['faktor'])->toBe(0.8)
        ->and($zeilen[1]['ersatz'])->toBeNull()                                  // ohne Katalog-Eintrag kein Hinweis
        ->and($lw->html())->toContain('data-zeile-ersatz');

    // Renderless-Nachlader (Client fügt Zeile hinzu / tauscht via Browser)
    $nachgeladen = $lw->instance()->ersatzFuer($konserve->id, null);
    expect($nachgeladen['id'])->toBe($frisch->id)
        ->and($nachgeladen['faktor'])->toBe(1.25)
        ->and($lw->instance()->ersatzFuer($ohne->id, null))->toBeNull();
});

it('tauscheZutat (Server-Weg): tauscht die Realisierung, rechnet Menge um und rechnet das Rezept neu', function () {
    $frisch = ($this->mkGpMitPreis)('Tomate frisch', 2.00);
    $konserve = ($this->mkGpMitPreis)('Tomate Konserve', 1.00);
    $this->equiv->verknuepfe($this->rootTeam, 'gp', $frisch->id, 'gp', $konserve->id, 0.8);

    $r = $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['gp_id' => $frisch->id, 'raw_text' => '1000 g Tomate frisch', 'menge' => '1000', 'einheit_vocab_id' => $this->g->id],
    ]);
    expect((float) $r->ek_total_eur)->toBe(2.00);                                // 1 kg × 2 €/kg

    $zutat = $this->equiv->tauscheZutat($this->rootTeam, (int) $r->ingredients()->first()->id);

    expect($zutat->gp_id)->toBe($konserve->id)
        ->and((float) $zutat->menge)->toBe(800.0)                                // 1000 × 0,8
        ->and((float) $r->refresh()->ek_total_eur)->toBe(0.80);                  // 0,8 kg × 1 €/kg

    // Zutat ohne Katalog-Eintrag → sprechende Ablehnung
    $r2 = $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [
        ['gp_id' => ($this->mkGpMitPreis)('Zwiebel', 1.00)->id, 'raw_text' => '100 g Zwiebel', 'menge' => '100', 'einheit_vocab_id' => $this->g->id],
    ]);
    expect(fn () => $this->equiv->tauscheZutat($this->rootTeam, (int) $r2->ingredients()->first()->id))
        ->toThrow(RuntimeException::class, 'Kein Ersatz');
});
