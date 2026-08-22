<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor as SpeiseplanEditor;
use Platform\FoodAlchemist\Livewire\Speiseplan\Index as SpeiseplanIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M14-02 / Spec 29: Speiseplan-Raster — anlegen (Browser), Zelle belegen (Gericht, Editor),
 * Eintrag erscheint. Das Planen wanderte in den Fullscreen-Editor (Speiseplan\Editor); der
 * Browser (Speiseplan\Index) legt nur an und öffnet ihn per Event.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g1', 'name' => 'Tagessuppe Kürbis', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 3.50, 'ek_total_eur' => 1.00,
    ]);
});

it('Browser: „+ Neuer Plan" legt an und öffnet den Editor', function () {
    Livewire::test(SpeiseplanIndex::class)->assertOk()
        ->call('neu')
        ->assertDispatched('speiseplan-editor.bearbeiten');

    expect(FoodAlchemistSpeiseplan::count())->toBe(1);
});

it('Editor: Zelle (Datum × Mittag) mit Gericht belegen — Eintrag erscheint', function () {
    Livewire::test(SpeiseplanIndex::class)->call('neu');
    $sp = FoodAlchemistSpeiseplan::first();
    expect($sp)->not->toBeNull();

    // Speiseplan v2: Zelle = echtes Datum × Linie (null = »Ohne Linie«) × Mahlzeit-State.
    // Datum in der sichtbaren Woche wählen, sonst zeigt das Raster den Eintrag nicht.
    $montag = now()->startOfWeek()->format('Y-m-d');
    Livewire::test(SpeiseplanEditor::class)
        ->call('oeffnenBearbeiten', $sp->id)
        ->assertDispatched('modal.open')
        ->set('form.name', 'KW aktuell')->call('speichern')
        ->call('zelleOeffnen', $montag, null)
        ->set('pickerTyp', 'gericht')
        ->set('pickerSuche', 'Kürbis')
        ->call('inhaltHinzu', 'gericht', $this->gericht->id)
        ->assertDispatched('speiseplan-geaendert')
        ->assertSee('Tagessuppe Kürbis');

    $e = $sp->eintraege()->first();
    expect($e)->not->toBeNull()
        ->and($e->entry_date->format('Y-m-d'))->toBe($montag)
        ->and($e->meal)->toBe('mittag')
        ->and($e->sales_recipe_id)->toBe($this->gericht->id);
});

it('gerichtKandidaten (Spec 42): browst ohne Suche, filtert Hauptgruppe, schließt Varianten aus', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SpeiseplanService::class);
    $a = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'sa', 'name' => 'Alpha-Gericht', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 5, 'ek_total_eur' => 1, 'dish_main_group_id' => 11]);
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'sb', 'name' => 'Beta-Gericht', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 5, 'ek_total_eur' => 1, 'dish_main_group_id' => 22]);
    $variant = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'sv', 'name' => 'Alpha-Variante', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 5, 'ek_total_eur' => 1, 'variant_source_recipe_id' => $a->id]);

    $alle = $svc->gerichtKandidaten($this->rootTeam, '', 50)->pluck('name')->all();
    expect($alle)->toContain('Alpha-Gericht');
    expect($alle)->toContain('Beta-Gericht');
    expect($svc->gerichtKandidaten($this->rootTeam, '', 50)->pluck('id')->all())->not->toContain($variant->id);

    $nur11 = $svc->gerichtKandidaten($this->rootTeam, '', 50, 11)->pluck('name')->all();
    expect($nur11)->toContain('Alpha-Gericht');
    expect($nur11)->not->toContain('Beta-Gericht');
});

it('Editor-Picker (Spec 42): browst Gerichte OHNE Suchbegriff + Facetten-Toggle', function () {
    Livewire::test(SpeiseplanIndex::class)->call('neu');
    $sp = FoodAlchemistSpeiseplan::first();
    $montag = now()->startOfWeek()->format('Y-m-d');

    Livewire::test(SpeiseplanEditor::class)
        ->call('oeffnenBearbeiten', $sp->id)
        ->call('zelleOeffnen', $montag, null)
        ->set('pickerTyp', 'gericht')
        ->assertSee('Tagessuppe Kürbis')          // Browse ohne Suche (nicht „erst tippen")
        ->call('pickerWaehleHg', 777)              // Facette ohne Treffer (Kürbis hat keine HG)
        ->assertSet('pickerHauptgruppe', 777)
        ->assertDontSee('Tagessuppe Kürbis')
        ->call('pickerWaehleHg', 777)              // erneut klicken = Facette löschen
        ->assertSet('pickerHauptgruppe', null)
        ->assertSee('Tagessuppe Kürbis');
});
