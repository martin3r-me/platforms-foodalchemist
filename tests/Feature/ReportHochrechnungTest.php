<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * E (Dominique, 2026-09-04): Bedarfs-Hochrechnung im Report — „ich will auf Bedarf drucken
 * können, zum Beispiel 50 Kilo oder 50 Portionen".
 *
 * EINE Mechanik (Zielmasse ÷ Ansatz), zwei Eingabe-Sichten (Basisrezept kg, Gericht
 * N × Darreichung). Geprüft wird, WAS mitskaliert und was ausdrücklich nicht: Mengen,
 * Ausbeute und EK ja — Verhältniszahlen (€/kg) und Arbeitszeit nein. Letzteres ist die
 * eigentliche Zusicherung: eine linear hochgerechnete Arbeitszeit wäre eine erfundene Zahl
 * (Rüstzeit fällt einmal an, s. OrderCostingService/Produktionsplaner).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeService::class);
    $this->report = app(ReportExportService::class);
    $this->g = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'],
        ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar']);

    // GP mit 10 €/kg = 0,01 €/g
    $gp = $this->makeGp($this->rootTeam, 'Berglinsen: trocken');
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Berglinsen', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id,
        'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id,
        'price' => 10.0, 'status' => '0']);
    $gp->update(['lead_la_supplier_item_id' => $la->id]);

    $this->rezept = $this->svc->create($this->rootTeam, ['name' => 'Beilage: Berglinsen']);
    $this->rezept->update(['work_time_min' => 15]);
    $this->svc->syncIngredients($this->rootTeam, $this->rezept->id, [[
        'id' => null, 'gp_id' => $gp->id, 'raw_text' => '2000 g',
        'quantity' => '2000', 'unit_vocab_id' => $this->g->id,
    ]]);
});

it('rechnet das Basisrezept auf die Ziel-Kilo hoch — Mengen, Ausbeute und EK, nicht die Arbeitszeit', function () {
    $ansatz = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id,
        $this->report->optionen([], 'recipe'));
    expect((float) $ansatz['recipe']['yield_kg'])->toBe(2.0)
        ->and((float) $ansatz['recipe']['ek_total_eur'])->toBe(20.0);

    // 50 kg aus 2 kg Ansatz = Faktor 25
    $hoch = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id,
        $this->report->optionen(['ziel_kg' => '50'], 'recipe'));

    expect($hoch['hochrechnung']['aktiv'])->toBeTrue()
        ->and((float) $hoch['hochrechnung']['faktor'])->toBe(25.0)
        ->and((float) $hoch['recipe']['yield_kg'])->toBe(50.0)
        ->and((float) $hoch['recipe']['ek_total_eur'])->toBe(500.0)
        ->and((float) $hoch['recipe']['ingredients'][0]['quantity'])->toBe(50000.0)
        ->and((float) $hoch['recipe']['ingredients'][0]['ek_anteil_eur'])->toBe(500.0)
        // Verhältniszahl bleibt — €/kg ändert sich beim Skalieren nicht
        ->and((float) $hoch['recipe']['ek_per_kg_eur'])->toBe((float) $ansatz['recipe']['ek_per_kg_eur'])
        // und die Arbeitszeit ausdrücklich AUCH nicht
        ->and($hoch['recipe']['produktion']['work_time_min'])->toBe(15);
});

it('nimmt Komma-Eingaben und lässt den Ansatz-Report unverändert, wenn nichts angegeben ist', function () {
    $hoch = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id,
        $this->report->optionen(['ziel_kg' => '5,5'], 'recipe'));
    expect((float) $hoch['recipe']['yield_kg'])->toBe(5.5);

    $ohne = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id,
        $this->report->optionen([], 'recipe'));
    expect($ohne['hochrechnung']['aktiv'])->toBeFalse()
        ->and($ohne['hochrechnung']['faktor'])->toBeNull()
        ->and((float) $ohne['recipe']['yield_kg'])->toBe(2.0);
});

it('meldet fehlende Ausbeute statt einen Faktor zu erfinden', function () {
    $leer = $this->svc->create($this->rootTeam, ['name' => 'Fond: ohne Ausbeute']);
    $hoch = $this->report->rezeptDaten($this->rootTeam, $leer->id,
        $this->report->optionen(['ziel_kg' => '50'], 'recipe'));

    expect($hoch['hochrechnung']['aktiv'])->toBeFalse()
        ->and($hoch['hochrechnung']['faktor'])->toBeNull()
        ->and($hoch['hochrechnung']['hinweis'])->toContain('Ausbeute');
});

it('rechnet das Gericht über N × Darreichung hoch — Standard vorgewählt, umschaltbar', function () {
    $this->rezept->update(['is_sales_recipe' => true]);
    $teller = FoodAlchemistServierform::firstOrCreate(['code' => 'teller', 'team_id' => $this->rootTeam->id],
        ['label' => 'Teller']);
    $platte = FoodAlchemistServierform::firstOrCreate(['code' => 'platte', 'team_id' => $this->rootTeam->id],
        ['label' => 'Servierplatte']);
    // Ansatz 2 kg. Teller 200 g (Standard) → 50 Teller = 10 kg = Faktor 5.
    FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id,
        'serving_form_id' => $teller->id, 'is_standard' => true, 'quantity_per_unit_g' => 200]);
    // Platte 1000 g → 50 Platten = 50 kg = Faktor 25 (die „je nach Verkaufseinheit"-Hälfte)
    FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id,
        'serving_form_id' => $platte->id, 'is_standard' => false, 'quantity_per_unit_g' => 1000]);

    // Ohne Auswahl greift die Standard-Darreichung …
    $standard = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id,
        $this->report->optionen(['ziel_menge' => '50'], 'recipe'));
    expect($standard['hochrechnung']['darreichung']['label'])->toBe('Teller')
        ->and((float) $standard['hochrechnung']['faktor'])->toBe(5.0)
        ->and((float) $standard['recipe']['yield_kg'])->toBe(10.0);

    // … der Umschalter wählt eine andere, und dieselben 50 Stück ergeben eine andere Masse.
    $ids = collect($standard['hochrechnung']['darreichungen'])->firstWhere('label', 'Servierplatte')['id'];
    $umgeschaltet = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id,
        $this->report->optionen(['ziel_menge' => '50', 'darreichung' => (string) $ids], 'recipe'));
    expect((float) $umgeschaltet['hochrechnung']['faktor'])->toBe(25.0)
        ->and((float) $umgeschaltet['recipe']['yield_kg'])->toBe(50.0);
});

it('erfindet kein Portionsgewicht, wenn die Darreichung keins hat', function () {
    $this->rezept->update(['is_sales_recipe' => true]);
    $sf = FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id],
        ['label' => 'Unbestimmt']);
    FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id,
        'serving_form_id' => $sf->id, 'is_standard' => true, 'quantity_per_unit_g' => null]);

    $hoch = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id,
        $this->report->optionen(['ziel_menge' => '50'], 'recipe'));

    expect($hoch['hochrechnung']['aktiv'])->toBeFalse()
        ->and($hoch['hochrechnung']['hinweis'])->toContain('Portionsgewicht')
        ->and((float) $hoch['recipe']['yield_kg'])->toBe(2.0);      // Ansatz bleibt stehen
});
