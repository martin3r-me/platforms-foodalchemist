<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 L1a: VK-Revise („✨ KI-Überarbeiten" im VkModal). Zwei Dinge sind zu
 * beweisen: (1) der Roundtrip läuft über DIESELBE Strecke wie im RecipeModal
 * (RecipeReviseService → syncIngredients mit #508-Grounding), und (2) die
 * Verkaufs-Facetten überleben ihn — sie sind Prompt-Kontext, kein Schreibziel.
 *
 * Der FakeAiProvider echot den Kontext als `werte` zurück; die Zutaten-Liste
 * geht also unverändert durch (Golden-Verhalten). Für den Änderungs-Fall wird
 * die Vorschau direkt gesetzt — das ist genau der Zustand nach kiUeberarbeiten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);

    $this->g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $this->klasse = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::create([
        'dish_main_group_id' => \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::create(
            ['code' => 'FIN', 'label' => 'Fingerfood'])->id,
        'code' => 'FIN-VEG', 'label' => 'Fingerfood vegan', 'diet_form' => 'vegan',
    ]);

    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-l1a', 'name' => 'FIN: Slider',
        'status' => 'draft', 'is_sales_recipe' => true, 'dish_class_id' => $this->klasse->id,
        'sales_unit_count' => 4, 'sales_quantity_per_unit_g' => 60,
    ]);

    $gp = $this->makeGp($this->rootTeam, 'Brioche');
    DB::table('foodalchemist_recipe_ingredients')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->vk->id, 'gp_id' => $gp->id, 'raw_text' => 'Brioche', 'display_name' => 'Brioche',
        'quantity' => 40, 'unit_vocab_id' => $this->g->id, 'position' => 1, 'role' => 'komponente',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->zeileId = (int) DB::getPdo()->lastInsertId();
});

it('L1a: Revise-Roundtrip am Gericht — Menge geändert, neue Komponente gegroundet, Texte mit Lineage ki', function () {
    $gpTofu = $this->makeGp($this->rootTeam, 'Tofu: frisch');

    $vorschau = [
        'werte' => [
            'zutaten' => [
                ['id' => $this->zeileId, 'text' => 'Brioche', 'quantity' => 55, 'einheit_slug' => 'g'],
                ['id' => null, 'text' => 'Tofu', 'quantity' => 70, 'einheit_slug' => 'g'],
            ],
            'description' => 'Veganer Slider mit gebratenem Tofu.',
            'sales_wording_standard' => 'Veganer Slider',
            'aenderungs_notiz' => 'Patty auf Tofu umgestellt.',
        ],
        'confidence' => 0.87,
        'match_vorschau' => [],
    ];

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->set('ueberarbeitung', $vorschau)
        ->call('ueberarbeitungUebernehmen')
        ->assertSet('fehler', null)
        ->assertSet('ueberarbeitung', null)
        ->assertSet('ueberarbeitenOffen', false);

    $zeilen = $this->vk->fresh()->ingredients()->orderBy('position')->get();
    expect($zeilen)->toHaveCount(2)
        ->and((int) $zeilen[0]->id)->toBe($this->zeileId)              // bestehende Zeile bleibt dieselbe
        ->and((float) $zeilen[0]->quantity)->toBe(55.0)
        ->and($zeilen[0]->role)->toBe('komponente')                    // Rolle ist Facette → unangetastet
        ->and((int) $zeilen[1]->gp_id)->toBe((int) $gpTofu->id)        // #508-Grounding der neuen Zeile
        ->and($zeilen[1]->match_method?->value ?? $zeilen[1]->match_method)->toBe('gp_v2_fk');

    $r = $this->vk->fresh();
    expect($r->description)->toBe('Veganer Slider mit gebratenem Tofu.')
        ->and($r->description_source)->toBe('ki')
        ->and($r->sales_wording_standard)->toBe('Veganer Slider')
        ->and($r->sales_wording_source)->toBe('ki');
});

it('L1a: Verkaufs-Facetten überleben das Revise (Klasse/Diät/Verkaufseinheit werden nie geschrieben)', function () {
    // Die KI liefert die Facetten mit — das VK-Revise darf sie trotzdem NICHT anfassen.
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->set('ueberarbeitung', ['werte' => [
            'zutaten' => [['id' => $this->zeileId, 'text' => 'Brioche', 'quantity' => 45, 'einheit_slug' => 'g']],
            'dish_class_id' => null, 'diaetform' => 'omnivor', 'sales_unit_count' => 99,
            'sales_quantity_per_unit_g' => 999, 'markup_class_id' => 4711,
        ], 'confidence' => 0.5, 'match_vorschau' => []])
        ->call('ueberarbeitungUebernehmen')
        ->assertSet('fehler', null);

    $r = $this->vk->fresh();
    expect((int) $r->dish_class_id)->toBe((int) $this->klasse->id)
        ->and($r->dishClass->diet_form)->toBe('vegan')
        ->and((int) $r->sales_unit_count)->toBe(4)
        ->and((float) $r->sales_quantity_per_unit_g)->toBe(60.0)
        ->and($r->markup_class_id)->toBeNull()
        ->and((float) $r->fresh()->ingredients()->first()->quantity)->toBe(45.0);   // Mengen-Revise IST angekommen
});

it('L1a: manuell gepflegter Text bleibt (Override-First, GL-07)', function () {
    $this->vk->update(['plating_text' => 'Hand-Plating', 'plating_source' => 'manual']);

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->set('ueberarbeitung', ['werte' => ['plating_text' => 'KI-Plating', 'description' => 'KI-Text'],
            'confidence' => 0.9, 'match_vorschau' => []])
        ->call('ueberarbeitungUebernehmen');

    $r = $this->vk->fresh();
    expect($r->plating_text)->toBe('Hand-Plating')                     // manual gewinnt
        ->and($r->plating_source)->toBe('manual')
        ->and($r->description)->toBe('KI-Text');                       // ungeschütztes Feld läuft durch
});

it('L1a: leere Anweisung wird abgewiesen (kein KI-Call)', function () {
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->set('anweisung', '   ')
        ->call('kiUeberarbeiten')
        ->assertSet('ueberarbeitung', null);

    expect(DB::table('foodalchemist_ai_call_log')->count())->toBe(0);
});

it('L1a: kiUeberarbeiten füllt die Vorschau inkl. Grounding-Status, ohne zu persistieren', function () {
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->set('anweisung', 'mach die Portion größer')
        ->call('kiUeberarbeiten')
        ->assertSet('fehler', null)
        ->assertSet('ueberarbeitung.match_vorschau.0.status', 'matched');   // Bestands-Zeile ist GP-verknüpft

    // Vorschau ist reine Lese-Operation: die Zutat steht unverändert (GL-07).
    expect((float) $this->vk->fresh()->ingredients()->first()->quantity)->toBe(40.0);
});

it('L1a: das VkModal rendert die Revise-Fläche', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();

    expect($html)->toContain('data-vk-ki-ueberarbeiten');
});
