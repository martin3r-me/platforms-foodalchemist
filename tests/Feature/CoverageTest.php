<?php

use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\CoverageService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R4.2 — Soll/Ist-Coverage: Gerüst-Soll gegen Foodbook-/Konzept-Ist, Ampel-Logik
 * erfüllt/teilerfüllt/verletzt. DoD-Test: absichtlich verletztes Gerüst zeigt EXAKT
 * die erwarteten Warnungen (Positiv- + Negativ-Test).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(CoverageService::class);
    $this->frames = app(PlanningFrameService::class);

    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $this->klasseVegan = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_V', 'label' => 'Vegan', 'diet_form' => 'vegan']);
    $this->klasseFleisch = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_F', 'label' => 'Fleisch', 'diet_form' => 'fleisch']);

    $this->gerichtVegan = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vegan-bowl', 'name' => 'HG: Gemüse-Bowl', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 12.00, 'dish_class_id' => $this->klasseVegan->id,
        'allergen_peanuts' => 'nicht_enthalten',
    ]);
    $this->gerichtFleisch = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'kalbsleber', 'name' => 'HG: Kalbsleber Berliner Art', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 18.00, 'dish_class_id' => $this->klasseFleisch->id,
        'allergen_peanuts' => 'enthalten',
    ]);

    // Konzept mit einem Slot „Hauptgang", beide Gerichte gesetzt
    $concepts = app(ConceptService::class);
    $this->concept = $concepts->create($this->rootTeam, ['name' => 'Test-Buffet']);
    $s1 = $concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Hauptgang']);
    $concepts->fillSlot($this->rootTeam, $s1->id, ['sales_recipe_id' => $this->gerichtVegan->id]);
    $s2 = $concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Hauptgang']);
    $concepts->fillSlot($this->rootTeam, $s2->id, ['sales_recipe_id' => $this->gerichtFleisch->id]);
});

it('ohne Gerüst: hat_geruest=false, keine Befunde', function () {
    $cov = $this->svc->coverage($this->rootTeam, 'concept', $this->concept->id);
    expect($cov['hat_geruest'])->toBeFalse()->and($cov['befunde'])->toBe([]);
});

it('Positiv-Test: erfülltes Gerüst zeigt NUR grüne Ampeln', function () {
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $this->concept->id);
    $slot = $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 2, 'is_pflicht' => true]);
    $this->frames->addRule($this->rootTeam, $frame, ['slot_id' => $slot->id, 'rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 1, 'unit' => 'count']);
    $this->frames->addRule($this->rootTeam, $frame, ['rule_type' => 'nogo_ingredient', 'value_text' => 'Aal']);

    $cov = $this->svc->coverage($this->rootTeam, 'concept', $this->concept->id);

    expect($cov['hat_geruest'])->toBeTrue()
        ->and($cov['ampel_gesamt'])->toBe('erfuellt')
        ->and($cov['zusammenfassung']['verletzt'])->toBe(0)
        ->and(collect($cov['befunde'])->pluck('ampel')->unique()->values()->all())->toBe(['erfuellt']);
});

it('Negativ-Test: absichtlich verletztes Gerüst zeigt exakt die erwarteten Warnungen', function () {
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $this->concept->id);
    // 1) Menge: 5 gefordert, nur 2 da → teilerfüllt
    $slot = $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 5]);
    // 2) Pflicht-Slot „Dessert" ohne Ist-Bezug → verletzt (Dramaturgie)
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Dessert', 'slot_type' => 'gang', 'is_pflicht' => true]);
    // 3) Diät-Quote: mind. 2× vegan, nur 1 → teilerfüllt
    $this->frames->addRule($this->rootTeam, $frame, ['slot_id' => $slot->id, 'rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 2, 'unit' => 'count']);
    // 4) No-Go „Leber" → verletzt (Kalbsleber im Ist)
    $this->frames->addRule($this->rootTeam, $frame, ['rule_type' => 'nogo_ingredient', 'value_text' => 'Leber']);
    // 5) No-Go-Allergen peanuts → verletzt (Kalbsleber enthält)
    $this->frames->addRule($this->rootTeam, $frame, ['rule_type' => 'nogo_allergen', 'ref_key' => 'peanuts']);

    $cov = $this->svc->coverage($this->rootTeam, 'concept', $this->concept->id);
    $byLabel = collect($cov['befunde'])->keyBy('label');

    expect($cov['ampel_gesamt'])->toBe('verletzt')
        ->and($byLabel['Slot „Hauptgang“']['dimension'])->toBe('menge')
        ->and($byLabel['Slot „Hauptgang“']['ampel'])->toBe('teilerfuellt')
        ->and($byLabel['Slot „Hauptgang“']['ist'])->toBe('2 Gerichte')
        ->and($byLabel['Slot „Dessert“']['dimension'])->toBe('dramaturgie')
        ->and($byLabel['Slot „Dessert“']['ampel'])->toBe('verletzt')
        ->and($byLabel['Slot „Hauptgang“: Diät-Quote vegan']['ampel'])->toBe('teilerfuellt')
        ->and($byLabel['Slot „Hauptgang“: Diät-Quote vegan']['fill_filter'])->toBe(['diet_form' => 'vegan', 'slot_label' => 'Hauptgang'])
        ->and($byLabel['No-Go „Leber“']['ampel'])->toBe('verletzt')
        ->and($byLabel['No-Go-Allergen peanuts']['ampel'])->toBe('verletzt')
        ->and($this->svc->hatRoteAmpeln($this->rootTeam, 'concept', $this->concept->id))->toBeTrue();
});

it('Preis-Dimension: Ist über der Spanne → verletzt, innerhalb → erfüllt', function () {
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $this->concept->id);
    // Ist p. P. = 12 + 18 = 30 €
    $this->frames->setHead($this->rootTeam, $frame, ['target_price_pp' => 25, 'price_max_pp' => 28]);
    $cov = $this->svc->coverage($this->rootTeam, 'concept', $this->concept->id);
    $preis = collect($cov['befunde'])->firstWhere('dimension', 'preis');
    expect($preis['ampel'])->toBe('verletzt')->and($preis['hinweis'])->toContain('Über der Preisspanne');

    $this->frames->setHead($this->rootTeam, $frame, ['target_price_pp' => 30, 'price_max_pp' => 35]);
    $cov = $this->svc->coverage($this->rootTeam, 'concept', $this->concept->id);
    $preis = collect($cov['befunde'])->firstWhere('dimension', 'preis');
    expect($preis['ampel'])->toBe('erfuellt');
});

it('Saison-Abdeckung: fehlende Soll-Saison → verletzt, gesetzte → erfüllt', function () {
    $sommer = FoodAlchemistSaison::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommer']);
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $this->concept->id);
    $this->frames->addRule($this->rootTeam, $frame, ['rule_type' => 'season_coverage', 'ref_id' => $sommer->id]);

    $cov = $this->svc->coverage($this->rootTeam, 'concept', $this->concept->id);
    expect(collect($cov['befunde'])->firstWhere('dimension', 'saison')['ampel'])->toBe('verletzt');

    app(ConceptService::class)->syncSaisons($this->rootTeam, $this->concept->id, [$sommer->id]);
    $cov = $this->svc->coverage($this->rootTeam, 'concept', $this->concept->id);
    expect(collect($cov['befunde'])->firstWhere('dimension', 'saison')['ampel'])->toBe('erfuellt');
});

it('Live-UI: Coverage-Panel im Concepter sichtbar, Lücken-Klick setzt den Diät-Filter des Pickers', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);

    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $this->concept->id);
    $slot = $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'target_count' => 5]);
    $this->frames->addRule($this->rootTeam, $frame, ['slot_id' => $slot->id, 'rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 2, 'unit' => 'count']);

    $comp = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Concepter\Editor::class)
        ->call('oeffnen', 'concepts', $this->concept->id)
        ->assertSee('Soll/Ist-Coverage')
        ->assertSee('Diät-Quote vegan');

    // Lücken-Klick → Aufbau-Tab + Diät-Filter gesetzt → Kandidaten nur vegan
    $comp->call('coverageFuellen', 'vegan')
        ->assertSet('tab', 'aufbau')
        ->assertSet('pickDiaet', 'vegan');
    $kandidaten = app(\Platform\FoodAlchemist\Services\PaketService::class)
        ->gerichtKandidaten($this->rootTeam, '', ['diet_form' => 'vegan']);
    expect($kandidaten->pluck('name')->all())->toBe(['HG: Gemüse-Bowl']);
});

it('Foodbook-Ist: Kapitel-Match über chapter_id und Label, Menge zählt Concept-Gerichte', function () {
    $foodbooks = app(FoodbookService::class);
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'FB 2027']);
    $kapitel = $fb->chapters()->create(['team_id' => $this->rootTeam->id, 'title' => 'Hauptgänge', 'position' => 0]);
    $kapitel->blocks()->create(['team_id' => $this->rootTeam->id, 'type' => 'concept_ref', 'concept_id' => $this->concept->id, 'position' => 0, 'visible' => true]);

    $frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    // Slot A: expliziter Kapitel-Verweis; Slot B: Label-Match; Slot C: kein Bezug
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Irgendwas', 'chapter_id' => $kapitel->id, 'target_count' => 2]);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgänge', 'target_count' => 3]);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Fehlt komplett', 'target_count' => 1]);

    $cov = $this->svc->coverage($this->rootTeam, 'foodbook', $fb->id);
    $byLabel = collect($cov['befunde'])->keyBy('label');

    expect($byLabel['Slot „Irgendwas“']['ampel'])->toBe('erfuellt')           // 2/2 via chapter_id
        ->and($byLabel['Slot „Hauptgänge“']['ampel'])->toBe('teilerfuellt')   // 2/3 via Label
        ->and($byLabel['Slot „Fehlt komplett“']['dimension'])->toBe('dramaturgie')
        ->and($byLabel['Slot „Fehlt komplett“']['ist'])->toBe('kein Ist-Bezug');
});
