<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConceptVariantService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R4.4 — Zutaten-Tausch im Concepter über konzept-lokale Slot-Varianten:
 * Quell-Gericht + andere Konzepte bleiben nachweislich unangetastet, Variante ist
 * katalog-unsichtbar, EK rechnet gegen die Variante, Rücksetzen räumt auf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ConceptVariantService::class);
    $this->concepts = app(ConceptService::class);
    $this->equiv = app(ComponentEquivalentService::class);

    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $mkGpMitPreis = function (string $name, float $preis) use ($supplier) {
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
    $this->butter = $mkGpMitPreis('Butter', 12.00);
    $this->margarine = $mkGpMitPreis('Margarine', 4.00);
    $this->equiv->verknuepfe($this->rootTeam, 'gp', $this->butter->id, 'gp', $this->margarine->id, 1.0);

    // Quell-Gericht mit Butter-Zutat, in ZWEI Konzepten gesetzt
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'butterkuchen', 'name' => 'DES: Butterkuchen',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 6.50, 'yield_kg' => 1.0,
    ]);
    $this->zutat = $this->gericht->ingredients()->create([
        'team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->butter->id,
        'raw_text' => 'Butter', 'quantity' => 500, 'unit_vocab_id' => $this->g->id,
    ]);
    app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);

    $this->conceptA = $this->concepts->create($this->rootTeam, ['name' => 'Konzept A']);
    $slotA = $this->concepts->addSlot($this->rootTeam, $this->conceptA->id, ['role' => 'Dessert']);
    $this->slotA = $this->concepts->fillSlot($this->rootTeam, $slotA->id, ['sales_recipe_id' => $this->gericht->id]);

    $this->conceptB = $this->concepts->create($this->rootTeam, ['name' => 'Konzept B']);
    $slotB = $this->concepts->addSlot($this->rootTeam, $this->conceptB->id, ['role' => 'Dessert']);
    $this->slotB = $this->concepts->fillSlot($this->rootTeam, $slotB->id, ['sales_recipe_id' => $this->gericht->id]);
});

it('Tausch in Konzept A: Slot-Variante entsteht, Quell-Gericht + Konzept B nachweislich unverändert', function () {
    $slot = $this->svc->tauscheZutatKonzeptLokal($this->rootTeam, $this->slotA->id, $this->zutat->id);

    // Slot A zeigt die Variante mit getauschter Zutat
    $variante = FoodAlchemistRecipe::find($slot->sales_recipe_id);
    expect($slot->variant_source_recipe_id)->toBe($this->gericht->id)
        ->and($variante->id)->not->toBe($this->gericht->id)
        ->and((int) $variante->variant_source_recipe_id)->toBe($this->gericht->id)
        ->and($variante->status->value)->toBe('draft')
        ->and((int) $variante->ingredients()->first()->gp_id)->toBe($this->margarine->id);

    // Quell-Gericht unangetastet: Zutat weiterhin Butter
    expect((int) $this->gericht->ingredients()->first()->gp_id)->toBe($this->butter->id)
        ->and($this->gericht->refresh()->name)->toBe('DES: Butterkuchen');

    // Konzept B unverändert: zeigt weiter das Original
    expect((int) $this->slotB->refresh()->sales_recipe_id)->toBe($this->gericht->id)
        ->and($this->slotB->variant_source_recipe_id)->toBeNull();

    // EK rechnet gegen die Variante: Margarine (4 €/kg) statt Butter (12 €/kg) bei 500 g
    expect((float) $variante->refresh()->ek_total_eur)->toBe(2.0)
        ->and((float) $this->gericht->refresh()->ek_total_eur)->toBe(6.0);
});

it('Variante ist katalog-unsichtbar (Picker + VK-Browser), Idempotenz beim zweiten Tausch-Aufruf', function () {
    $this->svc->varianteFuerSlot($this->rootTeam, $this->slotA->id);
    $slot = $this->svc->varianteFuerSlot($this->rootTeam, $this->slotA->id); // idempotent
    $varianteId = (int) $slot->sales_recipe_id;

    expect(FoodAlchemistRecipe::where('variant_source_recipe_id', $this->gericht->id)->count())->toBe(1);

    $picker = app(PaketService::class)->gerichtKandidaten($this->rootTeam, 'Butterkuchen');
    expect($picker->pluck('id')->all())->toBe([$this->gericht->id]); // Variante fehlt

    $browser = app(\Platform\FoodAlchemist\Services\SalesRecipeService::class)
        ->paginateBrowser(['search' => 'Butterkuchen'], $this->rootTeam);
    expect($browser->pluck('id')->all())->toBe([$this->gericht->id]);

    expect(FoodAlchemistRecipe::find($varianteId)->name)->toContain('Variante (Konzept A)');
});

it('Rücksetzen: Original zurück im Slot, Variante wird verworfen', function () {
    $this->svc->tauscheZutatKonzeptLokal($this->rootTeam, $this->slotA->id, $this->zutat->id);
    $varianteId = (int) $this->slotA->refresh()->sales_recipe_id;

    $slot = $this->svc->zuruecksetzen($this->rootTeam, $this->slotA->id);

    expect((int) $slot->sales_recipe_id)->toBe($this->gericht->id)
        ->and($slot->variant_source_recipe_id)->toBeNull()
        ->and(FoodAlchemistRecipe::find($varianteId))->toBeNull(); // soft-deleted
});

it('swap_locked wird respektiert — Tausch blockt typisiert', function () {
    $this->zutat->update(['swap_locked' => true]);

    expect(fn () => $this->svc->tauscheZutatKonzeptLokal($this->rootTeam, $this->slotA->id, $this->zutat->id))
        ->toThrow(RuntimeException::class, 'swap-gesperrt');

    // Kein stiller Global-Edit passiert
    expect((int) $this->gericht->ingredients()->first()->gp_id)->toBe($this->butter->id);
});

it('D1: fremdes Konzept — Kind-Team erzeugt keine Variante', function () {
    expect(fn () => $this->svc->varianteFuerSlot($this->childA, $this->slotA->id))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});

it('MCP: concept_slot_variante.POST tauscht konzept-lokal und setzt zurück', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($user, $this->rootTeam);

    $tausch = $registry->get('foodalchemist.concept_slot_variante.POST')->execute([
        'slot_id' => $this->slotA->id, 'ingredient_id' => $this->zutat->id,
    ], $kontext);
    expect($tausch->success)->toBeTrue()
        ->and($tausch->data['variiert'])->toBeTrue()
        ->and($tausch->data['variant_source_recipe_id'])->toBe($this->gericht->id);

    $reset = $registry->get('foodalchemist.concept_slot_variante.POST')->execute([
        'slot_id' => $this->slotA->id, 'zuruecksetzen' => true,
    ], $kontext);
    expect($reset->success)->toBeTrue()
        ->and($reset->data['variiert'])->toBeFalse()
        ->and($reset->data['sales_recipe_id'])->toBe($this->gericht->id);
});
