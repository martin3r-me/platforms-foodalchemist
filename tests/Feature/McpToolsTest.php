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
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'vk', 'name' => 'HG: Fond-Teller', 'status' => 'draft', 'ist_verkaufsrezept' => true]);

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

    $paket = $pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'preis_modus' => 'manuell']);
    $pakete->update($this->rootTeam, $paket->id, ['preis_pro_person' => 4.50]);
    $concept = $concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $slot = $concepts->addSlot($this->rootTeam, $concept->id, ['rolle' => 'Vorspeise']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $paket->id]);

    $fb = $foodbooks->create($this->rootTeam, ['bezeichnung' => 'Angebot Adler', 'kunde' => 'Hotel Adler', 'personen' => 100]);
    $kap = $foodbooks->addKapitel($this->rootTeam, $fb->id, ['titel' => 'Menü']);
    $foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $concept->id]);

    $res = $this->registry->get('foodalchemist.foodbook.GET')->execute(['id' => $fb->id], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['bezeichnung'])->toBe('Angebot Adler')
        ->and($res->data['kunde'])->toBe('Hotel Adler')
        ->and($res->data['preis']['vk_pro_person'])->toBe(4.50)
        ->and($res->data['preis']['gesamt_vk'])->toBe(450.00)               // 4,50 × 100 Pax
        ->and($res->data['kapitel'][0]['titel'])->toBe('Menü')
        ->and($res->data['kapitel'][0]['blocks'][0]['name'])->toBe('Grill-Buffet');

    $miss = $this->registry->get('foodalchemist.foodbook.GET')->execute(['id' => 999999], $this->kontext);
    expect($miss->success)->toBeFalse()->and($miss->errorCode)->toBe('NOT_FOUND');
});

it('Schreib-Tool: ohne accept nur Vorschlag; accept=true schreibt via GL-07; manual blockt als Tool-Error', function () {
    $hg = FoodAlchemistDishMainGroup::create(['code' => 'HG', 'bezeichnung' => 'Hauptgang']);
    $klasse = FoodAlchemistDishClass::create(['dish_main_group_id' => $hg->id, 'code' => 'HG_F', 'bezeichnung' => 'Fleisch', 'diaetform' => 'fleisch']);
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk2', 'name' => 'HG: Filet', 'status' => 'draft',
        'ist_verkaufsrezept' => true, 'speisen_klasse_id' => $klasse->id,  // Kontext fürs Fake-Echo
    ]);
    $tool = $this->registry->get('foodalchemist.recipe_klasse.POST');

    $nurVorschlag = $tool->execute(['recipe_id' => $vk->id], $this->kontext);
    expect($nurVorschlag->success)->toBeTrue()
        ->and($nurVorschlag->data['klasse_id'])->toBe($klasse->id)
        ->and($nurVorschlag->data['accepted'])->toBeFalse();

    $vk->update(['speisen_klasse_id' => null]);
    $mitAccept = $tool->execute(['recipe_id' => $vk->id, 'accept' => true], $this->kontext);
    // Fake-Echo ohne gesetzte Klasse ⇒ ehrlicher Nicht-Treffer, accepted bleibt false
    expect($mitAccept->data['accepted'])->toBeFalse();

    $vk->update(['speisen_klasse_id' => $klasse->id]);
    $echterAccept = $tool->execute(['recipe_id' => $vk->id, 'accept' => true], $this->kontext);
    expect($echterAccept->data['accepted'])->toBeTrue()
        ->and($vk->fresh()->speisen_klasse_quelle)->toBe('ki');       // GL-07-Lineage, nie Direkt-Write

    $vk->update(['speisen_klasse_quelle' => 'manual']);
    $geblockt = $tool->execute(['recipe_id' => $vk->id, 'accept' => true], $this->kontext);
    expect($geblockt->success)->toBeFalse()
        ->and($geblockt->errorCode)->toBe('OVERRIDE_FIRST');
});
