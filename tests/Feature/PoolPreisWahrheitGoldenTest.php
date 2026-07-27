<?php

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\MenuCandidatePoolService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 12·S2a-1b Punkt 3 (V-046) — EINE Preis-Wahrheit im Kandidaten-Pool.
 *
 * Zwei Rollen in einer Datei, bewusst getrennt benannt:
 *
 *   · **Golden (Nicht-Verschiebung):** für den Bestandsfall — Darreichungs-Preis ==
 *     Legacy-Spalte, oder gar keine Darreichung — muss die Pool-Ausgabe VOR und NACH
 *     dem Umbau zeichengleich sein. Das ist der Riegel gegen die stille Verschiebung:
 *     die Messung auf der Dev-MySQL sagt 0 von 26 VK-Gerichten abweichend, der Umbau
 *     darf also im Bestand *nichts* verändern.
 *   · **Verschiebungs-Nachweis:** genau dort, wo die beiden Zahlen auseinandergehen,
 *     MUSS sich das Verhalten ändern — die Darreichung gewinnt. Diese Erwartungen waren
 *     vor dem Umbau rot (belegt im Stand-Log); sie sind der Gegenbeweis, dass der
 *     Riegel greift und nicht nur mitläuft.
 *
 * Warum das mehr ist als Kosmetik: `price_min`/`price_max` sind ein HARTER Ausschluss
 * in `filterFuerSlot`. Ein Gericht, dessen gepflegter Darreichungs-Preis im Band liegt,
 * dessen Legacy-Spalte aber daneben, verschwand lautlos aus dem Pool — und der Solver
 * (R2.4) hätte DB auf der Resolver-Zahl maximiert, aber aus einer Menge gewählt, die
 * auf der Legacy-Zahl vorgefiltert wurde.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->pool = app(MenuCandidatePoolService::class);
    $this->frames = app(PlanningFrameService::class);

    $this->sf = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id],
        ['label' => 'Unbestimmt']
    );

    $this->mkGericht = function (string $key, string $name, array $attr = []): FoodAlchemistRecipe {
        return FoodAlchemistRecipe::create(array_merge([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
            'status' => 'approved', 'is_sales_recipe' => true,
        ], $attr));
    };
    $this->mkDarreichung = function (FoodAlchemistRecipe $r, ?float $vk, ?float $ek, bool $standard = true): FoodAlchemistRecipeDarreichung {
        return FoodAlchemistRecipeDarreichung::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->sf->id,
            'is_standard' => $standard, 'sales_net' => $vk, 'ek_portion' => $ek,
        ]);
    };

    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Preis-FB']);
    $this->frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    $this->frame->loadMissing(['slots.rules', 'rules']);
});

it('GOLDEN: im Bestandsfall (gleiche Zahl oder keine Darreichung) bleibt die Pool-Ausgabe zeichengleich', function () {
    // Der Bestand, wie die Messung ihn zeigt: Darreichung und Legacy-Spalte stimmen überein
    // (der Import füllt `recipes.sales_net` fill-only als Anzeige-Spiegel), plus Alt-Gerichte
    // ganz ohne Darreichung, plus der preislose Fall.
    $spiegel = ($this->mkGericht)('gold-spiegel', 'HG: Spiegel', ['sales_net' => 18.50]);
    ($this->mkDarreichung)($spiegel, 18.50, 5.00);
    $ohneDarr = ($this->mkGericht)('gold-legacy', 'HG: Nur Legacy', ['sales_net' => 24.00, 'ek_total_eur' => 7.00]);
    $ohnePreis = ($this->mkGericht)('gold-preislos', 'HG: Ohne Preis', ['ek_total_eur' => 3.00]);

    $pool = $this->pool->fuerFrame($this->rootTeam, $this->frame, false, true);

    // Golden-Projektion auf den Rezept-KEY statt auf die Auto-Increment-ID (Lehre aus Lauf 42:
    // IDs machen einen Golden-Satz beim ersten Fixture-Umbau wertlos).
    $projektion = $pool->mapWithKeys(fn ($k, $id) => [
        FoodAlchemistRecipe::find($id)->recipe_key => [
            'sales_net' => $k['sales_net'],
            'w_sales_net' => $k['wirtschaft']['sales_net'],
            'quelle' => $k['wirtschaft']['quelle'],
            'vollstaendig' => $k['wirtschaft']['vollstaendig'],
        ],
    ])->all();

    expect($projektion)->toBe([
        'gold-spiegel' => ['sales_net' => 18.5, 'w_sales_net' => 18.5, 'quelle' => 'darreichung', 'vollstaendig' => true],
        'gold-legacy' => ['sales_net' => 24.0, 'w_sales_net' => 24.0, 'quelle' => 'legacy', 'vollstaendig' => true],
        'gold-preislos' => ['sales_net' => null, 'w_sales_net' => null, 'quelle' => 'legacy', 'vollstaendig' => false],
    ]);

    // Unbeteiligte Felder bleiben, was sie waren — der Umbau fasst nur die Preis-Zahl an.
    expect($pool[$ohneDarr->id]['wirtschaft']['ek_portion'])->toBe(7.0)
        ->and($pool[$ohnePreis->id]['wirtschaft']['db_eur'])->toBeNull()
        ->and($pool[$spiegel->id]['wirtschaft']['wareneinsatz_pct'])->toBe(27.0);
});

it('VERSCHIEBUNG: bei Abweichung gewinnt die Darreichung — die Legacy-Zahl taucht im Pool nicht mehr auf', function () {
    // Genau der Fall aus V-046: `SalesRecipeService::updateVk` schreibt an der Darreichung,
    // die Legacy-Spalte ist stehengeblieben. Vor dem Umbau stand hier 99.00 im Pool.
    $r = ($this->mkGericht)('shift', 'HG: Neu bepreist', ['sales_net' => 99.00]);
    ($this->mkDarreichung)($r, 20.00, 6.00);

    $k = $this->pool->fuerFrame($this->rootTeam, $this->frame, false, true)[$r->id];

    expect($k['sales_net'])->toBe(20.0)
        ->and($k['preis_quelle'])->toBe('darreichung');
});

it('VERSCHIEBUNG: der harte Preisfilter wirft ein Gericht nicht mehr wegen der Legacy-Spalte raus', function () {
    // Band 15–25 €. Beide Gerichte sind an der Darreichung gepflegt; das eine hat eine
    // veraltete Legacy-Spalte über dem Band, das andere darunter. Vor dem Umbau fielen
    // BEIDE aus dem Pool — ohne dass die Begründung die Quelle genannt hätte.
    $zuHoch = ($this->mkGericht)('band-hoch', 'HG: Legacy zu hoch', ['sales_net' => 99.00]);
    ($this->mkDarreichung)($zuHoch, 20.00, 6.00);
    $zuNiedrig = ($this->mkGericht)('band-niedrig', 'HG: Legacy zu niedrig', ['sales_net' => 4.00]);
    ($this->mkDarreichung)($zuNiedrig, 22.00, 6.00);
    // Gegenprobe: an der Darreichung wirklich außerhalb → fliegt weiter raus (der Filter
    // wird nicht weich, er liest nur die andere Zahl).
    $echtDraussen = ($this->mkGericht)('band-raus', 'HG: Echt zu teuer', ['sales_net' => 20.00]);
    ($this->mkDarreichung)($echtDraussen, 40.00, 6.00);

    $slot = $this->frames->addSlot($this->rootTeam, $this->frame, [
        'label' => 'Hauptgänge', 'price_min' => 15.00, 'price_max' => 25.00,
    ]);
    $this->frame->load(['slots.rules', 'rules']);

    $pool = $this->pool->fuerFrame($this->rootTeam, $this->frame);
    $gefiltert = $this->pool->filterFuerSlot($pool, $this->frame, $slot);

    expect($gefiltert->keys()->sort()->values()->all())->toBe(collect([$zuHoch->id, $zuNiedrig->id])->sort()->values()->all());
});

it('EINE Zahl: Pool-sales_net und die Wirtschafts-Achse sind per Konstruktion identisch', function () {
    // Der eigentliche Zweck des Umbaus. Vorher konnten die beiden Felder im GLEICHEN
    // Kandidaten-Array verschiedene Preise tragen — der Filter las das eine, die
    // Zielfunktion das andere.
    ($this->mkDarreichung)(($this->mkGericht)('eins-a', 'HG: A', ['sales_net' => 99.00]), 20.00, 6.00);
    ($this->mkDarreichung)(($this->mkGericht)('eins-b', 'HG: B', ['sales_net' => 10.00]), null, 4.00);
    ($this->mkGericht)('eins-c', 'HG: C', ['sales_net' => 12.00, 'ek_total_eur' => 5.00]);
    ($this->mkGericht)('eins-d', 'HG: D');

    $pool = $this->pool->fuerFrame($this->rootTeam, $this->frame, false, true);

    expect($pool)->toHaveCount(4);
    foreach ($pool as $k) {
        expect($k['sales_net'])->toBe($k['wirtschaft']['sales_net'], "Kandidat {$k['name']} trägt zwei Preise");
    }

    // Die Quelle ist VK-BEZOGEN und damit schärfer als `wirtschaft.quelle` (die das
    // Zahlenpaar VK+EK beschreibt und `gemischt` kennt): 'darreichung' genau dann, wenn
    // die Standard-Darreichung einen Preis trägt, sonst 'legacy', sonst 'keine'.
    $quellen = $pool->mapWithKeys(fn ($k, $id) => [FoodAlchemistRecipe::find($id)->recipe_key => $k['preis_quelle']])->all();
    expect($quellen)->toBe([
        'eins-a' => 'darreichung',   // 20 € an der Darreichung schlägt 99 € Legacy
        'eins-b' => 'legacy',        // Darreichung ohne Preis → Legacy-Spalte
        'eins-c' => 'legacy',        // gar keine Darreichung
        'eins-d' => 'keine',         // nirgends ein Preis
    ]);
});

it('die Preis-Wahrheit kostet KEINE Query je Gericht — auch im billigen Modus', function () {
    // V-046 verlangt die Darreichungs-Relation IMMER eager (nicht nur bei $mitWirtschaft).
    // Geprüft wird die Ableitung, nicht der Absolutwert: doppelte Gericht-Zahl, gleiche
    // Query-Zahl (Muster AnkerAufloesungBatchTest aus Lauf 43).
    $baue = function (int $n, string $praefix) {
        for ($i = 0; $i < $n; $i++) {
            $r = ($this->mkGericht)("{$praefix}-{$i}", "HG: {$praefix} {$i}", ['sales_net' => 10.00 + $i]);
            ($this->mkDarreichung)($r, 10.00 + $i, 4.00);
        }
    };
    $zaehle = function (): array {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $pool = $this->pool->fuerFrame($this->rootTeam, $this->frame);
        $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return [$pool->count(), $n];
    };

    $baue(4, 'q4');
    $zaehle();                       // Warmlauf: `neutral`-Anker-Memoisierung aus der Messung halten
    [$anzahlA, $queriesA] = $zaehle();
    $baue(4, 'q8');
    [$anzahlB, $queriesB] = $zaehle();

    expect($anzahlA)->toBe(4)
        ->and($anzahlB)->toBe(8)
        ->and($queriesB)->toBe($queriesA);
});
