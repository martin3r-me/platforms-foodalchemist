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
