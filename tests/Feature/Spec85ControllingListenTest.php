<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Ausreisser;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Erfolg;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Wareneinsatz;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSignalSnapshot;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\EinkaufOptimizerService;
use Platform\FoodAlchemist\Services\MenuEngineeringService;
use Platform\FoodAlchemist\Services\PortfolioService;
use Platform\FoodAlchemist\Services\PromotionService;
use Platform\FoodAlchemist\Services\SignalTrendService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\StandortService;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 Controlling PR-E (Dominique 19.10.): die Listen-Teile (Portfolio, Erfolg/Menu-Engineering, Promotion, Verlauf, Optimizer, Ausreißer) folgen
 * der Team-Brille — alle = jeder Standort mit seinen eigenen Daten und Preisen, danach summiert; ein Standort = nur dieser; fremde Mandanten nie.
 * Kontext wie auf io, Stufe 4: Master → Mandant (rootTeam) → Standorte childA/childB, daneben ein fremder Mandant unter dem Master.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.team_sicht.stufe' => 4]);
    $this->rootTeam->update(['parent_team_id' => $this->master->id]);
    $this->fremd = Team::create(['name' => 'Fremder Mandant', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();

    $this->inhaber = $this->makeUser($this->rootTeam, 'Mandant Inhaber', 'owner');
    $this->actingAs($this->inhaber);
    $this->standorte = app(StandortService::class);
    $this->brille = function (string $modus, ?Team $team = null) {
        $this->standorte->setzeBrille($this->rootTeam, $modus, $team?->id);
    };

    // Gerichte mit Standard-Darreichung (Standort A: 1 Gericht, B: 1 Gericht, fremd: 1 Gericht)
    $gericht = function (Team $team, string $key, float $ek, float $vk) {
        $r = $this->makeRecipe($team, $key, ['is_sales_recipe' => true, 'status' => 'approved', 'ek_total_eur' => $ek, 'sales_unit_count' => 1]);
        $sf = FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $team->id], ['label' => 'Unbestimmt']);
        FoodAlchemistRecipeDarreichung::create(['team_id' => $team->id, 'recipe_id' => $r->id, 'serving_form_id' => $sf->id, 'is_standard' => true, 'sales_net' => $vk, 'ek_portion' => $ek]);

        return $r;
    };
    $this->gA = $gericht($this->childA, 'a1', 2.0, 4.0);
    $this->gB = $gericht($this->childB, 'b1', 3.0, 10.0);
    $this->gF = $gericht($this->fremd, 'f1', 1.0, 10.0);

    $this->fakt = fn (Team $t, $r, float $umsatz, string $h) => FoodAlchemistSalesFact::create(['team_id' => $t->id, 'recipe_id' => $r->id, 'raw_label' => $r->name,
        'qty_sold' => 1, 'revenue_net' => $umsatz, 'sold_at' => now()->subDay()->toDateString(), 'source' => 'csv_import', 'source_hash' => $h]);
});

it('Portfolio: Brille „alle“ listet die Ausgaben der Standorte, „ein Standort“ nur dessen, „eigen“ keine fremden; der fremde Mandant nie', function () {
    $karte = fn (Team $t, string $n) => app(SpeisekarteService::class)->create($t, ['name' => $n, 'status' => 'aktiv']);
    $karte($this->childA, 'Karte Standort A');
    $karte($this->childB, 'Karte Standort B');
    $karte($this->fremd, 'Karte Fremder');
    $namen = fn () => array_column(app(PortfolioService::class)->uebersicht($this->rootTeam), 'name');

    ($this->brille)('eigen');
    expect($namen())->not->toContain('Karte Standort A')->and($namen())->not->toContain('Karte Standort B');

    ($this->brille)('alle');
    expect($namen())->toContain('Karte Standort A', 'Karte Standort B')->and($namen())->not->toContain('Karte Fremder');

    ($this->brille)('team', $this->childA);
    expect($namen())->toBe(['Karte Standort A']);
});

it('Erfolg/Menu-Engineering: Matrix und Umsatz folgen der Brille; Zeile je Standort; fremde Umsätze nie', function () {
    ($this->fakt)($this->childA, $this->gA, 100.0, 'a');
    ($this->fakt)($this->childB, $this->gB, 200.0, 'b');
    ($this->fakt)($this->fremd, $this->gF, 999.0, 'f');
    $me = app(MenuEngineeringService::class);

    ($this->brille)('eigen');
    expect($me->matrix($this->rootTeam)['n'])->toBe(0);

    ($this->brille)('alle');
    $m = $me->matrix($this->rootTeam);
    expect($m['n'])->toBe(2)->and($m['umsatz'])->toBe(300.0);   // 100 + 200, ohne die 999 des fremden Mandanten
    Livewire::test(Erfolg::class)->assertSeeHtml('data-ctrl-erfolg-je-standort')->assertSee('Kind A')->assertSee('Kind B');

    ($this->brille)('team', $this->childB);
    expect($me->matrix($this->rootTeam)['umsatz'])->toBe(200.0);
});

it('Promotion: der Gesamtumsatz im Journal folgt der Brille', function () {
    ($this->fakt)($this->childA, $this->gA, 100.0, 'a');
    ($this->fakt)($this->childB, $this->gB, 200.0, 'b');
    ($this->fakt)($this->fremd, $this->gF, 999.0, 'f');
    $gesamt = fn () => app(PromotionService::class)->uebersicht($this->rootTeam)['umsatz_gesamt'];

    ($this->brille)('eigen');
    expect($gesamt())->toBe(0.0);
    ($this->brille)('alle');
    expect($gesamt())->toBe(300.0);
    ($this->brille)('team', $this->childA);
    expect($gesamt())->toBe(100.0);
});

it('Verlauf: Zähler der Standorte summiert, ein Standort allein, fremde Mandanten nie', function () {
    $snap = fn (Team $t, int $n, string $at) => FoodAlchemistSignalSnapshot::create(['team_id' => $t->id, 'source' => 'signals', 'metric_key' => 'preis_anomalie',
        'signal_type' => 'preis_anomalie', 'count' => $n, 'measured_at' => $at]);
    $snap($this->childA, 4, '2026-10-02 06:00:00');
    $snap($this->childA, 6, '2026-10-09 06:00:00');   // A: 4 → 6
    $snap($this->childB, 1, '2026-10-02 07:00:00');
    $snap($this->childB, 3, '2026-10-09 07:00:00');   // B: 1 → 3
    $snap($this->fremd, 50, '2026-10-09 06:00:00');
    $verlauf = fn () => collect(app(SignalTrendService::class)->uebersichtBrille($this->rootTeam)['metriken'])->firstWhere('metric_key', 'preis_anomalie');

    ($this->brille)('alle');
    expect($verlauf()['count'])->toBe(9)->and($verlauf()['previous'])->toBe(5)->and($verlauf()['delta'])->toBe(4);   // 6 + 3, vorher 4 + 1

    ($this->brille)('team', $this->childA);
    expect($verlauf()['count'])->toBe(6)->and($verlauf()['previous'])->toBe(4);
});

it('Optimizer: Brille „alle“ rechnet je Standort mit dessen eigenen Preisen und summiert; Zeilen tragen den Standort', function () {
    $kauf = function (Team $t, string $name, float $listenpreis, float $gezahlt) {
        $gp = $this->makeGp($t, 'Zwiebel '.$name);
        $sup = FoodAlchemistSupplier::create(['team_id' => $t->id, 'name' => 'Lieferant '.$name, 'status' => 'aktiv']);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $t->id, 'supplier_id' => $sup->id, 'designation' => 'Zwiebel '.$name, 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $t->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $t->id, 'supplier_item_id' => $la->id, 'price' => $listenpreis, 'status' => '0']);
        $gp->update(['n_las_total' => 1]);
        FoodAlchemistPurchaseTransaction::create(['team_id' => $t->id, 'supplier_id' => $sup->id, 'gp_id' => $gp->id, 'designation_raw' => 'Zwiebel', 'unit_code' => 'kg',
            'qty' => 10, 'unit_price' => $gezahlt / 10, 'line_total' => $gezahlt, 'purchased_at' => '2026-06-01', 'source' => 'necta_import']);
    };
    $kauf($this->childA, 'A', 1.0, 25.0);   // Optimum 10 € → Ersparnis 15
    $kauf($this->childB, 'B', 2.0, 40.0);   // Optimum 20 € → Ersparnis 20
    $kauf($this->fremd, 'F', 1.0, 500.0);
    $opt = app(EinkaufOptimizerService::class);

    ($this->brille)('eigen');
    expect($opt->optimierenBrille($this->rootTeam)['ist_total'])->toBe(0.0);

    ($this->brille)('alle');
    $r = $opt->optimierenBrille($this->rootTeam);
    $je = collect($r['je_standort'])->keyBy('team_id');
    expect($r['ist_total'])->toBe(65.0)->and($r['saving_list'])->toBe(35.0)
        ->and($je[$this->childA->id]['saving_list'])->toBe(15.0)->and($je[$this->childB->id]['saving_list'])->toBe(20.0)
        ->and(collect($r['top'])->pluck('team_id')->unique()->sort()->values()->all())->toBe([(int) $this->childA->id, (int) $this->childB->id]);
    Livewire::test(Wareneinsatz::class)->assertSeeHtml('data-ctrl-we-je-standort');

    ($this->brille)('team', $this->childB);
    expect($opt->optimierenBrille($this->rootTeam)['saving_list'])->toBe(20.0);
});

it('Ausreißer: je Standort mit eigenem Preisverlauf geprüft, Treffer tragen den Standort', function () {
    $serie = function (Team $t, array $preise) {
        foreach ($preise as $i => $p) {
            FoodAlchemistPurchaseTransaction::create(['team_id' => $t->id, 'designation_raw' => 'Butter', 'unit_code' => 'kg', 'qty' => 1, 'unit_price' => $p,
                'line_total' => $p, 'purchased_at' => '2026-06-0'.($i + 1), 'source' => 'necta_import', 'source_hash' => $t->id.'-'.$i]);
        }
    };
    $serie($this->childA, [10, 10, 10, 10, 90]);   // 90 ist ein Ausreißer
    $serie($this->childB, [10, 10, 10, 10, 10]);

    ($this->brille)('alle');
    Livewire::test(Ausreisser::class)->assertSeeHtml('data-ctrl-ausreisser-je-standort')
        ->assertViewHas('treffer', fn ($t) => count($t) === 1 && $t[0]['standort'] === 'Kind A')
        ->assertViewHas('jeStandort', fn ($j) => collect($j)->firstWhere('team_id', $this->childA->id)['anzahl'] === 1
            && collect($j)->firstWhere('team_id', $this->childB->id)['anzahl'] === 0);

    ($this->brille)('eigen');
    Livewire::test(Ausreisser::class)->assertViewHas('treffer', [])->assertViewHas('jeStandort', []);
});
