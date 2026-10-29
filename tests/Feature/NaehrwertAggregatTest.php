<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\NaehrwertAggregatService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\NaehrwerteVorschauGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dominique 29.10.2026: „Eine Null ist oft echt (Salz: Fett 0, Zucker 0, kcal 0; Öl: Kohlenhydrate 0). Nur ALLE Kernwerte 0 heißt
 * nicht deklariert.“ Eine Regel für Erdung und Rezept-Nährwerte: Leer-Guard je Zeile, 0 = Messwert, NULL = fehlt, Median.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->mkLa = function (int $gpId, array $naehrwerte, bool $discontinued = false) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => 'LA ' . $gpId . '-' . uniqid(), 'is_discontinued' => $discontinued]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gpId]);
        FoodAlchemistItemNutritional::create(array_merge(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id], $naehrwerte));

        return $la;
    };
    $this->agg = app(GpAggregateService::class);
    $this->erdung = fn (int $gpId) => app(SensorikService::class)->erdungBulk([$gpId])[$gpId] ?? [];
});

it('eine deklarierte 0 ist ein Messwert: Pflaume Fett 0 ergibt fettig gemessen 0, Rezept-Fett 0 aus 1 Artikel', function () {
    $gp = $this->makeGp($this->rootTeam, 'Pflaume: ganz, frisch');
    ($this->mkLa)($gp->id, ['energy_kcal' => 47, 'fat' => 0, 'sugar' => 7.3, 'carbs_absorbable' => 10, 'protein' => 0.6, 'sodium' => 0]);

    $e = ($this->erdung)($gp->id);
    $n = $this->agg->naehrwerte($gp);

    expect($e['fettig']['gehalt'])->toBe(0.0)->and($e['fettig']['anzahl'])->toBe(1)
        ->and($e['salzig']['gehalt'])->toBe(0.0)
        ->and($n['fat']['avg'])->toBe(0.0)->and($n['fat']['n'])->toBe(1)
        ->and($n['salt_g']['avg'])->toBe(0.0);
});

it('NULL heißt „fehlt“ und zählt nicht, auch neben einer 0', function () {
    $gp = $this->makeGp($this->rootTeam, 'Schweineschulter: schier, frisch');
    ($this->mkLa)($gp->id, ['energy_kcal' => 180, 'fat' => 10.8, 'protein' => 19, 'sugar' => 0]);          // sodium NULL

    expect(($this->erdung)($gp->id))->not->toHaveKey('salzig')->toHaveKey('suess')
        ->and($this->agg->naehrwerte($gp)['sodium']['n'])->toBe(0);
});

it('eine Zeile mit lauter Nullen ist „nicht deklariert“, auch in den Rezept-Nährwerten (kcal unbekannt statt 0)', function () {
    $leer = $this->makeGp($this->rootTeam, 'Rinderbug: TK');
    ($this->mkLa)($leer->id, ['energy_kcal' => 0, 'fat' => 0, 'protein' => 0, 'carbs_absorbable' => 0, 'sugar' => 0, 'sodium' => 0]);
    $gemischt = $this->makeGp($this->rootTeam, 'Rindergulasch: TK');
    ($this->mkLa)($gemischt->id, ['energy_kcal' => 0, 'fat' => 0, 'protein' => 0, 'carbs_absorbable' => 0, 'sugar' => 0, 'sodium' => 0]);
    ($this->mkLa)($gemischt->id, ['energy_kcal' => 160, 'fat' => 8]);

    expect($this->agg->naehrwerte($leer)['energy_kcal']['avg'])->toBeNull()
        ->and($this->agg->naehrwerte($leer)['source'])->toBe('keine')
        ->and($this->agg->naehrwerte($gemischt)['energy_kcal']['avg'])->toBe(160.0)                       // vorher Ø mit der Null-Zeile: 80
        ->and($this->agg->naehrwerte($gemischt)['energy_kcal']['n'])->toBe(1);
});

it('Salz pur bleibt ehrlich: kcal 0 und Fett 0 sind Messwerte, die Zeile zählt über das Natrium', function () {
    $gp = $this->makeGp($this->rootTeam, 'Speisesalz');
    ($this->mkLa)($gp->id, ['energy_kcal' => 0, 'fat' => 0, 'sugar' => 0, 'sodium' => 39300]);

    $n = $this->agg->naehrwerte($gp);
    expect($n['energy_kcal']['avg'])->toBe(0.0)->and($n['source'])->toBe('la')
        ->and(($this->erdung)($gp->id)['fettig']['gehalt'])->toBe(0.0);
});

it('Median statt Mittelwert: ein falscher Artikel unter drei verschiebt den Wert nicht', function () {
    $gp = $this->makeGp($this->rootTeam, 'Rapsöl: raffiniert');
    ($this->mkLa)($gp->id, ['energy_kcal' => 828, 'fat' => 92, 'sodium' => 0]);
    ($this->mkLa)($gp->id, ['energy_kcal' => 900, 'fat' => 100, 'sodium' => 0]);
    ($this->mkLa)($gp->id, ['energy_kcal' => 98, 'fat' => 2.5, 'sodium' => 2000]);                        // Mazola-Fall von io

    $n = $this->agg->naehrwerte($gp);
    expect($n['fat']['avg'])->toBe(92.0)->and($n['energy_kcal']['avg'])->toBe(828.0)->and($n['fat']['n'])->toBe(3)
        ->and(($this->erdung)($gp->id)['fettig']['gehalt'])->toBe(92.0)
        ->and(($this->erdung)($gp->id)['salzig']['gehalt'])->toBe(0.0);
});

it('Median: gerade Anzahl = Mittel der beiden mittleren, leere Liste = null', function () {
    expect(NaehrwertAggregatService::median([4.0, 1.0, 3.0, 2.0]))->toBe(2.5)
        ->and(NaehrwertAggregatService::median([5.0]))->toBe(5.0)
        ->and(NaehrwertAggregatService::median([]))->toBeNull();
});

it('ausgelistete Artikel zählen weiter nicht', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zucker');
    ($this->mkLa)($gp->id, ['sugar' => 100, 'carbs_absorbable' => 100, 'energy_kcal' => 400]);
    ($this->mkLa)($gp->id, ['sugar' => 0, 'carbs_absorbable' => 0, 'energy_kcal' => 1], discontinued: true);

    expect($this->agg->naehrwerte($gp)['sugar']['avg'])->toBe(100.0)->and($this->agg->naehrwerte($gp)['sugar']['n'])->toBe(1);
});

// ── MCP-Vorschau ─────────────────────────────────────────────────────────────

it('naehrwerte_vorschau.GET zeigt vorher/nachher je Achse und die Rezept-Wirkung', function () {
    $pflaume = $this->makeGp($this->rootTeam, 'Pflaume: ganz, frisch');
    ($this->mkLa)($pflaume->id, ['energy_kcal' => 47, 'fat' => 0, 'sugar' => 7.3, 'carbs_absorbable' => 10]);
    $leer = $this->makeGp($this->rootTeam, 'Rinderbug: TK');
    ($this->mkLa)($leer->id, ['energy_kcal' => 0, 'fat' => 0, 'protein' => 0, 'carbs_absorbable' => 0, 'sugar' => 0, 'sodium' => 0]);
    $oel = $this->makeGp($this->rootTeam, 'Rapsöl: raffiniert');
    ($this->mkLa)($oel->id, ['energy_kcal' => 828, 'fat' => 92]);
    ($this->mkLa)($oel->id, ['energy_kcal' => 900, 'fat' => 100]);
    ($this->mkLa)($oel->id, ['energy_kcal' => 98, 'fat' => 2.5]);
    $unveraendert = $this->makeGp($this->rootTeam, 'Butter');
    ($this->mkLa)($unveraendert->id, ['energy_kcal' => 740, 'fat' => 82]);
    $r = $this->makeRecipe($this->rootTeam, 'Rinderbug: geschmort', ['is_sales_recipe' => false]);
    $this->makeIngredient($r, 'Rinderbug', $leer, '1000', 1);

    $res = (new NaehrwerteVorschauGetTool)->execute(['limit' => 5], new ToolContext(auth()->user(), $this->rootTeam));
    expect($res->success)->toBeTrue();
    $d = $res->data;

    expect($d['gps_mit_naehrwertzeilen'])->toBe(4)
        ->and($d['erdung']['fettig']['neu_gemessen'])->toBe(1)                                             // Pflaume: 0 war „nicht deklariert“
        ->and($d['erdung']['fettig']['beispiele_neu_oder_entfaellt'][0])->toMatchArray(['gp_id' => $pflaume->id, 'vorher' => null, 'nachher' => 0.0])
        ->and($d['erdung']['fettig']['steigt'])->toBe(1)                                                   // Öl: Ø 64,8 → Median 92
        ->and($d['erdung']['fettig']['groesste_spruenge'][0])->toMatchArray(['gp_id' => $oel->id, 'vorher' => 64.83, 'nachher' => 92.0])
        ->and($d['rezept_naehrwerte']['kcal']['entfaellt'])->toBe(1)                                       // Rinderbug: 0 → unbekannt
        ->and($d['rezept_wirkung']['gps_kcal_0_wird_unbekannt'])->toBe(1)
        ->and($d['rezept_wirkung']['rezepte_mit_betroffener_zutat'])->toBe(1)
        ->and(collect($d['rezept_naehrwerte']['fett']['groesste_spruenge'])->pluck('gp_id')->all())->not->toContain($unveraendert->id);
});

it('naehrwerte_vorschau.GET schreibt nichts und grenzt per gp_ids ein', function () {
    $gp = $this->makeGp($this->rootTeam, 'Pflaume: ganz, frisch');
    ($this->mkLa)($gp->id, ['energy_kcal' => 47, 'fat' => 0]);
    $anderes = $this->makeGp($this->rootTeam, 'Rinderbug: TK');
    ($this->mkLa)($anderes->id, ['energy_kcal' => 0, 'fat' => 0]);
    $vorher = FoodAlchemistItemNutritional::query()->get()->toArray();

    $d = (new NaehrwerteVorschauGetTool)->execute(['gp_ids' => [$gp->id]], new ToolContext(auth()->user(), $this->rootTeam))->data;

    expect($d['gps_mit_naehrwertzeilen'])->toBe(1)
        ->and(FoodAlchemistItemNutritional::query()->get()->toArray())->toBe($vorher);
});

it('naehrwerte_vorschau.GET: nur Kuratieren; der Betrachter bekommt FORBIDDEN', function () {
    $viewer = $this->makeUser($this->rootTeam, 'Gast', 'viewer');
    expect((new NaehrwerteVorschauGetTool)->execute([], new ToolContext($viewer, $this->rootTeam))->errorCode)->toBe('FORBIDDEN');
});
