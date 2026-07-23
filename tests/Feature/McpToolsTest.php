<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M8-01: MCP-Tools — Registry-Smoke (alle 7 Namen), Lese-Tools team-scoped
 * über Services, Schreib-Tool NUR via GL-07-Proposal-Flow (accept-Pfad mit
 * Lineage; Override-First blockt typisiert als Tool-Error).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('Registry-Smoke: alle Modul-Tools registriert (Naming <modul>.resource.VERB)', function () {
    foreach ([
        'foodalchemist.gps.SEARCH', 'foodalchemist.gps.GET',
        'foodalchemist.recipes.SEARCH', 'foodalchemist.recipes.GET',
        'foodalchemist.verkaufsrezepte.SEARCH', 'foodalchemist.artikel.SEARCH',
        'foodalchemist.foodbook.GET',
        'foodalchemist.recipe_klasse.POST',
    ] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name);
        expect($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('Lese-Tools: recipes.SEARCH/GET team-scoped, VK-Rezepte bleiben draußen (Scope-Härte)', function () {
    $basis = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Fond: Kalb', 'status' => 'approved', 'yield_kg' => 1.0]);
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'vk', 'name' => 'HG: Fond-Teller', 'status' => 'draft', 'is_sales_recipe' => true]);

    $such = $this->registry->get('foodalchemist.recipes.SEARCH')->execute(['q' => 'Fond'], $this->kontext);
    expect($such->success)->toBeTrue()
        ->and($such->data['total'])->toBe(1)                          // VK-Rezept nicht in der Basis-Sicht
        ->and($such->data['recipes'][0]['name'])->toBe('Fond: Kalb');

    $detail = $this->registry->get('foodalchemist.recipes.GET')->execute(['id' => $basis->id], $this->kontext);
    expect($detail->success)->toBeTrue()
        ->and($detail->data['name'])->toBe('Fond: Kalb');

    $vkSuche = $this->registry->get('foodalchemist.verkaufsrezepte.SEARCH')->execute(['q' => 'Fond'], $this->kontext);
    expect($vkSuche->data['total'])->toBe(1)
        ->and($vkSuche->data['verkaufsrezepte'][0]['name'])->toBe('HG: Fond-Teller');
});

it('M11-11: foodbook.GET liefert Kopf + Kapitel/Blöcke + aggregierten Angebotspreis (team-scoped)', function () {
    $pakete = app(\Platform\FoodAlchemist\Services\PaketService::class);
    $concepts = app(\Platform\FoodAlchemist\Services\ConceptService::class);
    $foodbooks = app(\Platform\FoodAlchemist\Services\FoodbookService::class);

    $paket = $pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise', 'price_mode' => 'manuell']);
    $pakete->update($this->rootTeam, $paket->id, ['price_per_person' => 4.50]);
    $concept = $concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Vorspeise']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['package_id' => $paket->id]);

    $fb = $foodbooks->create($this->rootTeam, ['label' => 'Angebot Adler', 'customer' => 'Hotel Adler', 'personen' => 100]);
    $kap = $foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Menü']);
    $foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $concept->id]);

    $res = $this->registry->get('foodalchemist.foodbook.GET')->execute(['id' => $fb->id], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['label'])->toBe('Angebot Adler')
        ->and($res->data['customer'])->toBe('Hotel Adler')
        ->and($res->data['price']['vk_pro_person'])->toBe(4.50)
        ->and($res->data['price']['gesamt_vk'])->toBe(450.00)               // 4,50 × 100 Pax
        ->and($res->data['kapitel'][0]['title'])->toBe('Menü')
        ->and($res->data['kapitel'][0]['blocks'][0]['name'])->toBe('Grill-Buffet');

    $miss = $this->registry->get('foodalchemist.foodbook.GET')->execute(['id' => 999999], $this->kontext);
    expect($miss->success)->toBeFalse()->and($miss->errorCode)->toBe('NOT_FOUND');
});

it('E1.4: foodbook_blocks.POST recipe_ref-Roundtrip — sales_recipe_id + price_basis-Angleich (pro_stueck→pauschal)', function () {
    $foodbooks = app(\Platform\FoodAlchemist\Services\FoodbookService::class);
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk_ref', 'name' => 'HG: Wolfsbarsch',
        'status' => 'draft', 'is_sales_recipe' => true, 'sales_net' => 12.50,
    ]);
    $fb = $foodbooks->create($this->rootTeam, ['label' => 'Einzel-Test', 'personen' => 100]);
    $kap = $foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'À la carte']);

    $tool = $this->registry->get('foodalchemist.foodbook_blocks.POST');

    // pro_stueck (€/Position, flach) muss kanonisch als 'pauschal' landen — die „pro_stueck-Falle".
    $res = $tool->execute([
        'chapter_id' => $kap->id, 'type' => 'recipe_ref',
        'sales_recipe_id' => $vk->id, 'price_basis' => 'pro_stueck',
    ], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['block']['type'])->toBe('recipe_ref')
        ->and($res->data['block']['sales_recipe_id'])->toBe($vk->id);
    $block = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::find($res->data['block']['id']);
    expect($block->price_basis)->toBe('pauschal');                       // NICHT roh 'pro_stueck'

    // pro_person → person (Per-Person, ×Pax)
    $res2 = $tool->execute([
        'chapter_id' => $kap->id, 'type' => 'recipe_ref',
        'sales_recipe_id' => $vk->id, 'price_basis' => 'pro_person',
    ], $this->kontext);
    $block2 = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::find($res2->data['block']['id']);
    expect($block2->price_basis)->toBe('person');

    // recipe_ref ohne sales_recipe_id → Service-Guard als VALIDATION_ERROR
    $ohne = $tool->execute(['chapter_id' => $kap->id, 'type' => 'recipe_ref'], $this->kontext);
    expect($ohne->success)->toBeFalse()->and($ohne->errorCode)->toBe('VALIDATION_ERROR');
});

it('E1.4: foodbook_blocks.POST Tenancy — fremd-Team-Gericht als sales_recipe_id blockt (NOT_FOUND)', function () {
    $foodbooks = app(\Platform\FoodAlchemist\Services\FoodbookService::class);
    // Gericht gehört Kind A — für Root (Ancestry aufwärts) NICHT sichtbar.
    $fremd = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'vk_fremd', 'name' => 'HG: Fremd',
        'status' => 'draft', 'is_sales_recipe' => true,
    ]);
    $fb = $foodbooks->create($this->rootTeam, ['label' => 'Tenancy-Test', 'personen' => 50]);
    $kap = $foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Kap']);

    $res = $this->registry->get('foodalchemist.foodbook_blocks.POST')->execute([
        'chapter_id' => $kap->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $fremd->id,
    ], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('Schreib-Tool: ohne accept nur Vorschlag; accept=true schreibt via GL-07; manual blockt als Tool-Error', function () {
    $hg = FoodAlchemistDishMainGroup::create(['code' => 'HG', 'label' => 'Hauptgang']);
    $klasse = FoodAlchemistDishClass::create(['dish_main_group_id' => $hg->id, 'code' => 'HG_F', 'label' => 'Fleisch', 'diet_form' => 'fleisch']);
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk2', 'name' => 'HG: Filet', 'status' => 'draft',
        'is_sales_recipe' => true, 'dish_class_id' => $klasse->id,  // Kontext fürs Fake-Echo
    ]);
    $tool = $this->registry->get('foodalchemist.recipe_klasse.POST');

    $nurVorschlag = $tool->execute(['recipe_id' => $vk->id], $this->kontext);
    expect($nurVorschlag->success)->toBeTrue()
        ->and($nurVorschlag->data['klasse_id'])->toBe($klasse->id)
        ->and($nurVorschlag->data['accepted'])->toBeFalse();

    $vk->update(['dish_class_id' => null]);
    $mitAccept = $tool->execute(['recipe_id' => $vk->id, 'accept' => true], $this->kontext);
    // Fake-Echo ohne gesetzte Klasse ⇒ ehrlicher Nicht-Treffer, accepted bleibt false
    expect($mitAccept->data['accepted'])->toBeFalse();

    $vk->update(['dish_class_id' => $klasse->id]);
    $echterAccept = $tool->execute(['recipe_id' => $vk->id, 'accept' => true], $this->kontext);
    expect($echterAccept->data['accepted'])->toBeTrue()
        ->and($vk->fresh()->dish_class_source)->toBe('ki');       // GL-07-Lineage, nie Direkt-Write

    $vk->update(['dish_class_source' => 'manual']);
    $geblockt = $tool->execute(['recipe_id' => $vk->id, 'accept' => true], $this->kontext);
    expect($geblockt->success)->toBeFalse()
        ->and($geblockt->errorCode)->toBe('OVERRIDE_FIRST');
});
