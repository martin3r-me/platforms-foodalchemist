<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\SpeiseplanEintraegeGetTool;
use Platform\FoodAlchemist\Tools\SpeiseplanEintraegePostTool;
use Platform\FoodAlchemist\Tools\SpeiseplanEintraegePutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 2026-10-07: Darreichung je Speiseplan-Eintrag + EK je Portion + Typ-Guards.
 *
 * Vorher: der Eintrag konnte keine Form wählen (VK immer Standard) und der EK war
 * `ek_total_eur` — der ganze Ansatz. Bei 10 Portionen je Ansatz stand der EK zehnfach im
 * Wareneinsatz. Außerdem kam über MCP ein Basisrezept in den Plan, und Darreichungen ließen
 * sich an Basisrezepten anlegen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($this->user);
    $this->svc = app(SpeiseplanService::class);

    // Ansatz: 10 Portionen, EK des Ansatzes 20,00 € ⇒ 2,00 € je Portion.
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'bratkartoffeln', 'name' => 'Bratkartoffeln', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 4.00, 'ek_total_eur' => 20.00, 'sales_unit_count' => 10,
    ]);
    $teller = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    $kind = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'kinderportion', 'label' => 'Kinderportion']);
    $this->standard = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $teller->id, 'is_standard' => true,
        'quantity_per_unit_g' => 250, 'unit_count' => 1, 'ek_portion' => 2.00, 'sales_net' => 4.00, 'price_mode' => 'auto',
    ]);
    $this->kinder = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $kind->id, 'is_standard' => false,
        'quantity_per_unit_g' => 150, 'unit_count' => 1, 'ek_portion' => 1.20, 'sales_net' => 2.80, 'price_mode' => 'auto',
    ]);

    $this->basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'kartoffelpueree', 'name' => 'Kartoffelpüree', 'status' => 'approved',
        'is_sales_recipe' => false, 'ek_total_eur' => 15.00, 'yield_kg' => 5,
    ]);

    $this->plan = $this->svc->create($this->rootTeam, ['name' => 'KW 41', 'zyklus_wochen' => 1, 'start_date' => '2026-10-05']);
    $this->mo = '2026-10-05';
});

it('EK je Person ist die Portion (ek_portion der Standard-Form), nicht der ganze Ansatz', function () {
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $this->gericht->id]);

    $preis = $this->svc->eintragPreis($e->fresh());
    expect($preis['vk'])->toBe(4.0)
        ->and($preis['ek'])->toBe(2.0);                              // vorher 20,00 (Ansatz)
});

it('ohne Darreichung: EK = Ansatz ÷ Portionen (Fallback wie Paket)', function () {
    $ohne = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'suppe', 'name' => 'Suppe', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 3.00, 'ek_total_eur' => 8.00, 'sales_unit_count' => 8,
    ]);
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $ohne->id]);

    expect($this->svc->eintragPreis($e->fresh())['ek'])->toBe(1.0);
});

it('gewählte Darreichung bestimmt VK, EK und Portionsgewicht der Zelle', function () {
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $this->gericht->id]);
    $this->svc->setEintragDarreichung($this->rootTeam, $e->id, $this->kinder->id);

    $preis = $this->svc->eintragPreis($e->fresh());
    expect($preis['vk'])->toBe(2.8)->and($preis['ek'])->toBe(1.2);

    $zk = $this->svc->zellenKennzahlen($this->rootTeam, $this->plan->fresh(), 'mittag', \Illuminate\Support\Carbon::parse($this->mo));
    expect($zk['eintraege'][$e->id]['portion_g'])->toBe(150.0)
        ->and($zk['eintraege'][$e->id]['darreichung'])->toBe('Kinderportion');

    // Zurück auf Standard
    $this->svc->setEintragDarreichung($this->rootTeam, $e->id, null);
    expect($e->fresh()->presentation_id)->toBeNull()
        ->and($this->svc->eintragPreis($e->fresh())['vk'])->toBe(4.0);
});

it('fremde Darreichung wird abgelehnt', function () {
    $anderes = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'x', 'name' => 'Anderes Gericht', 'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $anderes->id]);

    expect(fn () => $this->svc->setEintragDarreichung($this->rootTeam, $e->id, $this->kinder->id))
        ->toThrow(\RuntimeException::class, 'gehört nicht zum Gericht');
    expect(fn () => $this->svc->addEintrag($this->rootTeam, $this->plan->id, [
        'entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $anderes->id, 'presentation_id' => $this->kinder->id,
    ]))->toThrow(\RuntimeException::class, 'gehört nicht zum Gericht');
});

it('Ersetzen setzt die Darreichung zurück, Kopieren übernimmt sie', function () {
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, [
        'entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $this->gericht->id, 'presentation_id' => $this->kinder->id,
    ]);
    expect($e->presentation_id)->toBe($this->kinder->id);

    expect($this->svc->kopiereEintrag($this->rootTeam, $e->id, ['2026-10-06']))->toBe(1);
    $kopie = $this->plan->entries()->whereDate('entry_date', '2026-10-06')->first();
    expect((int) $kopie->presentation_id)->toBe($this->kinder->id);

    $res = $this->svc->kopiereWoche($this->rootTeam, $this->plan->id, $this->mo, '2026-10-12', false, true);
    expect($res['kopiert'])->toBe(2)
        ->and($this->plan->entries()->whereDate('entry_date', '2026-10-12')->first()->presentation_id)->toBe($this->kinder->id);

    $anderes = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'y', 'name' => 'Rahmspinat', 'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    $this->svc->ersetzeEintrag($this->rootTeam, $e->id, ['sales_recipe_id' => $anderes->id]);
    expect($e->fresh()->presentation_id)->toBeNull();
});

it('Duplizieren eines Plans übernimmt die Darreichung', function () {
    $this->svc->addEintrag($this->rootTeam, $this->plan->id, [
        'entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $this->gericht->id, 'presentation_id' => $this->kinder->id,
    ]);
    $kopie = $this->svc->dupliziere($this->rootTeam, $this->plan->id);

    expect((int) $kopie->entries()->first()->presentation_id)->toBe($this->kinder->id);
});

it('Speiseplan nimmt kein Basisrezept an (auch nicht über den Service)', function () {
    expect(fn () => $this->svc->addEintrag($this->rootTeam, $this->plan->id, [
        'entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $this->basis->id,
    ]))->toThrow(\RuntimeException::class, 'kein Gericht');
});

it('Darreichungen gibt es nur an Gerichten', function () {
    $form = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'buffet', 'label' => 'Buffet']);

    expect(fn () => app(DarreichungService::class)->anlegen($this->rootTeam, $this->basis->id, $form->id))
        ->toThrow(\RuntimeException::class, 'Basisrezept')
        ->and(app(DarreichungService::class)->ensureStandard($this->rootTeam, $this->basis->id))->toBeNull()
        ->and($this->basis->presentations()->count())->toBe(0);
});

it('MCP: POST/PUT setzen presentation_id, GET liefert Form und Preis je Portion', function () {
    $ctx = new ToolContext($this->user, $this->rootTeam);

    $post = app(SpeiseplanEintraegePostTool::class)->execute([
        'menu_plan_id' => $this->plan->id, 'entry_date' => $this->mo, 'sales_recipe_id' => $this->gericht->id,
        'presentation_id' => $this->kinder->id,
    ], $ctx);
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''))
        ->and($post->data['eintrag']['presentation_id'])->toBe($this->kinder->id);
    $id = (int) $post->data['eintrag']['id'];

    $get = app(SpeiseplanEintraegeGetTool::class)->execute(['plan_id' => $this->plan->id], $ctx);
    expect($get->success)->toBeTrue('get: ' . ($get->error ?? ''));
    $zeile = collect($get->data['eintraege'])->firstWhere('id', $id);
    expect($zeile['darreichung'])->toBe('Kinderportion')
        ->and($zeile['vk'])->toBe(2.8)
        ->and($zeile['ek'])->toBe(1.2);

    $put = app(SpeiseplanEintraegePutTool::class)->execute(['eintrag_id' => $id, 'presentation_id' => 0], $ctx);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''))
        ->and($put->data['geaendert'])->toBe(['darreichung'])
        ->and($put->data['eintrag']['presentation_id'])->toBeNull();

    $basis = app(SpeiseplanEintraegePostTool::class)->execute([
        'menu_plan_id' => $this->plan->id, 'entry_date' => $this->mo, 'sales_recipe_id' => $this->basis->id,
    ], $ctx);
    expect($basis->success)->toBeFalse();
});

it('UI: Detail-Panel zeigt die Form-Wahl und setzt die Darreichung', function () {
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $this->gericht->id]);

    Livewire::test(Editor::class)
        ->call('oeffnenBearbeiten', $this->plan->id)
        ->call('eintragOeffnen', $e->id)
        ->assertSeeHtml('data-sp-darreichung')
        ->assertSee('Kinderportion')
        ->call('eintragDarreichungSetzen', $e->id, (string) $this->kinder->id);

    expect((int) $e->fresh()->presentation_id)->toBe($this->kinder->id);
});

it('VK-Modal: Einstieg aus dem Basisrezept belegt die Anlage vor', function () {
    Livewire::test(VkModal::class)
        ->call('oeffnen', null, false, $this->basis->id)
        ->assertSet('basisId', $this->basis->id)
        ->assertSet('neuName', 'Kartoffelpüree')
        ->assertSet('recipeId', null);
});

it('Einträge ohne Verkaufspreis zählen nicht in den Wareneinsatz (EK-Summe schon)', function () {
    // Vorher: Bananenbrot ohne Preis, EK 141 € (Ansatz = eine Portion) ⇒ 16.100 % Wareneinsatz am Tag.
    $bananenbrot = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'bananenbrot', 'name' => 'Bananenbrot', 'status' => 'approved',
        'is_sales_recipe' => true, 'ek_total_eur' => 141.43, 'yield_kg' => 8.2,
    ]);
    $a = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $bananenbrot->id]);
    $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $this->gericht->id]);

    // Konvention: ohne Portionszahl ist das Gericht EINE Portion (wie DarreichungService::recomputePreise).
    expect($this->svc->eintragPreis($a->fresh())['ek'])->toBe(141.43);

    $zk = $this->svc->zellenKennzahlen($this->rootTeam, $this->plan->fresh(), 'mittag', \Illuminate\Support\Carbon::parse($this->mo));
    expect($zk['eintraege'][$a->id]['wes'])->toBeNull()
        // Tages-WE nur aus dem Eintrag mit Preis: 2,00 / 4,00 = 50 %
        ->and($zk['tage'][$this->mo]['wes'])->toBe(50.0)
        ->and($zk['tage'][$this->mo]['ohne_preis'])->toBe(1)
        // die EK-Summe enthält das Bananenbrot weiterhin (Kosten sind real)
        ->and($zk['tage'][$this->mo]['ek'])->toBe(round((141.43 + 2.0) * 100, 2));
});

it('UI: Darreichung steht auch bei nur einer Form, mit Weg zum Gericht', function () {
    $einzeln = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'waffel', 'name' => 'Butterwaffeln', 'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $einzeln->id, 'serving_form_id' => $this->standard->serving_form_id, 'is_standard' => true,
        'quantity_per_unit_g' => 220, 'unit_count' => 1, 'ek_portion' => 1.56, 'sales_net' => 5.61, 'price_mode' => 'auto',
    ]);
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $einzeln->id]);

    Livewire::test(Editor::class)
        ->call('oeffnenBearbeiten', $this->plan->id)
        ->call('eintragOeffnen', $e->id)
        ->assertSeeHtml('data-sp-darreichung-einzig')
        ->assertSee('220 g')
        ->assertSeeHtml('data-sp-darreichung-anlegen');
});
