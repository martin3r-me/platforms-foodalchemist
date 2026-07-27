<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 12·S2a-1b (Punkt 4) — V-049 `recomputeMany` + V-050 `preisSprungMargeImpact($gpIds)`.
 *
 * Drei Zusicherungen, jede gegen eine eigene Fehlerklasse:
 *
 * 1. **Mengengleichheit.** Die Batch-BFS darf gegenüber der Vereinigung der Einzel-BFS
 *    weder ein Rezept verlieren (⇒ stale Kosten, die niemand meldet) noch eines
 *    hinzuerfinden. Der Riegel misst gegen einen **Nachbau der alten Einzel-BFS** im
 *    Test, nicht gegen `betroffeneRezepte()` — das delegiert seit V-049 selbst an die
 *    Batch-Variante und wäre als Orakel wertlos.
 * 2. **Einzigkeit + Ordnung.** Der Gewinn ist „jedes Rezept genau einmal"; die Gefahr
 *    dabei ist, die topologische Ordnung (Kinder vor Eltern) zu verlieren. Gemessen wird
 *    beides an derselben Sequenz — und die Gegenprobe (Schleife über
 *    `recomputeAndPropagate`) zeigt die alte Zahl.
 * 3. **Zielgenauigkeit des Detektors.** Mit `$gpIds` ersetzt die übergebene Menge die
 *    Team-weite Suche — ohne die D1-Grenze zu verschieben.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->recompute = app(RecipeRecomputeService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);

    /** GP mit Lead-LA und aktivem Preis (€/kg) — Muster aus IngredientSwapPropagationTest. */
    $this->mkGpMitPreis = function (string $name, float $preis, ?\Platform\Core\Models\Team $owner = null) {
        $owner ??= $this->rootTeam;
        $gp = $this->makeGp($owner, $name);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $owner->id, 'supplier_id' => $this->supplier->id,
            'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $owner->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $owner->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return [$gp->refresh(), $la];
    };

    /** Sub-Zutat direkt am Model — bewusst OHNE RecipeService, damit das Fixture nicht selbst propagiert. */
    $this->mkSubZutat = function (FoodAlchemistRecipe $eltern, FoodAlchemistRecipe $sub, int $position = 1): void {
        FoodAlchemistRecipeIngredient::create([
            'team_id' => $eltern->team_id, 'recipe_id' => $eltern->id,
            'referenced_recipe_id' => $sub->id, 'raw_text' => $sub->name,
            'quantity' => '1000', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id,
            'position' => $position,
        ]);
    };
});

/**
 * Nachbau der Einzel-BFS, wie sie vor V-049 in `betroffeneRezepte()` stand — das Orakel
 * für die Mengengleichheit. `PROPAGATION_LIMIT` ist im Service privat und hier bewusst
 * als Literal gespiegelt: wandert die Grenze, muss dieser Riegel bewusst nachgezogen
 * werden statt still mitzuwandern.
 *
 * @return list<int>
 */
function einzelBfsAlt(int $recipeId): array
{
    $besucht = [$recipeId => true];
    $ebene = [$recipeId];
    for ($tiefe = 0; $tiefe < 10 && $ebene !== []; $tiefe++) {
        $eltern = FoodAlchemistRecipeIngredient::whereIn('referenced_recipe_id', $ebene)
            ->whereNull('deleted_at')->distinct()->pluck('recipe_id')
            ->reject(fn ($id) => isset($besucht[$id]))->values()->all();
        foreach ($eltern as $parentId) {
            $besucht[$parentId] = true;
        }
        $ebene = $eltern;
    }

    return array_map('intval', array_keys($besucht));
}

/** Wie oft wurde genau dieses Rezept von `recomputePipeline` geladen? (Ein Load = ein Lauf.) */
function pipelineLaeufe(array $recipeIds, callable $arbeit): array
{
    $treffer = array_fill_keys($recipeIds, 0);
    $reihenfolge = [];
    DB::listen(function ($q) use (&$treffer, &$reihenfolge) {
        if (! str_contains($q->sql, 'from "foodalchemist_recipes"') || ! str_contains($q->sql, 'limit 1')) {
            return;
        }
        foreach ($q->bindings as $b) {
            if (is_int($b) && array_key_exists($b, $treffer)) {
                $treffer[$b]++;
                $reihenfolge[] = $b;
            }
        }
    });
    $arbeit();

    return ['treffer' => $treffer, 'reihenfolge' => $reihenfolge];
}

it('V-049: die Batch-BFS ist mengengleich mit der Vereinigung der Einzel-BFS — auch an der Tiefen-Grenze', function () {
    // Kette r0 ← r1 ← … ← r12 (r1 hat r0 als Sub usw.) — 12 Ebenen, also zwei mehr als
    // PROPAGATION_LIMIT. Genau dort trennt sich die Batch- von der Einzel-Sicht, wenn die
    // Distanz-Rechnung nicht stimmt: die gemeinsame BFS vergibt die KLEINSTE Distanz zu
    // irgendeinem Start, die Einzel-BFS die zum jeweiligen Start.
    $kette = [];
    for ($i = 0; $i <= 12; $i++) {
        $kette[$i] = $this->makeRecipe($this->rootTeam, "Kette {$i}");
        if ($i > 0) {
            ($this->mkSubZutat)($kette[$i], $kette[$i - 1]);
        }
    }
    // Diamant unten: r0 hat zwei Wege in dieselbe Kette (r1 und diamant → r2). Bewusst
    // KEINE Abkürzung nach oben — die würde den Tiefen-Deckel entschärfen, und genau der
    // ist hier der interessante Fall.
    $diamant = $this->makeRecipe($this->rootTeam, 'Diamant');
    ($this->mkSubZutat)($diamant, $kette[0]);
    ($this->mkSubZutat)($kette[2], $diamant, 2);

    $vergleiche = function (array $starts) {
        $union = [];
        foreach ($starts as $id) {
            foreach (einzelBfsAlt($id) as $rid) {
                $union[$rid] = true;
            }
        }
        $erwartet = array_keys($union);
        sort($erwartet);
        $ist = $this->recompute->betroffeneRezepteMany($starts);
        sort($ist);

        return [$erwartet, $ist];
    };

    // Ein Start (der Ein-Element-Fall MUSS identisch bleiben — daran hängen alle Bestands-Aufrufer).
    // Der Deckel muss dabei WIRKLICH greifen, sonst prüft der Riegel den langweiligen Fall.
    [$erwartet, $ist] = $vergleiche([$kette[0]->id]);
    expect($ist)->toBe($erwartet)
        ->and($ist)->not->toContain($kette[11]->id)
        ->and($ist)->not->toContain($kette[12]->id)
        ->and($ist)->toContain($kette[10]->id);

    // Zwei Starts auf verschiedenen Höhen derselben Kette
    [$erwartet, $ist] = $vergleiche([$kette[0]->id, $kette[5]->id]);
    expect($ist)->toBe($erwartet);

    // Start ganz oben + Start in der Mitte + Dublette in der Eingabe
    [$erwartet, $ist] = $vergleiche([$kette[12]->id, $kette[3]->id, $kette[3]->id]);
    expect($ist)->toBe($erwartet);

    expect($this->recompute->betroffeneRezepteMany([]))->toBe([]);
});

it('V-049: ein gemeinsames Eltern-Gericht wird EINMAL gerechnet, nicht einmal je Kind', function () {
    [$gp] = ($this->mkGpMitPreis)('Limette', 4.00);

    $subs = [];
    foreach (['A', 'B', 'C'] as $k) {
        $s = $this->makeRecipe($this->rootTeam, "Sub {$k}");
        $this->makeIngredient($s, 'Limette', $gp, '1000');
        $subs[] = $s;
    }
    $eltern = $this->makeRecipe($this->rootTeam, 'Gericht: Salat');
    foreach ($subs as $i => $s) {
        ($this->mkSubZutat)($eltern, $s, $i + 1);
    }
    $ids = array_map(fn ($s) => $s->id, $subs);

    $batch = pipelineLaeufe([...$ids, $eltern->id],
        fn () => $this->recompute->recomputeMany($ids));

    // Gegenprobe: die Schleife, die vier Aufrufer bisher geschrieben haben.
    $schleife = pipelineLaeufe([...$ids, $eltern->id], function () use ($ids) {
        foreach ($ids as $id) {
            $this->recompute->recomputeAndPropagate($id);
        }
    });

    expect($batch['treffer'][$eltern->id])->toBe(1)
        ->and($schleife['treffer'][$eltern->id])->toBe(3)      // dreimal dasselbe Gericht
        ->and(array_values(array_unique($batch['reihenfolge'])))->toHaveCount(4);

    // Ordnung: das Eltern-Gericht steht NACH allen drei Kindern (Diamond-Härtung I8).
    $pos = array_flip($batch['reihenfolge']);
    foreach ($ids as $id) {
        expect($pos[$id])->toBeLessThan($pos[$eltern->id]);
    }
});

it('V-049: der Ein-Element-Fall bleibt unverändert und die Kosten propagieren korrekt', function () {
    [$gp, $la] = ($this->mkGpMitPreis)('Limette', 4.00);
    $sub = $this->makeRecipe($this->rootTeam, 'Sub: Basis');
    $this->makeIngredient($sub, 'Limette', $gp, '1000');
    $eltern = $this->makeRecipe($this->rootTeam, 'Gericht: Salat');
    ($this->mkSubZutat)($eltern, $sub);

    expect($this->recompute->recomputeAndPropagate($sub->id))
        ->toBe($this->recompute->betroffeneRezepteMany([$sub->id]))
        ->and((float) $eltern->fresh()->ek_total_eur)->toBe(4.00);

    // Preis am LA bewegen, OHNE die Kette zu triggern — dann die Menge auf einmal rechnen.
    // Stünde das Eltern-Gericht vor seinem Sub in der Ordnung, läse es den alten Wert.
    DB::table('foodalchemist_prices')->where('supplier_item_id', $la->id)->update(['price' => 10.00]);
    $this->recompute->recomputeMany([$sub->id]);

    expect((float) $sub->fresh()->ek_total_eur)->toBe(10.00)
        ->and((float) $eltern->fresh()->ek_total_eur)->toBe(10.00);
});

it('V-050: der Detektor arbeitet auf der übergebenen GP-Menge statt team-weit — ohne D1 zu weiten', function () {
    $preise = app(\Platform\FoodAlchemist\Services\PriceService::class);

    $mkSprung = function (string $name) use ($preise) {
        [$gp, $la] = ($this->mkGpMitPreis)($name, 10.00);
        $r = $this->makeRecipe($this->rootTeam, "Gericht mit {$name}", ['is_sales_recipe' => true, 'sales_net' => 30.00]);
        $this->makeIngredient($r, $name, $gp, '1000');
        $this->recompute->recomputeAndPropagate($r->id);
        $preise->createFor($this->rootTeam, $la, 25.00);        // +150 % am Lead-LA
        $this->recompute->recomputeAndPropagate($r->id);

        return $gp;
    };

    $gpA = $mkSprung('Zander');
    $gpB = $mkSprung('Saibling');

    $svc = app(SignalDetektorService::class);
    $signaleFuer = fn () => \Platform\FoodAlchemist\Models\FoodAlchemistSignal::where('type', 'preis_sprung_marge_impact')
        ->pluck('ref_id')->map(fn ($v) => (int) $v)->sort()->values()->all();

    // Die übergebene Menge ersetzt die Team-weite Suche: gpB ist gleich frisch gesprungen,
    // bleibt aber unberührt — genau die Zielgenauigkeit, die V-050 verlangt.
    expect($svc->preisSprungMargeImpact($this->rootTeam, gpIds: [$gpA->id]))->toBe(1)
        ->and($signaleFuer())->toBe([$gpA->id]);

    // Ohne den Parameter bleibt der Scheduler-Pfad, wie er war: beide GPs.
    expect($svc->preisSprungMargeImpact($this->rootTeam))->toBe(2)
        ->and($signaleFuer())->toBe(collect([$gpA->id, $gpB->id])->sort()->values()->all());

    // D1: eine leere Menge findet nichts, und eine ID außerhalb der Team-Kette
    // erzeugt keine Sichtbarkeit (der Ancestry-Filter bleibt davor stehen).
    [$fremd] = ($this->mkGpMitPreis)('Fremd-GP', 10.00, $this->childA);
    expect($svc->preisSprungMargeImpact($this->rootTeam, gpIds: []))->toBe(0)
        ->and($svc->preisSprungMargeImpact($this->rootTeam, gpIds: [$fremd->id]))->toBe(0);
});
