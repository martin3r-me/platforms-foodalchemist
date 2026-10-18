<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 B3 Stufe 3 · Regression auf der io-Verteilung (Kurator 18.10.): alle Stamm-Zeilen global `stamm_paket`, Broich mit Opt-in für EINEN
 * Lieferanten, Foodpol ohne Opt-in, alle Preise beim Master. Ziel: Stufe 3 = heute (Stufe 0) auf jedem Pfad.
 * Wächter (Dominique 13.10., Spec 82): kein Mandant sieht einen Master-Preis — auch nicht über die Relation Preis→Artikel ('verweis');
 * preisSichtFuer bleibt der einzige Riegel.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->foodpol = Team::create(['name' => 'Foodpol', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();

    $this->lieferantFrei = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Großhandel A', 'stamm_class' => StammPaket::PAKET]);
    $this->lieferantZu = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Großhandel B', 'stamm_class' => StammPaket::PAKET]);
    $this->gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 's3|zander', 'name' => 'Zanderfilet', 'stamm_class' => StammPaket::PAKET]);
    $mkLa = fn ($lief, string $name) => FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $lief->id, 'designation' => $name,
        'qty' => 1.0, 'unit_code' => 'kg', 'stamm_class' => StammPaket::PAKET]);
    $this->laFrei = $mkLa($this->lieferantFrei, 'Zanderfilet 1 kg A');
    $this->laZu = $mkLa($this->lieferantZu, 'Zanderfilet 1 kg B');
    foreach ([$this->laFrei, $this->laZu] as $la) {
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id]);
        $this->masterPreise[] = app(PriceService::class)->createTeamPrice($this->master, $la, 12.50)->id;   // BHG-Konditionen
    }
    StammPaket::freischalten($this->broich);
    DB::table(StammPaket::LIEFERANTEN_TABELLE)->insert(['team_id' => $this->broich->id, 'supplier_id' => $this->lieferantFrei->id, 'created_at' => now(), 'updated_at' => now()]);
    StammPaket::flush();
    // eigene Zeilen der Mandanten, die auf den globalen Stamm verweisen
    $this->rezeptFoodpol = $this->makeRecipe($this->foodpol, 'Zander Foodpol');
    $this->zutatFoodpol = $this->makeIngredient($this->rezeptFoodpol, 'Zander', $this->gp, '150');
    $this->preisBroich = app(PriceService::class)->createTeamPrice($this->broich, $this->laFrei, 11.00);   // Mandanten-Preis (CSV/Necta)

    $this->teams = fn () => ['broich' => $this->broich, 'foodpol' => $this->foodpol, 'master' => $this->master];
});

it('sichtbare LA, Lieferanten und GP je Team: Stufe 3 automatisch = heute ausdrücklich (visibleToTeam)', function () {
    $stand = function (int $stufe, bool $automatisch) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        $out = [];
        foreach (($this->teams)() as $k => $t) {
            $out[$k] = $automatisch
                ? FaTeam::imTeam($t, fn () => [FoodAlchemistSupplierItem::query()->pluck('id')->sort()->values()->all(),
                    FoodAlchemistSupplier::query()->pluck('id')->sort()->values()->all(), FoodAlchemistGp::query()->pluck('id')->sort()->values()->all()])
                : [FoodAlchemistSupplierItem::visibleToTeam($t)->pluck('id')->sort()->values()->all(),
                    FoodAlchemistSupplier::visibleToTeam($t)->pluck('id')->sort()->values()->all(), FoodAlchemistGp::visibleToTeam($t)->pluck('id')->sort()->values()->all()];
        }

        return $out;
    };
    $heute = $stand(0, false);
    // io-Regel (Spec 82): Foodpol ohne Opt-in sieht nichts, Broich nur den freigeschalteten Lieferanten, Master alles
    expect($heute['foodpol'][0])->toBe([])->and($heute['broich'][0])->toBe([(int) $this->laFrei->id])
        ->and($heute['master'][0])->toHaveCount(2);
    expect($stand(3, true))->toBe($heute);
});

it('Relationen auf den Stamm (verweis) lösen bei Stufe 3 auf wie bei Stufe 0 — Preis→Artikel, Struktur→Artikel/GP, Zutat→GP', function () {
    $aufloesen = function (int $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        $out = [];
        foreach (($this->teams)() as $k => $t) {
            $out[$k] = FaTeam::imTeam($t, fn () => [
                FoodAlchemistPrice::query()->whereIn('id', $this->masterPreise)->with('item')->get()->map(fn ($p) => $p->item?->id)->all(),
                FoodAlchemistSupplierItemStructure::query()->with(['item', 'gp'])->get()->map(fn ($s) => [$s->item?->id, $s->gp?->id])->all(),
                FoodAlchemistRecipeIngredient::query()->whereKey($this->zutatFoodpol->id)->with('gp')->get()->map(fn ($z) => $z->gp?->id)->all(),
            ]);
        }

        return $out;
    };
    $null = $aufloesen(0);
    expect($null['foodpol'][0])->not->toContain(null)->and($null['foodpol'][2])->toBe([(int) $this->gp->id]);
    expect($aufloesen(3))->toBe($null);
});

it('Wächter: kein Mandant sieht einen Master-Preis — bei Stufe 0 und 3, auch nicht über die verweis-Relation', function () {
    foreach ([0, 3] as $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        foreach (['broich' => $this->broich, 'foodpol' => $this->foodpol] as $k => $t) {
            foreach ([$this->laFrei, $this->laZu] as $la) {
                $p = FaTeam::imTeam($t, fn () => app(PriceService::class)->activeFor((int) $la->id, $t));
                expect($p === null || ! in_array((int) $p->id, $this->masterPreise, true))->toBeTrue("Stufe {$stufe}: {$k} sieht Master-Preis an LA {$la->id}");
            }
            // Listen-Spalte (aktive Preis-Unterabfrage) — dieselbe Regel
            $ek = FaTeam::imTeam($t, fn () => FoodAlchemistSupplierItem::ohneTeamSicht('test')->whereKey($this->laZu->id)
                ->select('id')->selectSub(app(PriceService::class)->activePriceSubquery('foodalchemist_supplier_items.id', $t), 'ek')->value('ek'));
            expect($ek)->toBeNull();
        }
        // Gegenprobe: der eigene Mandanten-Preis ist für Broich da, der Master sieht seinen Preis
        expect((int) FaTeam::imTeam($this->broich, fn () => app(PriceService::class)->activeFor((int) $this->laFrei->id, $this->broich))?->id)->toBe((int) $this->preisBroich->id)
            ->and(FaTeam::imTeam($this->master, fn () => app(PriceService::class)->activeFor((int) $this->laZu->id, $this->master))?->id)->not->toBeNull();
    }
});
