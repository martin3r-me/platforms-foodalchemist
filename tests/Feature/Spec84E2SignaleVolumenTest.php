<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\SignalObjectService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 E-2 PR B · reine Gleichheit + weniger geladene Zeilen: signaleAmObjekt (SQL-Vorfilter), volumenProxyRaw
 * (Unterabfrage statt ID-Liste), veraltetePreise (COUNT in der Datenbank).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->signals = app(SignalService::class);
    $this->objekte = app(SignalObjectService::class);
});

it('Spec 84 E-2 · signaleAmObjekt: gleiches Ergebnis wie die Prüfung über alle offenen Signale, unbeteiligte werden nicht geladen', function () {
    $ziel = $this->makeRecipe($this->rootTeam, 'Zielrezept');
    $this->makeIngredient($ziel, 'Zwiebeln', null);
    $ziel->update(['n_ingredients_unmapped' => 1]);
    $anderes = $this->makeRecipe($this->rootTeam, 'Anderes');

    $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Kritisch, 'Marge', ['ref_type' => 'recipe', 'ref_id' => $ziel->id, 'dedup_key' => 'm-z']);
    $this->signals->erzeuge($this->rootTeam, SignalTyp::RezeptZutatenUngemappt, SignalSeverity::Warnung, 'Lücke', ['dedup_key' => 'dq-x', 'source' => 'data-quality', 'payload' => ['metrik' => 'rezept_zutaten_ungemappt']]);
    $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Info, 'Beispiele', ['dedup_key' => 'bsp', 'payload' => ['beispiele' => [['recipe_id' => $ziel->id]]]]);
    for ($k = 0; $k < 40; $k++) {   // unbeteiligt: Bezug auf ein anderes Rezept, ohne Metrik/Beispiele
        $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Info, "Rauschen {$k}", ['ref_type' => 'recipe', 'ref_id' => $anderes->id, 'dedup_key' => "r-{$k}"]);
    }

    // Referenz = bisheriger Weg: alle offenen Signale laden, betrifft() entscheidet
    $betrifft = new ReflectionMethod($this->objekte, 'betrifft');
    $cache = [];
    $erwartet = FoodAlchemistSignal::visibleToTeam($this->rootTeam)->offen()->get()
        ->filter(function ($s) use ($betrifft, $ziel, &$cache) {
            $args = [$this->rootTeam, $s, 'recipe', $ziel->id, &$cache];

            return $betrifft->invokeArgs($this->objekte, $args);
        })->pluck('id')->sort()->values()->all();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $neu = $this->objekte->signaleAmObjekt($this->rootTeam, 'recipe', $ziel->id);
    $haupt = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'foodalchemist_signals'));
    DB::disableQueryLog();

    expect(collect($neu)->pluck('id')->sort()->values()->all())->toBe($erwartet)
        ->and(count($erwartet))->toBe(3)
        ->and($haupt['query'])->toContain('"payload" like ?')   // der Vorfilter steht in der Abfrage
        ->and($haupt['bindings'])->toContain('%"metrik"%');
    expect(FoodAlchemistSignal::visibleToTeam($this->rootTeam)->offen()->count())->toBe(43);
});

it('Spec 84 E-2 · volumenProxyRaw: Unterabfrage liefert dasselbe wie die ID-Liste', function () {
    $sup = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Volumen-Lieferant']);
    for ($k = 0; $k < 4; $k++) {
        $gp = $this->makeGp($this->rootTeam, "Volumen GP {$k}");
        $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => "LA {$k}"]);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);
        $r = $this->makeRecipe($this->rootTeam, "Volumen Rezept {$k}");
        $this->makeIngredient($r, "Zutat {$k}", $gp);
    }
    $svc = app(SupplierService::class);
    $neu = (new ReflectionMethod($svc, 'volumenProxyRaw'))->invoke($svc, $this->rootTeam);

    $ids = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($this->rootTeam)->pluck('id');
    $alt = DB::table('foodalchemist_recipe_ingredients AS ri')
        ->join('foodalchemist_gps AS g', 'g.id', '=', 'ri.gp_id')
        ->join('foodalchemist_supplier_items AS si', 'si.id', '=', 'g.lead_la_supplier_item_id')
        ->whereNull('ri.deleted_at')->whereIn('ri.recipe_id', $ids)
        ->selectRaw('si.supplier_id, COUNT(*) AS n')->groupBy('si.supplier_id')->pluck('n', 'supplier_id')->map(fn ($n) => (int) $n)->all();

    expect($neu)->toBe($alt)->and($neu[$sup->id] ?? 0)->toBe(4)
        ->and((new ReflectionMethod($svc, 'volumenProxyRaw'))->invoke($svc, \Platform\Core\Models\Team::create(['name' => 'Leer', 'user_id' => 1, 'personal_team' => false])))->toBe([]);   // ohne sichtbare Rezepte: leer wie bisher
});

it('Spec 84 E-2 · veraltetePreise: COUNT in der Datenbank = bisher geladene ID-Zahl', function () {
    $sup = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Alt-Lieferant']);
    for ($k = 0; $k < 5; $k++) {
        $gp = $this->makeGp($this->rootTeam, "Alt GP {$k}");
        $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => "Alt LA {$k}"]);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);
        if ($k < 2) {   // zwei mit frischem Preis, drei ohne Preis = veraltet
            \Platform\FoodAlchemist\Models\FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 3.0, 'status' => '0', 'status_valid_from' => now()]);
        }
    }

    expect(app(SignalDetektorService::class)->veraltetePreise($this->rootTeam))->toBe(1)   // ein Sammel-Signal …
        ->and(FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->where('dedup_key', 'veraltete-preise')->first()->payload['anzahl'])->toBe(3);   // … mit drei veralteten GPs
});
