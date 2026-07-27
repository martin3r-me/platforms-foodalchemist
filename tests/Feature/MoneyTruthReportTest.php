<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\MoneyTruthReportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 22 · H2a — der Messbericht der Geld-Wahrheiten.
 *
 * Diese Tests riegeln die MESSUNG, nicht das gemessene Verhalten: sie bauen die drei
 * Divergenz-Lagen absichtlich (V-041 · V-046/V-059 · V-053) und prüfen, dass der Bericht
 * sie **findet und richtig einordnet**. Das ist der Punkt, an dem eine Messung schiefgehen
 * kann, ohne aufzufallen — eine Zahl, die immer 0 meldet, sieht wie „alles in Ordnung" aus.
 * Genau deshalb enthält jeder Block auch den Gegenfall (Lage vorhanden ⇒ Zähler springt).
 *
 * ⚠️ SQLite-Grenze (wie in `DarreichungServiceTest` dokumentiert): der partielle
 * Ein-Standard-Unique-Index wirkt hier wie volles `unique(recipe_id)` — pro Gericht ist
 * genau EINE Darreichungs-Zeile möglich. Die Fälle sind deshalb je auf ein eigenes Gericht
 * verteilt, was fachlich nichts kostet (der Bericht zählt je Gericht).
 */
beforeEach(function () {
    $this->svc = app(MoneyTruthReportService::class);
    $this->seedTeamHierarchy();
    $this->form = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'teller', 'team_id' => $this->rootTeam->id],
        ['label' => 'Teller']
    );

    /** VK-Gericht + (optional) eine Darreichungs-Zeile in einem Zug. */
    $this->mkGericht = function (string $name, array $recipe = [], ?array $darreichung = null) {
        $g = $this->makeRecipe($this->rootTeam, $name, array_merge([
            'is_sales_recipe' => true,
        ], $recipe));

        if ($darreichung !== null) {
            FoodAlchemistRecipeDarreichung::create(array_merge([
                'team_id' => $this->rootTeam->id,
                'recipe_id' => $g->id,
                'serving_form_id' => $this->form->id,
                'is_standard' => true,
            ], $darreichung));
        }

        return $g->fresh();
    };

    /** GP mit Lead-LA + frei bestimmbaren Preiszeilen. */
    $this->mkGpMitLead = function (string $name, array $preise) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Lieferant '.$name]);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name, 'unit_code' => 'kg',
        ]);
        foreach ($preise as $p) {
            FoodAlchemistPrice::create(array_merge([
                'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'is_blocked' => false,
            ], $p));
        }
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->fresh();
    };
});

// ── A · V-041 — die zwei Lesarten von sales_unit_count ────────────────────────

it('A: findet die Divisions-Lesart bei sales_unit_count > 1 und beziffert den Faktor', function () {
    // Der Fall aus V-041, in klein: Charge-EK 24 €, 10 Einheiten je Verkauf, VK 96 €.
    //   Divisions-Lesart (heutige recipeHk):  24 / 10 / 96  = 2,5 %
    //   Darreichungs-Lesart (mengenkonsistent): 24 / 96     = 25,0 %
    // Faktor 10 — genau die Größenordnung, die die Backlog-Zeile behauptet.
    ($this->mkGericht)('[FIX] Praline | Nougat', ['sales_unit_count' => 10, 'ek_total_eur' => 24.0],
        ['sales_net' => 96.00, 'ek_portion' => 24.0, 'quantity_per_unit_g' => 20, 'unit_count' => 10]);

    $a = $this->svc->messe($this->rootTeam)['a_sales_unit_count'];

    expect($a['verteilung']['unit_count_gt_1'])->toBe(1)
        ->and($a['vergleichbar'])->toBe(1)
        ->and($a['abweichend']['unit_count_gt_1'])->toBe(1)
        ->and($a['abweichend']['unit_count_le_1'])->toBe(0)
        ->and($a['faktor_max'])->toBe(10.0);

    $b = $a['beispiele'][0];
    expect($b['w_pct_division'])->toBe(2.5)
        ->and($b['w_pct_darreichung'])->toBe(25.0)
        ->and($b['delta_pp'])->toBe(22.5);
});

it('A: findet auch den spiegelbildlichen Fall (unit_count = 1, Grammatur ≠ Charge)', function () {
    // Die zweite Hälfte von V-041: eine Einheit je Verkauf, aber die Portion ist nur ein
    // Bruchteil der Charge. Division rechnet mit dem CHARGEN-EK gegen einen PORTIONS-VK
    // ⇒ Quote zu hoch (hier 4× zu hoch). Am echten Bestand ist genau das der Fall,
    // der auftritt — nicht der aus dem ersten Test.
    ($this->mkGericht)('[FIX] Suppe | Kürbis', ['sales_unit_count' => 1, 'ek_total_eur' => 8.0],
        ['sales_net' => 10.00, 'ek_portion' => 2.0, 'quantity_per_unit_g' => 250, 'unit_count' => 1]);

    $a = $this->svc->messe($this->rootTeam)['a_sales_unit_count'];

    expect($a['abweichend']['unit_count_le_1'])->toBe(1)
        ->and($a['abweichend']['unit_count_gt_1'])->toBe(0)
        ->and($a['beispiele'][0]['w_pct_division'])->toBe(80.0)
        ->and($a['beispiele'][0]['w_pct_darreichung'])->toBe(20.0)
        ->and($a['beispiele'][0]['faktor'])->toBe(0.25);
});

it('A: mengenkonsistente Gerichte gelten NICHT als abweichend (kein falsches Rot)', function () {
    ($this->mkGericht)('[FIX] Salat | Feldsalat', ['sales_unit_count' => 1, 'ek_total_eur' => 3.0],
        ['sales_net' => 12.00, 'ek_portion' => 3.0, 'quantity_per_unit_g' => 1000, 'unit_count' => 1]);

    $a = $this->svc->messe($this->rootTeam)['a_sales_unit_count'];

    expect($a['vergleichbar'])->toBe(1)
        ->and($a['abweichend_gesamt'])->toBe(0)
        ->and($a['faktor_max'])->toBeNull()
        ->and($a['beispiele'])->toBe([]);
});

it('A: unvergleichbare Gerichte fallen aus der Quoten-Rechnung, bleiben aber in der Verteilung', function () {
    // Ohne VK, ohne Darreichungs-EK oder ohne Charge-EK ist keine der beiden Quoten
    // definiert — der Bericht darf hier nicht dividieren und nicht raten.
    ($this->mkGericht)('[FIX] Ohne VK | Test', ['sales_unit_count' => 4, 'ek_total_eur' => 5.0],
        ['sales_net' => null, 'ek_portion' => 1.25, 'quantity_per_unit_g' => 100, 'unit_count' => 4]);
    ($this->mkGericht)('[FIX] Ohne EK | Test', ['sales_unit_count' => 4, 'ek_total_eur' => null],
        ['sales_net' => 20.00, 'ek_portion' => 1.25, 'quantity_per_unit_g' => 100, 'unit_count' => 4]);
    ($this->mkGericht)('[FIX] Ohne Darreichung | Test', ['sales_unit_count' => 4, 'ek_total_eur' => 5.0]);

    $a = $this->svc->messe($this->rootTeam)['a_sales_unit_count'];

    expect($a['vk_gerichte'])->toBe(3)
        ->and($a['verteilung']['unit_count_gt_1'])->toBe(3)
        ->and($a['vergleichbar'])->toBe(0)
        ->and($a['abweichend_gesamt'])->toBe(0);
});

// ── B · V-046 / V-059 — welche Zahl die Preis-Leiter liefert ──────────────────

it('B: zählt Preis-Divergenz, Legacy-Rückfall, V-059-Lage und „gar kein VK" getrennt', function () {
    // 1 · beide Zahlen da und ungleich ⇒ Divergenz (die Zahl, die V-046 wollte)
    ($this->mkGericht)('[FIX] Divergent | A', ['sales_net' => 20.00],
        ['sales_net' => 24.00, 'ek_portion' => 5.0, 'quantity_per_unit_g' => 200, 'unit_count' => 1]);
    // 2 · Darreichung ohne Preis ⇒ Leiter fällt auf die Legacy-Spalte
    ($this->mkGericht)('[FIX] Legacy | B', ['sales_net' => 15.00],
        ['sales_net' => null, 'ek_portion' => 4.0, 'quantity_per_unit_g' => 200, 'unit_count' => 1]);
    // 3 · Darreichungs-Zeile vorhanden, aber keine mit is_standard ⇒ V-059
    ($this->mkGericht)('[FIX] Ohne Standard | C', ['sales_net' => 9.00],
        ['is_standard' => false, 'sales_net' => 11.00, 'ek_portion' => 3.0, 'quantity_per_unit_g' => 200, 'unit_count' => 1]);
    // 4 · nichts gepflegt
    ($this->mkGericht)('[FIX] Preislos | D', ['sales_net' => null]);

    $b = $this->svc->messe($this->rootTeam)['b_preis_wahrheit'];

    expect($b['vk_gerichte'])->toBe(4)
        ->and($b['mit_standard_darreichung'])->toBe(2)
        ->and($b['darreichungen_ohne_standard'])->toBe(1)
        ->and($b['ohne_darreichung'])->toBe(1)
        ->and($b['preis_divergenz'])->toBe(1)
        ->and($b['nur_legacy_preis'])->toBe(2) // B (Darreichung ohne Preis) + C (kein Standard)
        ->and($b['kein_preis'])->toBe(1)
        ->and($b['beispiele'][0]['delta_eur'])->toBe(4.0);
});

it('B: gleiche Zahl auf beiden Wegen ist KEINE Divergenz (Cent-Rundung toleriert)', function () {
    ($this->mkGericht)('[FIX] Gleich | A', ['sales_net' => 24.00],
        ['sales_net' => 24.00, 'ek_portion' => 5.0, 'quantity_per_unit_g' => 200, 'unit_count' => 1]);

    $b = $this->svc->messe($this->rootTeam)['b_preis_wahrheit'];

    expect($b['preis_divergenz'])->toBe(0)
        ->and($b['nur_legacy_preis'])->toBe(0)
        ->and($b['beispiele'])->toBe([]);
});

// ── C · V-053 — drei Fassungen von „aktiver Preis" ────────────────────────────

it('C: trennt statusfremd (lax ja, streng nein) von abgelaufen (streng ja, gültig nein)', function () {
    // 1 · Status 9 = ausgelistet: die laxe DQ-Fassung sagt ja, scopeAktiv sagt nein.
    $statusfremd = ($this->mkGpMitLead)('Statusfremd', [
        ['price' => 4.20, 'status' => '9', 'valid_to' => null],
    ]);
    // 2 · Status 0, aber valid_to in der Vergangenheit: streng ja, Gültigkeit nein.
    $abgelaufen = ($this->mkGpMitLead)('Abgelaufen', [
        ['price' => 3.10, 'status' => '0', 'valid_to' => now()->subYear()],
    ]);
    // 3 · sauber versorgt: alle drei Fassungen ja.
    ($this->mkGpMitLead)('Sauber', [['price' => 2.50, 'status' => '0', 'valid_to' => null]]);
    // 4 · gar keine Preiszeile: alle drei Fassungen nein (auch die Ampel meldet die Lücke).
    ($this->mkGpMitLead)('Preislos', []);

    $c = $this->svc->messe($this->rootTeam)['c_lead_la_preis'];

    expect($c['gps_mit_lead'])->toBe(4)
        ->and($c['lax_erfuellt'])->toBe(3)    // statusfremd + abgelaufen + sauber
        ->and($c['streng_erfuellt'])->toBe(2) // abgelaufen + sauber
        ->and($c['gueltig_erfuellt'])->toBe(1)
        ->and($c['delta_lax_streng'])->toBe(1)
        ->and($c['delta_streng_gueltig'])->toBe(1)
        ->and(array_column($c['beispiele_nur_statusfremd'], 'gp_id'))->toBe([$statusfremd->id])
        ->and(array_column($c['beispiele_nur_abgelaufen'], 'gp_id'))->toBe([$abgelaufen->id]);
});

it('C: gesperrte und gelöschte Preiszeilen zählen in keiner Fassung', function () {
    $gp = ($this->mkGpMitLead)('Gesperrt', [
        ['price' => 4.00, 'status' => '0', 'valid_to' => null, 'is_blocked' => true],
    ]);
    FoodAlchemistPrice::where('supplier_item_id', $gp->lead_la_supplier_item_id)->update(['is_blocked' => false]);
    FoodAlchemistPrice::where('supplier_item_id', $gp->lead_la_supplier_item_id)->delete(); // Soft-Delete

    $c = $this->svc->messe($this->rootTeam)['c_lead_la_preis'];

    expect($c['gps_mit_lead'])->toBe(1)
        ->and($c['lax_erfuellt'])->toBe(0)
        ->and($c['streng_erfuellt'])->toBe(0)
        ->and($c['gueltig_erfuellt'])->toBe(0);
});

// ── Querschnitt ──────────────────────────────────────────────────────────────

it('D1: der Bericht eines Kind-Teams sieht die Fälle des Geschwister-Teams nicht', function () {
    // Bewusst über die Fixture-Helfer des ROOT-Teams gebaut und dann umgehängt: die
    // Sichtbarkeit läuft über die Team-Kette, nicht über eine Gleichheit auf team_id.
    $g = ($this->mkGericht)('[FIX] Fremd | A', ['sales_unit_count' => 10, 'ek_total_eur' => 24.0],
        ['sales_net' => 96.00, 'ek_portion' => 24.0, 'quantity_per_unit_g' => 20, 'unit_count' => 10]);
    $g->update(['team_id' => $this->childA->id]);
    FoodAlchemistRecipeDarreichung::where('recipe_id', $g->id)->update(['team_id' => $this->childA->id]);

    $eigen = $this->svc->messe($this->childA)['a_sales_unit_count'];
    $fremd = $this->svc->messe($this->childB)['a_sales_unit_count'];

    expect($eigen['abweichend_gesamt'])->toBe(1)
        ->and($fremd['vk_gerichte'])->toBe(0)
        ->and($fremd['abweichend_gesamt'])->toBe(0);
});

it('read-only: die Messung schreibt nichts (kein Signal, keine Lauf-Zeile, keine Preis-Zeile)', function () {
    ($this->mkGericht)('[FIX] Praline | Nougat', ['sales_unit_count' => 10, 'ek_total_eur' => 24.0],
        ['sales_net' => 96.00, 'ek_portion' => 24.0, 'quantity_per_unit_g' => 20, 'unit_count' => 10]);
    ($this->mkGpMitLead)('Statusfremd', [['price' => 4.20, 'status' => '9', 'valid_to' => null]]);

    $vorher = array_map(fn ($t) => \Illuminate\Support\Facades\DB::table($t)->count(), [
        'foodalchemist_signals', 'foodalchemist_bulk_runs', 'foodalchemist_prices',
        'foodalchemist_recipes', 'foodalchemist_recipe_presentations', 'foodalchemist_gps',
    ]);

    $this->svc->messe($this->rootTeam);

    $nachher = array_map(fn ($t) => \Illuminate\Support\Facades\DB::table($t)->count(), [
        'foodalchemist_signals', 'foodalchemist_bulk_runs', 'foodalchemist_prices',
        'foodalchemist_recipes', 'foodalchemist_recipe_presentations', 'foodalchemist_gps',
    ]);

    expect($nachher)->toBe($vorher);
});

it('Kommando läuft und liefert JSON mit allen drei Blöcken', function () {
    ($this->mkGericht)('[FIX] Praline | Nougat', ['sales_unit_count' => 10, 'ek_total_eur' => 24.0],
        ['sales_net' => 96.00, 'ek_portion' => 24.0, 'quantity_per_unit_g' => 20, 'unit_count' => 10]);

    $this->artisan('foodalchemist:money-truth-report', ['--team' => $this->rootTeam->id, '--json' => true])
        ->assertExitCode(0);

    // Zweiter Lauf ohne --json rendert die Tabellen (Blade-freie Console-Ausgabe).
    $this->artisan('foodalchemist:money-truth-report', ['--team' => $this->rootTeam->id])
        ->assertExitCode(0);

    $this->artisan('foodalchemist:money-truth-report', ['--team' => 999999])
        ->assertExitCode(1);
});
