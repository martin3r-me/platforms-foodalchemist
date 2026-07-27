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
 * 12·S2a-1 (R2.4) — der geteilte Kandidaten-Pool und seine Wirtschafts-Achse.
 *
 * Zwei Dinge werden hier festgenagelt: (1) der Pool bleibt die EINE Auswahl-Wahrheit
 * (nur echte VK-Gerichte, Draft/Variante fliegen raus, Team-Grenze hält) und (2) das
 * DB je Portion kommt aus dem Zahlenpaar der Standard-Darreichung — derselben Quelle,
 * aus der die W%-Ampel liest. Ein Gericht ohne Preis oder ohne EK bleibt im Pool und
 * ist als `vollstaendig=false` benannt, statt still zu verschwinden.
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
    $this->mkDarreichung = function (FoodAlchemistRecipe $r, ?float $vk, ?float $ek): FoodAlchemistRecipeDarreichung {
        return FoodAlchemistRecipeDarreichung::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->sf->id,
            'is_standard' => true, 'sales_net' => $vk, 'ek_portion' => $ek,
        ]);
    };

    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Pool-FB']);
    $this->frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    $this->frame->loadMissing(['slots.rules', 'rules']);
});

it('Pool trägt nur echte VK-Gerichte — Draft, Slot-Variante und Basisrezept fallen raus', function () {
    $echt = ($this->mkGericht)('echt', 'HG: Echtes Gericht', ['sales_net' => 20.00]);
    ($this->mkGericht)('entwurf', 'HG: Entwurf', ['sales_net' => 20.00, 'status' => 'draft']);
    ($this->mkGericht)('basis', 'Basis: Fond', ['is_sales_recipe' => false]);
    ($this->mkGericht)('variante', 'HG: Variante', ['sales_net' => 20.00, 'variant_source_recipe_id' => $echt->id]);

    $pool = $this->pool->fuerFrame($this->rootTeam, $this->frame);

    expect($pool->keys()->all())->toBe([$echt->id]);
});

it('ohne mitWirtschaft kostet die DB-Achse nichts — der Block bleibt null', function () {
    $r = ($this->mkGericht)('ohne', 'HG: Ohne Wirtschaft', ['sales_net' => 20.00]);
    ($this->mkDarreichung)($r, 20.00, 6.00);

    $pool = $this->pool->fuerFrame($this->rootTeam, $this->frame);

    expect($pool[$r->id]['wirtschaft'])->toBeNull();
});

it('DB je Portion kommt aus der Standard-Darreichung: VK − EK, W% = EK/VK', function () {
    $r = ($this->mkGericht)('db', 'HG: Mit Darreichung', ['sales_net' => 99.00]);   // Legacy-Spalte absichtlich falsch
    ($this->mkDarreichung)($r, 20.00, 6.00);

    $w = $this->pool->fuerFrame($this->rootTeam, $this->frame, false, true)[$r->id]['wirtschaft'];

    // Die Darreichung schlägt die Legacy-Spalte (M2-Preis-Wahrheit) — 99 € tauchen nirgends auf
    expect($w['quelle'])->toBe('darreichung')
        ->and($w['vollstaendig'])->toBeTrue()
        ->and($w['sales_net'])->toBe(20.0)
        ->and($w['ek_portion'])->toBe(6.0)
        ->and($w['db_eur'])->toBe(14.0)
        ->and($w['db_pct'])->toBe(70.0)
        ->and($w['wareneinsatz_pct'])->toBe(30.0);
});

it('ohne Darreichung fällt die Achse auf die Legacy-Spalten zurück — EK durch Verkaufseinheiten geteilt', function () {
    $r = ($this->mkGericht)('legacy', 'HG: Legacy', [
        'sales_net' => 10.00, 'ek_total_eur' => 8.00, 'sales_unit_count' => 4,
    ]);

    $w = $this->pool->fuerFrame($this->rootTeam, $this->frame, false, true)[$r->id]['wirtschaft'];

    expect($w['quelle'])->toBe('legacy')
        ->and($w['ek_portion'])->toBe(2.0)      // 8 € / 4 Einheiten
        ->and($w['db_eur'])->toBe(8.0)
        ->and($w['wareneinsatz_pct'])->toBe(20.0);
});

it('fehlender EK verengt den Pool NICHT — der Kandidat bleibt, aber als benannte Lücke', function () {
    $ohneEk = ($this->mkGericht)('ohne-ek', 'HG: Preis ohne EK', ['sales_net' => 15.00]);
    $ohneVk = ($this->mkGericht)('ohne-vk', 'HG: EK ohne Preis', ['ek_total_eur' => 3.00]);

    $pool = $this->pool->fuerFrame($this->rootTeam, $this->frame, false, true);

    expect($pool)->toHaveCount(2);
    foreach ([$ohneEk->id, $ohneVk->id] as $id) {
        expect($pool[$id]['wirtschaft']['vollstaendig'])->toBeFalse()
            ->and($pool[$id]['wirtschaft']['db_eur'])->toBeNull();
    }
});

it('halb gepflegte Darreichung wird als gemischte Quelle ausgewiesen, nicht als saubere', function () {
    $r = ($this->mkGericht)('gemischt', 'HG: Halb gepflegt', ['sales_net' => 30.00, 'ek_total_eur' => 9.00]);
    ($this->mkDarreichung)($r, 24.00, null);      // Preis an der Darreichung, EK nur legacy

    $w = $this->pool->fuerFrame($this->rootTeam, $this->frame, false, true)[$r->id]['wirtschaft'];

    expect($w['quelle'])->toBe('gemischt')
        ->and($w['sales_net'])->toBe(24.0)
        ->and($w['ek_portion'])->toBe(9.0)
        ->and($w['db_eur'])->toBe(15.0);
});

it('die DB-Achse kostet EINE Query, nicht eine je Gericht', function () {
    for ($i = 0; $i < 12; $i++) {
        $r = ($this->mkGericht)("perf-{$i}", "HG: Perf {$i}", ['sales_net' => 10.00 + $i]);
        ($this->mkDarreichung)($r, 10.00 + $i, 4.00);
    }

    $zaehle = function (bool $mitWirtschaft) {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $pool = $this->pool->fuerFrame($this->rootTeam, $this->frame, false, $mitWirtschaft);
        $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return [$pool, $n];
    };

    // Warmlauf VOR der Messung: `PairingService` löst den `neutral`-Anker seit V-045 einmal
    // je Instanz auf (memoisiert). Ohne Warmlauf zahlte nur die erste der beiden Messungen
    // diesen einen Lookup — der Delta läse dadurch 0 statt 1 und würde eine echte
    // Regression der Wirtschafts-Achse verdecken.
    $zaehle(false);

    [, $ohne] = $zaehle(false);
    [$pool, $mit] = $zaehle(true);

    // Gemessen wird der DELTA der neuen Achse: die Darreichungs-Relation ist eager, also +1.
    // Zusätzlich eine absolute Obergrenze als Regressions-Riegel. Verlauf über die zwei
    // V-045-Halbschritte für dieselben 12 zutatenlosen Gerichte: **38 → 27 → 4**. Der erste
    // Halbschritt hat den `neutral`-Lookup memoisiert und die Zutaten-Requery bei geladener
    // Relation abgestellt; der zweite (`resolveRecipeAnchorsMany`) hat die letzten beiden
    // Per-Gericht-Queries gezogen — die Zutaten-Ladung läuft jetzt als EIN `whereIn` über den
    // ganzen Pool, und ohne GP-/Sub-Zutaten entfallen die Mapping-Batches ganz.
    // **Der Riegel gegen Proportionalität steht in `AnkerAufloesungBatchTest`** (doppelte
    // Gericht-Zahl, gleiche Query-Zahl) — eine absolute Zahl allein wäre auf proportionalem
    // Verhalten grün, solange sie nur passt.
    expect($pool)->toHaveCount(12)
        ->and($pool->every(fn ($k) => $k['wirtschaft']['vollstaendig'] === true))->toBeTrue()
        ->and($mit - $ohne)->toBe(1)
        ->and($ohne)->toBeLessThanOrEqual(6);
});

it('V-045: bei eager geladenen Zutaten fragt die Anker-Auflösung sie NICHT neu', function () {
    // Begriffe-Modus scharfstellen — er ist die Bedingung, unter der `fuerFrame` die Zutaten
    // samt gp/referencedRecipe eager lädt. Genau dann darf `resolveRecipeAnchors` sie nicht
    // ein zweites Mal holen.
    $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'value_text' => 'Leber']);
    $this->frame->load(['slots.rules', 'rules']);

    $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'v045|butter', 'name' => 'Butter', 'status' => 'approved',
    ]);
    for ($i = 0; $i < 6; $i++) {
        $r = ($this->mkGericht)("v045-{$i}", "HG: Anker {$i}", ['sales_net' => 12.00]);
        // drei Zutaten je Gericht: unter der alten Requery kostete jedes Gericht eine eigene
        // Zutaten-Query, unabhängig davon, dass der Pool sie gerade eager geladen hatte.
        for ($z = 1; $z <= 3; $z++) {
            $this->makeIngredient($r, 'Butter', $gp, '100', $z);
        }
    }

    $warm = fn () => $this->pool->fuerFrame($this->rootTeam, $this->frame, false, false);
    $warm(); // `neutral`-Memoisierung aus der Messung halten

    \Illuminate\Support\Facades\DB::flushQueryLog();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $pool = $warm();
    $queries = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    // Der Riegel: keine einzige Query darf die Zutaten-Tabelle nach EINEM `recipe_id` fragen.
    // Der Eager-Load des Pools nutzt `whereIn` — eine Einzel-`where`-Abfrage ist genau die
    // Requery, die V-045 beschreibt. Identifier-Quoting vorher wegnormalisieren, damit der
    // Riegel auf SQLite ("recipe_id") UND MySQL (`recipe_id`) greift und nicht still grün wird.
    $requeries = collect($queries)
        ->map(fn ($q) => str_replace(['`', '"'], '', $q['query']))
        ->filter(
            fn (string $sql) => str_contains($sql, 'foodalchemist_recipe_ingredients')
                && str_contains($sql, 'recipe_id = ')
        );

    expect($pool)->toHaveCount(6)
        ->and($pool->every(fn ($k) => $k['anker'] !== null))->toBeTrue()
        ->and($requeries)->toBeEmpty();
});

it('Team-Grenze hält: fremde VK-Gerichte stehen nicht im Pool', function () {
    $eigen = ($this->mkGericht)('eigen', 'HG: Eigen', ['sales_net' => 20.00]);
    $fremdesTeam = \Platform\Core\Models\Team::create(['name' => 'Fremd-Caterer', 'user_id' => 1, 'personal_team' => false]);
    FoodAlchemistRecipe::create([
        'team_id' => $fremdesTeam->id, 'recipe_key' => 'fremd', 'name' => 'HG: Fremd',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 20.00,
    ]);

    $pool = $this->pool->fuerFrame($this->rootTeam, $this->frame, false, true);

    expect($pool->keys()->all())->toBe([$eigen->id]);
});
