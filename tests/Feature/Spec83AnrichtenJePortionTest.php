<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ProductionTimeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 83 B3 (L7): Anrichten am Gericht pro Portion. sales_unit_count leer → 1 Ansatz = 1 Portion; ohne gepflegte Zeit pro
 * Menge leitet sich die Anrichte-Zeit aus den Teller-Komponenten ab (Komponenten × 5 s, Startwert, ungeprüft).
 *
 * Fixture = Nachbau „[HG] Weiderind-Senfrostbraten“ (lokal 3868): 7 Komponenten (Fleisch, Jus, Püree, Beilage, Pickle, Chip,
 * Microgreens), Portion 477 g, sales_unit_count leer, Arbeitszeit 5 min (Pass einrichten). NICHT gegen die lokale DB.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    app(TeamSettingsService::class)->update($this->rootTeam, ['default_batch_max_kg' => 20]);
    $this->times = app(ProductionTimeService::class);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'senfrostbraten', 'name' => '[HG] Weiderind-Senfrostbraten',
        'status' => 'approved', 'is_sales_recipe' => true, 'yield_kg' => 0.477, 'sales_unit_count' => null, 'work_time_min' => 5,
    ]);
    foreach (['Fleisch: Senfrostbraten', 'Jus: Altbier', 'Püree: Petersilienwurzel', 'Beilage: Blumenkohl, geröstet',
        'Pickle: Perlzwiebel', 'Chip: Zwiebelring'] as $i => $name) {
        $sub = $this->makeRecipe($this->rootTeam, $name);
        $this->makeIngredient($this->gericht, $name, null, '60', $i + 1)->update(['referenced_recipe_id' => $sub->id]);
    }
    $this->makeIngredient($this->gericht, 'Microgreens Erbse', FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f83|microgreens', 'name' => 'Microgreens Erbse']), '2', 7);
});

it('Abnahme Senfrostbraten: 100 Portionen Anrichten ≈ 50 Personenminuten (±25 %) aus 7 Komponenten × 5 s', function () {
    $r = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100);   // 100 Portionen = 100 Ansätze (1 Ansatz = 1 Portion)

    expect($this->times->tellerKomponenten($this->gericht))->toBe(7)
        ->and($r['variable_time_source'])->toBe('komponenten')
        ->and($r['anrichten_komponenten'])->toBe(7)
        ->and($r['variable_quantity_basis'])->toBe('portion')
        ->and($r['variable_quantity'])->toBe(100.0)
        ->and($r['variable_minutes'])->toBe(58.333)                              // 7 × 5 s × 100 / 60
        ->and($r['variable_minutes'])->toBeGreaterThanOrEqual(37.5)->toBeLessThanOrEqual(62.5)   // Ziel ~50 ±25 %
        ->and($r['warnings'])->toBeEmpty()
        // Pass einrichten EINMAL je Auftragszeile (Entscheid 14.10.): nicht 3 × (47,7 kg / 20 kg), nicht 100 × (1 Ansatz = 1 Portion)
        ->and($r['operations'])->toBe(1)->and($r['batch_minutes'])->toBe(5.0)
        ->and($r['batch_limit'])->toBeNull()->and($r['batch_limit_source'])->toBe('gericht_einmal')
        // Zeile: Pass 5 + Anrichten 58,3 = 63,3 Personenminuten — knapp über dem Ziel-Band 62,5 (an Dominique)
        ->and($r['active_person_minutes'])->toBe(63.333);
});

it('Gepflegte Zeit pro Menge am Gericht gewinnt immer (Portion und auch kg)', function () {
    $this->gericht->update(['variable_work_time_min' => 0.4, 'variable_work_time_basis' => 'portion']);
    $p = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100);
    expect($p['variable_time_source'])->toBe('gepflegt')->and($p['variable_minutes'])->toBe(40.0)
        ->and($p['anrichten_komponenten'])->toBeNull();

    // Sofort-Korrektur 14.10. am Referenzgericht: 0,8 min/kg — bleibt so, keine Doppelzählung mit der Ableitung
    $this->gericht->update(['variable_work_time_min' => 0.8, 'variable_work_time_basis' => 'kg']);
    $k = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100);
    expect($k['variable_time_source'])->toBe('gepflegt')->and($k['variable_quantity_basis'])->toBe('kg')
        ->and($k['variable_minutes'])->toBe(38.16);                               // 0,8 × 47,7 kg
});

it('sales_unit_count leer → 1 Portion je Ansatz; gesetzt → Portionen je Ansatz wie bisher', function () {
    $leer = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 12);
    expect($leer['variable_quantity'])->toBe(12.0);

    $this->gericht->update(['sales_unit_count' => 10]);
    $zehn = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 3);
    expect($zehn['variable_quantity'])->toBe(30.0)->and($zehn['variable_minutes'])->toBe(17.5);   // 30 × 35 s
});

it('Optionale und qs-Zeilen sind keine Teller-Komponenten; Basisrezepte bekommen keine Ableitung', function () {
    $qs = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'qs'], ['display_de' => 'nach Geschmack', 'dimension' => 'count']);
    $this->makeIngredient($this->gericht, 'Fleur de Sel', null, '1', 8)->update(['unit_vocab_id' => $qs->id]);
    $this->makeIngredient($this->gericht, 'Kresse', null, '1', 9)->update(['is_optional' => true]);
    expect($this->times->tellerKomponenten($this->gericht->fresh()))->toBe(7);

    $basis = $this->makeRecipe($this->rootTeam, 'Fond: Kalb');
    $this->makeIngredient($basis, 'Knochen', null, '1000');
    $b = $this->times->calculateForBatches($this->rootTeam, $basis->fresh(), 2);
    expect($b['variable_time_source'])->toBeNull()->and($b['variable_minutes'])->toBe(0.0);
});

it('Gericht ohne Komponenten: keine Anrichte-Zeit, keine Warnung (Bestand bleibt unverändert)', function () {
    $leer = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'leer', 'name' => 'Leerer Teller',
        'status' => 'approved', 'is_sales_recipe' => true, 'work_time_min' => 10]);
    $r = $this->times->calculateForBatches($this->rootTeam, $leer, 2);

    expect($r['variable_minutes'])->toBe(0.0)->and($r['variable_time_source'])->toBeNull()
        ->and($r['active_person_minutes'])->toBe(10.0)->and($r['warnings'])->toBeEmpty();   // Pass einmal, nicht 2 × 10
});

it('VK-Modal zeigt die abgeleitete Anrichte-Zeit als Platzhalter und Hinweis', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Koch Anrichten'));
    $html = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Verkauf\VkModal::class)->call('oeffnen', $this->gericht->id)->html();

    expect($html)->toContain('data-vk-anrichten-abgeleitet="0,58"')
        ->and($html)->toContain('Leer = Anrichten aus 7 Teller-Komponenten × 5 s = 0,58 min je Portion');
});

it('Planungsblatt: Ansätze und Portionen des Gerichts bleiben unverändert, nur die Arbeitszeit folgt B3', function () {
    $blatt = app(\Platform\FoodAlchemist\Services\PlanungsblattService::class)
        ->produktionsblattFuerZiele($this->rootTeam, [['recipe_id' => $this->gericht->id, 'portions' => 100]]);
    $zeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $this->gericht->id);

    // Gerichts-Ansätze = Portionen ÷ max(1, sales_unit_count) — rechnet das Planungsblatt selbst, nicht ProductionTimeService
    expect($zeile['ansaetze'])->toBe(100.0)->and($zeile['benoetigt_ansaetze'])->toBe(100.0)->and($zeile['portionen'])->toBe(100)
        ->and($zeile['arbeitszeit_min'])->toBe(63.333);                        // Pass 5 + Anrichten 58,3
});
