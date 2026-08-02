<?php

use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\WareneinsatzAbweichungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 32 · C4 — Soll/Ist-Wareneinsatz.
 *
 * Der Rechenweg ist einfach; gefährlich ist die Aussage. Geprüft wird deshalb vor allem,
 * dass die Fläche NICHT behauptet, etwas gemessen zu haben, wenn die Datenlage das nicht
 * hergibt — eine plausible Zahl aus halb zugeordneten Verkaufszeilen wäre schlimmer als gar keine.
 */
beforeEach(function () {
    $this->svc = app(WareneinsatzAbweichungService::class);
    $this->seedTeamHierarchy();

    $sf = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id],
        ['label' => 'Unbestimmt'],
    );

    // Ein Gericht, EK 4,00 € je Portion.
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g1', 'name' => 'Schnitzel',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_unit_count' => 1,
    ]);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'sales_net' => 12.00, 'ek_portion' => 4.00,
    ]);

    $this->verkauf = function (float $menge, float $umsatz, ?int $recipeId, string $label = 'Schnitzel', string $tag = '2026-07-15') {
        FoodAlchemistSalesFact::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $recipeId, 'raw_label' => $label,
            'qty_sold' => $menge, 'revenue_net' => $umsatz, 'sold_at' => $tag,
            'source' => 'csv_import', 'source_hash' => sha1($label . $tag . $umsatz),
        ]);
    };

    $this->einkauf = function (float $betrag, string $tag = '2026-07-10') {
        FoodAlchemistPurchaseTransaction::create([
            'team_id' => $this->rootTeam->id, 'designation_raw' => 'Fleisch',
            'qty' => 1, 'unit_price' => $betrag, 'line_total' => $betrag,
            'purchased_at' => $tag, 'source' => 'necta_import',
        ]);
    };
});

it('rechnet Ist-Quote und Abweichung gegen die Rezeptur', function () {
    // 100 Portionen à 12 € = 1.200 € Umsatz; Rezeptur verlangt 100 × 4 € = 400 €.
    // Eingekauft wurden 520 € → 120 € zu viel, das sind 10 pp vom Umsatz.
    ($this->verkauf)(100, 1200.00, $this->gericht->id);
    ($this->einkauf)(520.00);

    $a = $this->svc->analyse($this->rootTeam, '2026-07-01', '2026-07-31');

    expect($a['umsatz'])->toBe(1200.0)
        ->and($a['einkauf'])->toBe(520.0)
        ->and($a['theoretisch'])->toBe(400.0)
        ->and($a['ist_pct'])->toBe(43.3)          // 520 / 1200
        ->and($a['abdeckung_pct'])->toBe(100.0)
        ->and($a['belastbar'])->toBeTrue()
        ->and($a['abweichung_eur'])->toBe(120.0)
        ->and($a['abweichung_pp'])->toBe(10.0);
});

it('verweigert die Aussage, wenn zu wenig Umsatz zugeordnet ist', function () {
    // Nur 30 % des Umsatzes hängen an einem Gericht — der theoretische Wareneinsatz wäre
    // systematisch zu niedrig und die „Abweichung" ein Artefakt der eigenen Datenlücke.
    ($this->verkauf)(30, 360.00, $this->gericht->id);
    ($this->verkauf)(0, 840.00, null, 'Unbekannter Posten');
    ($this->einkauf)(500.00);

    $a = $this->svc->analyse($this->rootTeam, '2026-07-01', '2026-07-31');

    expect($a['abdeckung_pct'])->toBe(30.0)
        ->and($a['belastbar'])->toBeFalse()
        ->and($a['abweichung_eur'])->toBeNull()
        ->and($a['hinweis'])->toContain('nicht belastbar');

    // Die Ist-Quote bleibt trotzdem gültig — sie braucht keine Zuordnung, nur Umsatz und Einkauf.
    expect($a['ist_pct'])->toBe(41.7);
});

it('sagt es, wenn eine der beiden Seiten fehlt', function () {
    ($this->einkauf)(500.00);
    $ohneUmsatz = $this->svc->analyse($this->rootTeam, '2026-07-01', '2026-07-31');
    expect($ohneUmsatz['ist_pct'])->toBeNull()
        ->and($ohneUmsatz['hinweis'])->toContain('Kein Verkaufs-Ist');

    // Anderer Zeitraum: Umsatz da, Einkauf nicht.
    ($this->verkauf)(10, 120.00, $this->gericht->id, 'Schnitzel', '2026-06-15');
    $ohneEinkauf = $this->svc->analyse($this->rootTeam, '2026-06-01', '2026-06-30');
    expect($ohneEinkauf['hinweis'])->toContain('Kein Einkaufsjournal');
});

it('erzeugt ein Signal, sobald die Abweichung die Schwelle reißt', function () {
    $vormonat = now()->subMonthNoOverflow()->startOfMonth();
    ($this->verkauf)(100, 1200.00, $this->gericht->id, 'Schnitzel', $vormonat->copy()->addDays(5)->toDateString());
    ($this->einkauf)(520.00, $vormonat->copy()->addDays(3)->toDateString());

    $n = app(SignalDetektorService::class)->wareneinsatzIstAbweichung($this->rootTeam);

    expect($n)->toBe(1);
    $sig = FoodAlchemistSignal::where('type', SignalTyp::WareneinsatzIstAbweichung->value)->first();
    expect($sig)->not->toBeNull()
        ->and($sig->severity->value)->toBe('kritisch')          // mehr eingekauft als nötig
        // Lose verglichen: der Wert geht durch die JSON-Spalte und kommt je nach
        // serialize_precision als 10 oder 10.0 zurück — das ist keine fachliche Aussage.
        ->and((float) $sig->payload['abweichung_pp'])->toBe(10.0)
        ->and($sig->dedup_key)->toBe('we-ist-abweichung:' . $vormonat->format('Y-m'));
});

it('schweigt unterhalb der Schwelle und bei nicht belastbarer Lage', function () {
    $vormonat = now()->subMonthNoOverflow()->startOfMonth();

    // 410 statt 400 € → 0,8 pp, unter dem Default von 3 pp.
    ($this->verkauf)(100, 1200.00, $this->gericht->id, 'Schnitzel', $vormonat->copy()->addDays(5)->toDateString());
    ($this->einkauf)(410.00, $vormonat->copy()->addDays(3)->toDateString());

    expect(app(SignalDetektorService::class)->wareneinsatzIstAbweichung($this->rootTeam))->toBe(0);

    // Schwelle enger stellen → dasselbe Bild meldet jetzt.
    app(TeamSettingsService::class)->update($this->rootTeam, ['we_deviation_threshold_pp' => 0.5]);
    expect(app(SignalDetektorService::class)->wareneinsatzIstAbweichung($this->rootTeam))->toBe(1);
});

it('bleibt knopflos — die Ursache klärt die Küche, nicht das System', function () {
    $sig = FoodAlchemistSignal::create([
        'team_id' => $this->rootTeam->id, 'type' => SignalTyp::WareneinsatzIstAbweichung->value,
        'severity' => 'kritisch', 'status' => 'offen', 'title' => 'x',
        'dedup_key' => 'we-ist-abweichung:2026-07', 'source' => 'detektor',
    ]);

    expect(\Platform\FoodAlchemist\Support\SignalCockpit::planFor($sig))->toBeNull()
        ->and(\Platform\FoodAlchemist\Support\SignalCockpit::ohneWegGrund($sig))->toContain('Messung');
});
