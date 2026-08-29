<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D5b: Concept-Slots/Blocks/Varianten/Paket (Editor-Parität).
 * PUT/DELETE/REORDER/GESCHIRR/DARREICHUNG + concept_blocks.POST/PUT +
 * concept_slot_variante.SWAP/RESET + concept_paket.BUILD.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);

    $this->svc = app(ConceptService::class);
    $this->concept = $this->svc->create($this->rootTeam, ['name' => 'Testkonzept']);
    $this->dish = $this->makeRecipe($this->rootTeam, 'DES: Testgericht', ['is_sales_recipe' => true, 'sales_net' => 6.5]);
    $mkSlot = function () {
        $s = $this->svc->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Gang']);

        return $this->svc->fillSlot($this->rootTeam, $s->id, ['sales_recipe_id' => $this->dish->id]);
    };
    $this->slotA = $mkSlot();
    $this->slotB = $mkSlot();
});

it('Registry-Smoke: alle 10 D5b-Tools registriert mit type=object', function () {
    $namen = [
        'concept_slots.PUT', 'concept_slots.DELETE', 'concept_slots.REORDER',
        'concept_slots.GESCHIRR', 'concept_slots.DARREICHUNG',
        'concept_blocks.POST', 'concept_blocks.PUT',
        'concept_slot_variante.SWAP', 'concept_slot_variante.RESET', 'concept_paket.BUILD',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('concept_slots.PUT: Rolle + wording folden', function () {
    $put = ($this->run)('foodalchemist.concept_slots.PUT', [
        'slot_id' => $this->slotA->id,
        'felder' => ['role' => 'Vorspeise', 'wording' => 'Gruß aus der Küche'],
    ]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));

    $slot = FoodAlchemistConceptSlot::find($this->slotA->id);
    expect($slot->role)->toBe('Vorspeise')
        ->and($slot->display_name ?? $slot->wording ?? '')->toContain('Gruß aus der Küche');
});

it('concept_slots.REORDER: Slots in Zielreihenfolge', function () {
    $re = ($this->run)('foodalchemist.concept_slots.REORDER', [
        'concept_id' => $this->concept->id,
        'ids' => [$this->slotB->id, $this->slotA->id],
    ]);
    expect($re->success)->toBeTrue('reorder: ' . ($re->error ?? ''));

    $ordered = FoodAlchemistConceptSlot::where('concept_id', $this->concept->id)
        ->orderBy('position')->pluck('id')->all();
    expect($ordered)->toBe([$this->slotB->id, $this->slotA->id]);
});

it('concept_slots.GESCHIRR + DARREICHUNG: lösen (null) läuft durch', function () {
    $g = ($this->run)('foodalchemist.concept_slots.GESCHIRR', ['slot_id' => $this->slotA->id, 'role' => 'Teller']);
    expect($g->success)->toBeTrue('geschirr: ' . ($g->error ?? ''));

    $d = ($this->run)('foodalchemist.concept_slots.DARREICHUNG', ['slot_id' => $this->slotA->id]);
    expect($d->success)->toBeTrue('darreichung: ' . ($d->error ?? ''));
});

it('concept_blocks.POST + PUT: Header-Block anlegen und ändern', function () {
    $post = ($this->run)('foodalchemist.concept_blocks.POST', [
        'concept_id' => $this->concept->id, 'type' => 'header', 'felder' => ['label' => 'Menü'],
    ]);
    expect($post->success)->toBeTrue('block-post: ' . ($post->error ?? ''));
    $blockId = $post->data['slot_id'];

    $put = ($this->run)('foodalchemist.concept_blocks.PUT', [
        'slot_id' => $blockId, 'felder' => ['label' => 'Abendmenü'],
    ]);
    expect($put->success)->toBeTrue('block-put: ' . ($put->error ?? ''));
});

it('concept_paket.BUILD: zwei Gericht-Slots zu einem Paket bündeln', function () {
    $build = ($this->run)('foodalchemist.concept_paket.BUILD', [
        'concept_id' => $this->concept->id,
        'slot_ids' => [$this->slotA->id, $this->slotB->id],
        'name' => 'Menü A',
    ]);
    expect($build->success)->toBeTrue('build: ' . ($build->error ?? ''))
        ->and($build->data)->toHaveKey('paket_slot_id');

    // Die beiden Einzel-Slots sind weg, ein Paket-Slot steht da.
    $paketSlot = FoodAlchemistConceptSlot::find($build->data['paket_slot_id']);
    expect($paketSlot->type)->toBe('paket')
        ->and(FoodAlchemistConceptSlot::whereIn('id', [$this->slotA->id, $this->slotB->id])->count())->toBe(0);
});

it('concept_slots.DELETE: Slot entfernen', function () {
    $del = ($this->run)('foodalchemist.concept_slots.DELETE', ['slot_id' => $this->slotB->id]);
    expect($del->success)->toBeTrue('delete: ' . ($del->error ?? ''));
    expect(FoodAlchemistConceptSlot::find($this->slotB->id))->toBeNull();
});

it('concept_slot_variante.SWAP + RESET: konzept-lokaler Zutat-Tausch', function () {
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $mkGp = function (string $name, float $preis) use ($supplier) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };
    $butter = $mkGp('Butter', 12.00);
    $margarine = $mkGp('Margarine', 4.00);
    app(ComponentEquivalentService::class)->verknuepfe($this->rootTeam, 'gp', $butter->id, 'gp', $margarine->id, 1.0);

    $gericht = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'butterkuchen', 'name' => 'DES: Butterkuchen', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 6.5, 'yield_kg' => 1.0]);
    $zutat = $gericht->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $butter->id, 'raw_text' => 'Butter', 'quantity' => 500, 'unit_vocab_id' => $g->id]);
    app(RecipeRecomputeService::class)->recomputePipeline($gericht->id);

    $slot = $this->svc->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Dessert']);
    $slot = $this->svc->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $gericht->id]);

    $swap = ($this->run)('foodalchemist.concept_slot_variante.SWAP', ['slot_id' => $slot->id, 'ingredient_id' => $zutat->id]);
    expect($swap->success)->toBeTrue('swap: ' . ($swap->error ?? ''));
    $variante = FoodAlchemistRecipe::find(FoodAlchemistConceptSlot::find($slot->id)->sales_recipe_id);
    expect((int) $variante->variant_source_recipe_id)->toBe($gericht->id)
        ->and((int) $variante->ingredients()->first()->gp_id)->toBe($margarine->id);

    $reset = ($this->run)('foodalchemist.concept_slot_variante.RESET', ['slot_id' => $slot->id]);
    expect($reset->success)->toBeTrue('reset: ' . ($reset->error ?? ''));
    expect((int) FoodAlchemistConceptSlot::find($slot->id)->sales_recipe_id)->toBe($gericht->id);
});

it('Guards: unbekannt → NOT_FOUND; fremd → ACCESS_DENIED; leere felder → VALIDATION_ERROR', function () {
    expect(($this->run)('foodalchemist.concept_slots.PUT', ['slot_id' => 999999, 'felder' => ['role' => 'X']])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.concept_slots.PUT', ['slot_id' => $this->slotA->id, 'felder' => ['role' => 'X']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->run)('foodalchemist.concept_slots.PUT', ['slot_id' => $this->slotA->id, 'felder' => []])->errorCode)->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.concept_paket.BUILD', ['concept_id' => $this->concept->id, 'slot_ids' => [$this->slotA->id], 'name' => 'X'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});
