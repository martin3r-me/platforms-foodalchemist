<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index as SpeisekarteIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Speisekarte-Editor (Stufe A) — Livewire-Smoke: anlegen, Rubrik, Gericht-Position
 * über den Picker, Voll-Page-Render gegen platform::layouts.app.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'skui1', 'name' => 'Wiener Schnitzel', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 18.90, 'ek_total_eur' => 6.00,
    ]);
});

it('Speisekarte-Editor: anlegen, Rubrik, Gericht-Position über Picker', function () {
    Livewire::test(SpeisekarteIndex::class)->assertOk()->call('neu');
    $karte = FoodAlchemistSpeisekarte::first();
    expect($karte)->not->toBeNull();

    $comp = Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->set('name', 'Abendkarte')
        ->set('kartenTyp', 'alacarte')
        ->call('speichern')
        ->set('neueRubrik', 'Hauptgänge')
        ->call('rubrikNeu');

    $rubrik = $karte->sections()->first();
    expect($rubrik)->not->toBeNull()->and($rubrik->title)->toBe('Hauptgänge');

    $comp->call('pickerOeffnen', $rubrik->id)
        ->set('pickerSuche', 'Schnitzel')
        ->call('positionAusGericht', $rubrik->id, $this->gericht->id)
        ->assertOk()
        ->assertSee('Wiener Schnitzel')
        ->assertSee('18,90')
        // Rechtes Detail-Panel (read-only Info): Blöcke + Eckdaten der Auswahl
        ->assertSee('Eckdaten')
        ->assertSee('Kartentyp')
        ->assertSee('Erstellt');

    expect($rubrik->items()->count())->toBe(1);
    expect($karte->refresh()->name)->toBe('Abendkarte');
});

// ── Werkstrang M Phase A (Spec 40 §6): Kontext-Leitplanken ────────────────────

it('Phase A: Kontext-Leitplanken werden gesetzt + persistiert (waehle hydriert, speichern schreibt)', function () {
    $ws = \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'nuechtern', 'name' => 'Nüchtern', 'sprach_duktus' => 'sachlich',
    ]);
    Livewire::test(SpeisekarteIndex::class)->call('neu');
    $karte = FoodAlchemistSpeisekarte::first();

    Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->set('kundentyp', 'Business-Lunch')
        ->set('niveau', 'gehoben')
        ->set('convenience', 'teil_convenience')
        ->set('writingStyleId', $ws->id)
        ->call('speichern')
        ->assertOk();

    $karte->refresh();
    expect($karte->kundentyp)->toBe('Business-Lunch')
        ->and($karte->default_niveau)->toBe('gehoben')
        ->and($karte->default_convenience)->toBe('teil_convenience')
        ->and((int) $karte->writing_style_id)->toBe((int) $ws->id);

    // Rück-Hydration: waehle lädt die Leitplanken wieder in die Properties.
    Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->assertSet('kundentyp', 'Business-Lunch')
        ->assertSet('niveau', 'gehoben')
        ->assertSet('writingStyleId', $ws->id);
});

// ── Werkstrang M Phase B (Spec 40 §6): reicher Gericht-Picker (Facetten + offen bleiben) ──────

it('Phase B: Gericht-Picker filtert per Facette + bleibt nach dem Einfügen offen', function () {
    $hg = \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $klFleisch = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_F', 'label' => 'Fleisch', 'diet_form' => 'fleisch']);
    $klVeg = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_V', 'label' => 'Vegetarisch', 'diet_form' => 'vegetarisch']);
    $rind = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'pb1', 'name' => 'Rinderbraten', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 22.00, 'dish_main_group_id' => $hg->id, 'dish_class_id' => $klFleisch->id]);
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'pb2', 'name' => 'Gemüsestrudel', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 16.00, 'dish_main_group_id' => $hg->id, 'dish_class_id' => $klVeg->id]);

    Livewire::test(SpeisekarteIndex::class)->call('neu');
    $karte = FoodAlchemistSpeisekarte::first();
    $comp = Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->set('neueRubrik', 'Hauptgänge')->call('rubrikNeu');
    $rubrik = $karte->sections()->first();

    $comp->call('pickerOeffnen', $rubrik->id, 'gericht')
        ->assertSee('Rinderbraten')->assertSee('Gemüsestrudel')        // ohne Facette: beide
        ->call('pickerWaehleHg', $hg->id)
        ->call('pickerWaehleKlasse', $klFleisch->id)
        ->assertSet('pickerDishClass', $klFleisch->id)
        ->assertSee('Rinderbraten')->assertDontSee('Gemüsestrudel');   // Fleisch-Facette filtert

    // „+ bleibt offen": nach dem Einfügen ist der Picker + die Facette noch aktiv.
    $comp->call('positionAusGericht', $rubrik->id, $rind->id)
        ->assertSet('pickerRubrikId', $rubrik->id)
        ->assertSet('pickerDishClass', $klFleisch->id);
    expect($rubrik->items()->count())->toBe(1);
});
