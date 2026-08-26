<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Produktion\Tagesplan;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation as Station;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\ProductionPlanService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Stufe 3 P3.3 — Verteiler: routet auf den Rezept-Default-Posten und zieht vorproduzierbare
 * Zeilen von einem überlasteten Tag auf frühere, freie Tage. Frische Zeilen bleiben.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->plan = app(ProductionPlanService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));

    $this->wk = Station::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'wk', 'name' => 'Warme Küche',
        'kapazitaet_min_pro_tag' => 480,
    ]);

    // Fond: vorproduzierbar (3 Tage), 300 min/Ansatz. Salat: frisch (0), 300 min/Ansatz.
    $this->fond = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Brauner Fond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 4.0, 'work_time_min' => 300,
        'default_station_id' => $this->wk->id, 'max_vorlauf_tage' => 3,
    ]);
    $this->salat = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'salat', 'name' => 'Blattsalat',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 4.0, 'work_time_min' => 300,
        'default_station_id' => $this->wk->id, 'max_vorlauf_tage' => 0,
    ]);

    // Ein Liefertag 2026-08-10 mit BEIDEN → 600 min an der WK (Kapazität 480) = Überlast.
    $this->auftrag = $this->svc->saveNew($this->rootTeam, '2026-08-10', 'Bankett', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->fond->id, 'amount_kg' => 4.0],
        ['source_ref' => 'r:salat', 'recipe_id' => $this->salat->id, 'amount_kg' => 4.0],
    ]);
});

it('routet auf den Default-Posten und zieht den Fond vor, der Salat bleibt frisch', function () {
    $ergebnis = $this->plan->schlage($this->rootTeam, '2026-08-05', '2026-08-12');

    $proRezept = collect($ergebnis['vorschlag'])->keyBy('rezept');

    // Beide auf die Warme Küche geroutet
    expect($proRezept['Brauner Fond']['station'])->toBe('Warme Küche')
        ->and($proRezept['Blattsalat']['station'])->toBe('Warme Küche');

    // Fond wurde vom 10. weggezogen (Vorlauf > 0), Salat bleibt am Liefertag
    expect($proRezept['Brauner Fond']['vorlauf_tage'])->toBeGreaterThan(0)
        ->and($proRezept['Brauner Fond']['plan_date'])->toBe('2026-08-09')
        ->and($proRezept['Blattsalat']['vorlauf_tage'])->toBe(0)
        ->and($proRezept['Blattsalat']['plan_date'])->toBe('2026-08-10');

    // Kein Tag mehr über Kapazität, Kosten sind ausgewiesen
    $stufen = collect($ergebnis['last'])->flatMap(fn ($s) => collect($s)->pluck('stufe'));
    expect($stufen)->not->toContain('ueberlast')
        ->and($ergebnis['kosten']['gesamt_eur'])->toBeGreaterThan(0);
});

it('übernimmt den Vorschlag in die Zeilen (Posten + Vorlauf, plan_date abgeleitet)', function () {
    $ergebnis = $this->plan->schlage($this->rootTeam, '2026-08-05', '2026-08-12');
    $gesetzt = $this->plan->uebernehmen($this->rootTeam, $ergebnis['vorschlag']);

    expect($gesetzt)->toBe(2);

    $fondLine = Line::where('recipe_id', $this->fond->id)->firstOrFail();
    expect((int) $fondLine->station_id)->toBe($this->wk->id)
        ->and($fondLine->vorlauf_tage)->toBe(1)
        ->and($fondLine->plan_date->toDateString())->toBe('2026-08-09');   // 10. − 1 Tag Vorlauf
});

it('rechnet die Produktionskosten aus dem Posten-Rollensatz', function () {
    // Besetzung: 1 Koch à 60 €/Std = 1 €/min. 2 Ansätze × 300 min = 600 min → 600 €.
    $rolle = \Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'koch', 'name' => 'Koch', 'stundensatz_eur' => 60,
    ]);
    $this->wk->besetzung = [(string) $rolle->id => 1];
    $this->wk->schicht_minuten = 480;
    $this->wk->kapazitaet_min_pro_tag = 480;   // Override bleibt, Kosten kommen aus der Besetzung
    $this->wk->save();

    $ergebnis = $this->plan->schlage($this->rootTeam, '2026-08-05', '2026-08-12');
    expect($ergebnis['kosten']['gesamt_eur'])->toBe(600.0);
});

it('zeigt den Vorschlag im Tagesplan und übernimmt ihn per Klick', function () {
    Livewire::test(Tagesplan::class, ['von' => '2026-08-05', 'tage' => 8])
        ->call('vorschlagen')
        ->assertSet('vorschlag', fn ($v) => $v !== null && $v['aenderungen'] >= 2)
        ->assertSeeHtml('data-tagesplan-vorschlag')
        ->assertSee('Brauner Fond')
        ->call('vorschlagUebernehmen')
        ->assertSet('vorschlag', null);

    $fondLine = Line::where('recipe_id', $this->fond->id)->firstOrFail();
    expect((int) $fondLine->station_id)->toBe($this->wk->id)->and($fondLine->vorlauf_tage)->toBe(1);
});

it('speichert die Planer-Rezeptfelder über den Editor-Service (Whitelist erweitert)', function () {
    app(RecipeService::class)->update($this->rootTeam, $this->salat->id, [
        'name' => 'Blattsalat',
        'default_station_id' => $this->wk->id,
        'max_vorlauf_tage' => 5,
        'setup_time_min' => 15,
        'batch_max_kg' => 12.5,
    ]);

    $r = $this->salat->fresh();
    expect((int) $r->default_station_id)->toBe($this->wk->id)
        ->and($r->max_vorlauf_tage)->toBe(5)
        ->and($r->setup_time_min)->toBe(15)
        ->and((float) $r->batch_max_kg)->toBe(12.5);
});

it('verwendet die kleinste Batchgrenze aus Rezept, Posten und Team-Standard', function () {
    $kessel = Station::create(['team_id' => $this->rootTeam->id, 'slug' => 'kessel', 'name' => 'Großer Kessel', 'batch_max_kg' => 40.0]);
    $auftrag = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Fond groß', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->fond->id, 'amount_kg' => 24.0],   // 24 kg Bedarf
    ]);
    $line = Line::where('production_order_id', $auftrag->id)->where('recipe_id', $this->fond->id)->firstOrFail();
    // Ohne Posten greift der globale Default-Kessel (20 kg): 24 kg = 2 Koch-Vorgänge × 300.
    expect((int) $line->arbeitszeit_min)->toBe(600);

    // Der 40-kg-Posten ist großzügiger als der 20-kg-Team-Standard. Der kleinere
    // Team-Wert bleibt wirksam, also werden weiterhin zwei Vorgänge benötigt.
    $this->svc->assignLine($this->rootTeam, $line->id, ['station_id' => $kessel->id]);
    expect((int) $line->fresh()->arbeitszeit_min)->toBe(600);
});

it('nutzt den Team-Standard-Topf-Deckel als Fallback, wenn Rezept und Posten keinen haben', function () {
    // Ohne Team-Setting greift die Code-Konstante (20 kg): 24 kg = 2 Koch-Vorgänge × 300 = 600.
    $ohne = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Fond A', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->fond->id, 'amount_kg' => 24.0],
    ]);
    $lineOhne = Line::where('production_order_id', $ohne->id)->where('recipe_id', $this->fond->id)->firstOrFail();
    expect((int) $lineOhne->arbeitszeit_min)->toBe(600);

    // Team pflegt einen 40-kg-Standardkessel → 24 kg passen in EINEN Vorgang → 300.
    app(TeamSettingsService::class)->update($this->rootTeam, ['default_batch_max_kg' => 40.0]);
    $mit = $this->svc->saveNew($this->rootTeam, '2026-08-21', 'Fond B', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->fond->id, 'amount_kg' => 24.0],
    ]);
    $lineMit = Line::where('production_order_id', $mit->id)->where('recipe_id', $this->fond->id)->firstOrFail();
    expect((int) $lineMit->arbeitszeit_min)->toBe(300);
});

it('speichert die Planer-Felder auch beim NEUANLEGEN (Create-Parität)', function () {
    $r = app(RecipeService::class)->create($this->rootTeam, [
        'name' => 'Neue Sauce',
        'default_station_id' => $this->wk->id,
        'max_vorlauf_tage' => 2, 'setup_time_min' => 10, 'batch_max_kg' => 9.0,
    ]);

    expect((int) $r->default_station_id)->toBe($this->wk->id)
        ->and($r->max_vorlauf_tage)->toBe(2)
        ->and($r->setup_time_min)->toBe(10)
        ->and((float) $r->batch_max_kg)->toBe(9.0);
});
