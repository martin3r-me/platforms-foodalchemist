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

// ── Spec 19 E3.5: Zielgruppen-Vokabular (GET/POST) + Foodbook-Defaults ──

it('E3.5: zielgruppen.POST/GET-Roundtrip — anlegen, listen, Dedup + Leername als VALIDATION_ERROR', function () {
    $post = $this->registry->get('foodalchemist.zielgruppen.POST');
    $get = $this->registry->get('foodalchemist.zielgruppen.GET');

    $res = $post->execute(['name' => 'VIP-Gala', 'description' => 'Gehobenes Publikum', 'sort_order' => 5], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['zielgruppe']['name'])->toBe('VIP-Gala')
        ->and($res->data['zielgruppe']['sort_order'])->toBe(5);
    $id = $res->data['zielgruppe']['id'];

    $liste = $get->execute([], $this->kontext);
    expect($liste->success)->toBeTrue()
        ->and(collect($liste->data['zielgruppen'])->firstWhere('id', $id))->not->toBeNull()
        ->and(collect($liste->data['zielgruppen'])->firstWhere('id', $id)['is_owned'])->toBeTrue();

    // Dedup (case-insensitiv) im eigenen Team
    $dup = $post->execute(['name' => 'vip-gala'], $this->kontext);
    expect($dup->success)->toBeFalse()->and($dup->errorCode)->toBe('VALIDATION_ERROR');

    // Leerer Name
    $leer = $post->execute(['name' => '   '], $this->kontext);
    expect($leer->success)->toBeFalse()->and($leer->errorCode)->toBe('VALIDATION_ERROR');
});

it('E3.5: foodbooks.POST setzt Bedarf-Defaults + Zielgruppen/Einsatzmomente; foodbook.GET spiegelt sie', function () {
    $et = \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::create(['team_id' => $this->rootTeam->id, 'name' => 'Gala']);
    $sf = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'label' => 'Buffet', 'code' => 'buffet']);
    $em = \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::create(['team_id' => $this->rootTeam->id, 'name' => 'Apéro']);
    $zg = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::create(['team_id' => $this->rootTeam->id, 'name' => 'Bankett-Gast']);

    $post = $this->registry->get('foodalchemist.foodbooks.POST');
    $res = $post->execute([
        'label' => 'Bedarf-Defaults-Test', 'personen' => 80,
        'default_event_type_id' => $et->id, 'default_serving_form_id' => $sf->id,
        'target_food_cost_pct' => 30, 'food_cost_tolerance_pp' => 5,
        'zielgruppen' => [$zg->id], 'einsatzmomente' => [$em->id],
    ], $this->kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['foodbook']['default_event_type_id'])->toBe($et->id)
        ->and($res->data['foodbook']['default_serving_form_id'])->toBe($sf->id)
        ->and($res->data['foodbook']['zielgruppen_ids'])->toBe([$zg->id])
        ->and($res->data['foodbook']['service_moment_ids'])->toBe([$em->id]);

    $get = $this->registry->get('foodalchemist.foodbook.GET')->execute(['id' => $res->data['foodbook']['id']], $this->kontext);
    expect($get->success)->toBeTrue()
        ->and($get->data['defaults']['event_type_id'])->toBe($et->id)
        ->and($get->data['defaults']['serving_form_id'])->toBe($sf->id)
        ->and((float) $get->data['defaults']['target_food_cost_pct'])->toBe(30.0)
        ->and($get->data['defaults']['service_moment_ids'])->toBe([$em->id])
        ->and(collect($get->data['defaults']['zielgruppen'])->pluck('id')->all())->toBe([$zg->id]);
});

it('E3.5: Tenancy — foodbooks.POST mit fremd-Team-Zielgruppe blockt (NOT_FOUND), nichts wird angelegt', function () {
    // Zielgruppe gehört Kind A — für Root (Ancestry aufwärts) NICHT sichtbar.
    $fremd = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::create(['team_id' => $this->childA->id, 'name' => 'Fremd-Gruppe']);
    $vorher = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::count();

    $res = $this->registry->get('foodalchemist.foodbooks.POST')->execute([
        'label' => 'Tenancy-Foodbook', 'zielgruppen' => [$fremd->id],
    ], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
    // Guard läuft VOR create() → kein verwaistes Foodbook.
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::count())->toBe($vorher);

    // Fremd-Team-Zielgruppe erscheint auch nicht in der GET-Liste des Root-Teams.
    $liste = $this->registry->get('foodalchemist.zielgruppen.GET')->execute([], $this->kontext);
    expect(collect($liste->data['zielgruppen'])->firstWhere('id', $fremd->id))->toBeNull();
});

// ── Spec 19 E4.6: foodbook_kapitel.PUT (Ziele+Zielgruppen+pricing_mode) + coverage.GET-WE ──

it('E4.6: foodbook_kapitel.PUT setzt SOLL-Ziele + pricing_mode + Zielgruppen; kapitelZiele spiegelt sie', function () {
    $foodbooks = app(\Platform\FoodAlchemist\Services\FoodbookService::class);
    $sf = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'label' => 'Buffet', 'code' => 'buffet']);
    $zg = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::create(['team_id' => $this->rootTeam->id, 'name' => 'Bankett-Gast']);
    $fb = $foodbooks->create($this->rootTeam, ['label' => 'Ziele-PUT', 'personen' => 100]);
    $kap = $foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Hauptgänge']);

    $put = $this->registry->get('foodalchemist.foodbook_kapitel.PUT');
    $res = $put->execute([
        'kapitel_id' => $kap->id,
        'target_count' => 6, 'price_anchor' => 24.00, 'price_min' => 20.00, 'price_max' => 30.00,
        'serving_form_id' => $sf->id, 'pricing_mode' => 'paket', 'target_food_cost_pct' => 28.5,
        'zielgruppen' => [$zg->id],
    ], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['kapitel']['target_count'])->toBe(6)
        ->and($res->data['kapitel']['pricing_mode'])->toBe('paket')
        ->and($res->data['kapitel']['serving_form_id'])->toBe($sf->id)
        ->and($res->data['kapitel']['zielgruppen_ids'])->toBe([$zg->id]);

    // Ziel-Kaskade sieht die frisch gesetzten SOLL-Werte (Quelle = Kapitel).
    $ziele = $foodbooks->kapitelZiele($this->rootTeam, $kap->fresh());
    expect($ziele['target_count'])->toBe(6)
        ->and((float) $ziele['price_anchor'])->toBe(24.0)
        ->and($ziele['quellen']['target_count'])->toBe('kapitel');

    // Zielgruppen-PUT ist sync: erneutes PUT mit [] leert die Liste.
    $leer = $put->execute(['kapitel_id' => $kap->id, 'zielgruppen' => []], $this->kontext);
    expect($leer->data['kapitel']['zielgruppen_ids'])->toBe([]);

    // pricing_mode-Enum wird VOR dem Write geprüft.
    $bad = $put->execute(['kapitel_id' => $kap->id, 'pricing_mode' => 'quatsch'], $this->kontext);
    expect($bad->success)->toBeFalse()->and($bad->errorCode)->toBe('VALIDATION_ERROR');
});

it('E4.6: foodbook_kapitel.PUT Tenancy — fremd-Team-Zielgruppe blockt (NOT_FOUND), Kapitel unverändert', function () {
    $foodbooks = app(\Platform\FoodAlchemist\Services\FoodbookService::class);
    $fremd = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::create(['team_id' => $this->childA->id, 'name' => 'Fremd-Gruppe']);
    $fb = $foodbooks->create($this->rootTeam, ['label' => 'Tenancy-Ziele', 'personen' => 50]);
    $kap = $foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Kap']);

    $res = $this->registry->get('foodalchemist.foodbook_kapitel.PUT')->execute([
        'kapitel_id' => $kap->id, 'target_count' => 4, 'zielgruppen' => [$fremd->id],
    ], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
    // Guard läuft VOR jedem Write → weder Zielgruppe verknüpft noch target_count gesetzt.
    expect($kap->fresh()->target_count)->toBeNull()
        ->and($kap->targetGroups()->count())->toBe(0);
});

it('E4.6: coverage.GET liefert bei Foodbook eine wareneinsatz[]-Sektion je Kapitel', function () {
    $foodbooks = app(\Platform\FoodAlchemist\Services\FoodbookService::class);
    $frames = app(\Platform\FoodAlchemist\Services\PlanningFrameService::class);
    $fb = $foodbooks->create($this->rootTeam, ['label' => 'WE-Coverage', 'personen' => 100]);
    $kap = $foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Hauptgänge']);
    // Gerüst nötig, damit coverage hat_geruest=true liefert (sonst Kurzschluss im Tool).
    $frame = $frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    $frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]);

    $res = $this->registry->get('foodalchemist.coverage.GET')->execute([
        'owner_type' => 'foodbook', 'owner_id' => $fb->id,
    ], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['hat_geruest'])->toBeTrue()
        ->and($res->data)->toHaveKey('wareneinsatz');
    $we = collect($res->data['wareneinsatz'])->firstWhere('chapter_id', $kap->id);
    // Ohne bepreiste Blöcke: IST unbekannt → status 'unbekannt', nicht partiell.
    expect($we)->not->toBeNull()
        ->and($we['status'])->toBe('unbekannt')
        ->and($we['partiell'])->toBeFalse();
});

// ── Spec 19 E5.4: leitstelle.GET (Checkliste + Kapitel-Matrix + Kapitel-Stand) ──

it('E5.4: leitstelle.GET ohne chapter_id liefert Checkliste (7 Schritte) + Kapitel-Matrix', function () {
    $foodbooks = app(\Platform\FoodAlchemist\Services\FoodbookService::class);
    $fb = $foodbooks->create($this->rootTeam, ['label' => 'Leitstelle-Überblick', 'personen' => 100]);
    $kap = $foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Hauptgänge']);

    $res = $this->registry->get('foodalchemist.leitstelle.GET')->execute([
        'foodbook_id' => $fb->id,
    ], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['foodbook']['id'])->toBe($fb->id)
        ->and($res->data['foodbook']['personen'])->toBe(100)
        ->and($res->data['checkliste'])->toHaveCount(7)
        ->and(collect($res->data['checkliste'])->pluck('key')->all())
        ->toBe(['bedarf', 'struktur', 'tiefe', 'kapitel_aufbau', 'kreativ', 'anlegen', 'preise'])
        // Struktur = erledigt (1 Kapitel vorhanden); Kapitel-Matrix listet es.
        ->and(collect($res->data['checkliste'])->firstWhere('key', 'struktur')['status'])->toBe('erledigt')
        ->and($res->data)->toHaveKey('kapitel_matrix')
        ->and($res->data)->not->toHaveKey('kapitel');
    $row = collect($res->data['kapitel_matrix'])->firstWhere('kapitel_id', $kap->id);
    expect($row)->not->toBeNull()
        ->and($row['hat_inhalt'])->toBeFalse()
        ->and($row['wareneinsatz']['status'])->toBe('unbekannt');
});

it('E5.4: leitstelle.GET mit chapter_id liefert Kapitel-Stand (Ziele/Zielgruppen/WE) + Coverage-Befunde', function () {
    $foodbooks = app(\Platform\FoodAlchemist\Services\FoodbookService::class);
    $frames = app(\Platform\FoodAlchemist\Services\PlanningFrameService::class);
    $zg = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::create(['team_id' => $this->rootTeam->id, 'name' => 'Bankett-Gast']);
    $fb = $foodbooks->create($this->rootTeam, ['label' => 'Leitstelle-Kapitel', 'personen' => 100]);
    $kap = $foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Hauptgänge']);
    $foodbooks->updateKapitel($this->rootTeam, $kap->id, ['target_count' => 5]);
    $foodbooks->setKapitelZielgruppen($this->rootTeam, $kap->id, [$zg->id]);
    // Gerüst → Coverage misst; Kapitel-Mengenziel erzeugt einen chapter_id-Befund (E4.3).
    $frame = $frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    $frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]);

    $res = $this->registry->get('foodalchemist.leitstelle.GET')->execute([
        'foodbook_id' => $fb->id, 'chapter_id' => $kap->id,
    ], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['kapitel']['kapitel_id'])->toBe($kap->id)
        ->and($res->data['kapitel']['ziele']['target_count'])->toBe(5)
        ->and(collect($res->data['kapitel']['zielgruppen'])->pluck('id')->all())->toBe([$zg->id])
        ->and($res->data['kapitel'])->toHaveKey('wareneinsatz')
        ->and($res->data['kapitel'])->toHaveKey('coverage_befunde')
        // Überblicks-Sektion (Matrix) entfällt im Kapitel-Modus.
        ->and($res->data)->not->toHaveKey('kapitel_matrix');
});

it('E5.4: leitstelle.GET Tenancy — Foodbook eines fremden Teams + fremdes Kapitel blocken (NOT_FOUND)', function () {
    $foodbooks = app(\Platform\FoodAlchemist\Services\FoodbookService::class);
    // Fremd-Team-Foodbook: für rootTeam nicht sichtbar (childA ist NICHT Vorfahr von root).
    $fremdFb = $foodbooks->create($this->childA, ['label' => 'Fremd', 'personen' => 20]);
    $tool = $this->registry->get('foodalchemist.leitstelle.GET');

    $res = $tool->execute(['foodbook_id' => $fremdFb->id], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');

    // Eigenes Foodbook, aber chapter_id gehört zu einem anderen (fremden) Foodbook → NOT_FOUND.
    $meinFb = $foodbooks->create($this->rootTeam, ['label' => 'Mein', 'personen' => 30]);
    $fremdKap = $foodbooks->addKapitel($this->childA, $fremdFb->id, ['title' => 'Fremd-Kap']);
    $res2 = $tool->execute(['foodbook_id' => $meinFb->id, 'chapter_id' => $fremdKap->id], $this->kontext);
    expect($res2->success)->toBeFalse()->and($res2->errorCode)->toBe('NOT_FOUND');
});
