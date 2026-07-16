<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #511 (b): der Editor-Tauschweg end-to-end — client rows → speichern →
 * syncIngredients → recomputeAndPropagate → Events. Der bislang ungetestete
 * Pfad (IngredientEditorTest deckt nur den Direkt-Sync, ErsatzTauschTest nur
 * den Service-Tauschweg). Beweist: Server-Propagation an die Eltern läuft,
 * der Save signalisiert sie per Event an die kosten-abhängigen Panels.
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
});

it('Tausch im Sub-Rezept propagiert den EK bis zum Eltern-Gericht (Server-These)', function () {
    $limette = ($this->mkGpMitPreis)('Limette', 4.00);
    $petersilie = ($this->mkGpMitPreis)('Petersilie', 10.00);

    // Sub-Rezept: 1 kg Limette → EK 4,00 €, yield 1 kg → ek_per_kg 4,00
    $sub = $this->svc->create($this->rootTeam, ['name' => 'Sub: Salat-Basis']);
    $this->svc->syncIngredients($this->rootTeam, $sub->id, [
        ['id' => null, 'gp_id' => $limette->id, 'raw_text' => '1000 g Limette', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
    ]);
    $limetteZeile = $sub->fresh()->ingredients()->first();

    // Eltern-Gericht: enthält 1 kg des Sub-Rezepts → erbt dessen ek_per_kg
    $eltern = $this->svc->create($this->rootTeam, ['name' => 'Gericht: Getreidesalat']);
    $this->svc->syncIngredients($this->rootTeam, $eltern->id, [
        ['id' => null, 'referenced_recipe_id' => $sub->id, 'raw_text' => '1000 g Salat-Basis', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
    ]);
    expect((float) $eltern->fresh()->ek_total_eur)->toBe(4.00);

    // TAUSCH im Sub: Limette → Petersilie (10 €/kg), gleiche Zeile/Menge
    $this->svc->syncIngredients($this->rootTeam, $sub->id, [
        ['id' => $limetteZeile->id, 'gp_id' => $petersilie->id, 'raw_text' => '1000 g Petersilie', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
    ]);

    // Kind- UND Eltern-EK sind server-seitig frisch (Propagation belegt)
    expect((float) $sub->fresh()->ek_total_eur)->toBe(10.00)
        ->and((float) $eltern->fresh()->ek_total_eur)->toBe(10.00);
});

it('recomputeAndPropagate liefert die betroffenen Rezept-IDs (Kind + transitive Eltern)', function () {
    $gp = ($this->mkGpMitPreis)('Limette', 4.00);
    $sub = $this->svc->create($this->rootTeam, ['name' => 'Sub: Basis']);
    $this->svc->syncIngredients($this->rootTeam, $sub->id, [
        ['id' => null, 'gp_id' => $gp->id, 'raw_text' => '1000 g Limette', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
    ]);
    $eltern = $this->svc->create($this->rootTeam, ['name' => 'Gericht: Salat']);
    $this->svc->syncIngredients($this->rootTeam, $eltern->id, [
        ['id' => null, 'referenced_recipe_id' => $sub->id, 'raw_text' => '1000 g Basis', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
    ]);

    $betroffen = app(RecipeRecomputeService::class)->recomputeAndPropagate($sub->id);
    expect($betroffen)->toContain($sub->id)->toContain($eltern->id);
});

it('F2: unbepreiste Zutat liefert ek_pro_g=null und der Editor trägt die Warnhinweise', function () {
    $bepreist = ($this->mkGpMitPreis)('Karotte', 2.00);
    $ohnePreis = $this->makeGp($this->rootTeam, 'Petersilie glatt');   // KEIN LA/Preis → echte §8-Lücke
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $rezept = $this->svc->create($this->rootTeam, ['name' => 'Salat: Warnung']);
    $this->svc->syncIngredients($this->rootTeam, $rezept->id, [
        ['gp_id' => $bepreist->id, 'raw_text' => '500 g Karotte', 'quantity' => '500', 'unit_vocab_id' => $this->g->id],
        ['gp_id' => $ohnePreis->id, 'raw_text' => '50 g Petersilie', 'quantity' => '50', 'unit_vocab_id' => $this->g->id],
    ]);

    // Server kennt die Lücke: 1 von 2 bepreist
    expect($rezept->fresh()->ek_n_ingredients_priced)->toBe(1)
        ->and($rezept->fresh()->ek_n_ingredients_total)->toBe(2);

    $lw = Livewire::test(IngredientEditor::class, ['recipeId' => $rezept->id, 'eingebettet' => true]);
    $zeilen = collect($lw->viewData('zeilenJson'))->keyBy('gp_id');

    expect($zeilen[$bepreist->id]['ek_pro_g'])->not->toBeNull()
        ->and($zeilen[$ohnePreis->id]['ek_pro_g'])->toBeNull()          // ⇒ triggert die amber-Warnung
        ->and($lw->html())->toContain('data-ek-unpriced')->toContain('data-ek-unvollstaendig');
});

it('IngredientEditor::speichern signalisiert Kosten-Änderung an die abhängigen Panels', function () {
    $gp = ($this->mkGpMitPreis)('Karotte', 2.00);
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $rezept = $this->svc->create($this->rootTeam, ['name' => 'Fond: Test']);

    Livewire::test(IngredientEditor::class)
        ->call('oeffnen', $rezept->id)
        ->call('speichern', [
            ['id' => null, 'gp_id' => $gp->id, 'raw_text' => '300 g Karotte', 'quantity' => '300', 'unit_vocab_id' => $this->g->id],
        ])
        ->assertSet('fehler', null)
        ->assertDispatched('recipe-gespeichert')
        ->assertDispatched('recipe-selected')
        ->assertDispatched('kosten-aktualisiert');
});

it('F4 (E2E durch den Editor): Sub-Tausch via Livewire::speichern rechnet den Eltern-EK neu + signalisiert die Eltern-ID', function () {
    $limette = ($this->mkGpMitPreis)('Limette', 4.00);
    $petersilie = ($this->mkGpMitPreis)('Petersilie', 10.00);
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $sub = $this->svc->create($this->rootTeam, ['name' => 'Sub: Basis']);
    $this->svc->syncIngredients($this->rootTeam, $sub->id, [
        ['id' => null, 'gp_id' => $limette->id, 'raw_text' => '1000 g Limette', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
    ]);
    $limetteZeile = $sub->fresh()->ingredients()->first();
    $eltern = $this->svc->create($this->rootTeam, ['name' => 'Gericht: Salat']);
    $this->svc->syncIngredients($this->rootTeam, $eltern->id, [
        ['id' => null, 'referenced_recipe_id' => $sub->id, 'raw_text' => '1000 g Basis', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
    ]);
    expect((float) $eltern->fresh()->ek_total_eur)->toBe(4.00);

    // Der Alpine-⇄/♻-Tausch produziert genau diese Payload (gleiche Zeile, neuer gp_id) → speichern.
    Livewire::test(IngredientEditor::class)
        ->call('oeffnen', $sub->id)
        ->call('speichern', [
            ['id' => $limetteZeile->id, 'gp_id' => $petersilie->id, 'raw_text' => '1000 g Petersilie', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
        ])
        ->assertSet('fehler', null)
        ->assertDispatched('kosten-aktualisiert', fn ($event, $params) => in_array($eltern->id, $params['ids'] ?? [], true));

    // Eltern-EK ist ohne Reload frisch (Kind 10 €/kg → Eltern 10 €)
    expect((float) $sub->fresh()->ek_total_eur)->toBe(10.00)
        ->and((float) $eltern->fresh()->ek_total_eur)->toBe(10.00);
});
