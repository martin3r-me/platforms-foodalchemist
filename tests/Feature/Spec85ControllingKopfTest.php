<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Controlling\Cockpit;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Kennzahlen;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\BenchmarkService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\StandortService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 Controlling PR-D (Dominique 19.10.): KPI-Kopf und Geld-Signale folgen der Team-Brille (alle = jeder Standort mit eigenen Preisen,
 * danach summiert; eine Zeile je Standort), das Benchmark-Netz endet an der Kundengrenze, Kennzahlen und Abweichung rechnen mit dem Kalkulationsprofil.
 * Kontext wie auf io, Stufe 4: Master → Mandant (rootTeam) → Standorte childA/childB, daneben ein fremder Mandant unter dem Master.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.team_sicht.stufe' => 4]);
    $this->rootTeam->update(['parent_team_id' => $this->master->id]);
    $this->fremd = Team::create(['name' => 'Fremder Mandant', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();

    $this->gericht = function (Team $team, string $key, float $ekPortion, float $vk) {
        $r = $this->makeRecipe($team, $key, ['is_sales_recipe' => true, 'status' => 'approved', 'ek_total_eur' => $ekPortion, 'sales_unit_count' => 1]);
        $sf = FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $team->id], ['label' => 'Unbestimmt']);
        FoodAlchemistRecipeDarreichung::create(['team_id' => $team->id, 'recipe_id' => $r->id, 'serving_form_id' => $sf->id, 'is_standard' => true, 'sales_net' => $vk, 'ek_portion' => $ekPortion]);

        return $r;
    };
    // Standort A: ein Gericht 50 %; Standort B: zwei Gerichte 30 % und 40 %; Mandant selbst ohne Gericht; fremder Mandant 10 %
    ($this->gericht)($this->childA, 'a1', 2.0, 4.0);
    ($this->gericht)($this->childB, 'b1', 3.0, 10.0);
    ($this->gericht)($this->childB, 'b2', 8.0, 20.0);
    ($this->gericht)($this->fremd, 'f1', 1.0, 10.0);

    $this->inhaber = $this->makeUser($this->rootTeam, 'Mandant Inhaber', 'owner');
    $this->standorte = app(StandortService::class);
    $this->signal = fn (Team $t, string $typ, string $key) => FoodAlchemistSignal::create(['team_id' => $t->id, 'type' => $typ, 'severity' => 'warnung',
        'status' => 'offen', 'title' => $typ, 'dedup_key' => $key, 'source' => 'test']);
});

it('Benchmark-Netz endet an der Kundengrenze: kein fremder Mandant, nie der Master; der Master selbst hat kein Netz', function () {
    $svc = app(BenchmarkService::class);
    $netz = $svc->netzTeamIds($this->childA);

    expect($netz)->toContain((int) $this->rootTeam->id, (int) $this->childA->id, (int) $this->childB->id)
        ->and($netz)->not->toContain((int) $this->fremd->id)->and($netz)->not->toContain((int) $this->master->id)
        ->and($svc->netzTeamIds($this->master))->toBe([(int) $this->master->id]);

    // der Peer-Median enthält das Gericht des fremden Mandanten (10 %) nicht: nur Standort B (35 %), der Mandant selbst hat keine Gerichte
    $b = $svc->benchmark($this->childA);
    expect($b['n_peers'])->toBe(1)->and($b['peer_median']['avg_w_pct'])->toBe(35.0);
});

it('kpisFuerTeam über mehrere Standorte: jedes Gericht mit seinem eigenen EK gezählt (Ø über alle, nicht Ø der Standort-Durchschnitte)', function () {
    $k = app(BenchmarkService::class)->kpisFuerTeam([$this->childA->id, $this->childB->id]);

    expect($k['n_dishes'])->toBe(3)->and($k['avg_w_pct'])->toBe(40.0)   // (50 + 30 + 40) ÷ 3, nicht (50 + 35) ÷ 2 = 42,5
        ->and(app(BenchmarkService::class)->kpisFuerTeam($this->childB->id)['avg_w_pct'])->toBe(35.0);   // einzeln wie bisher
});

it('KPI-Kopf folgt der Brille: eigen = das Team, alle = Summe der Standorte + Zeile je Standort, ein Standort = nur dieser', function () {
    $this->actingAs($this->inhaber);

    $this->standorte->setzeBrille($this->rootTeam, 'eigen');
    Livewire::test(Cockpit::class)->assertViewHas('kpi', fn ($k) => $k['n_dishes'] === 0 && $k['je_standort'] === []);

    $this->standorte->setzeBrille($this->rootTeam, 'alle');
    Livewire::test(Cockpit::class)->assertViewHas('kpi', function ($k) {
        $je = collect($k['je_standort'])->keyBy('team_id');

        return $k['n_dishes'] === 3 && $k['avg_w_pct'] === 40.0
            && $je->count() === 3                                              // Mandant selbst + zwei Standorte
            && $je[$this->childA->id]['wert']['avg_w_pct'] === 50.0 && $je[$this->childB->id]['wert']['avg_w_pct'] === 35.0
            && $je[$this->rootTeam->id]['wert']['n_dishes'] === 0;
    });

    $this->standorte->setzeBrille($this->rootTeam, 'team', $this->childA->id);
    Livewire::test(Cockpit::class)->assertViewHas('kpi', fn ($k) => $k['n_dishes'] === 1 && $k['avg_w_pct'] === 50.0);
});

it('Geld-Signale folgen der Brille und zählen je Standort nur die eigenen; fremde Mandanten nie', function () {
    ($this->signal)($this->rootTeam, 'marge_unter_ziel', 'r1');
    ($this->signal)($this->childA, 'preis_anomalie', 'a1');
    ($this->signal)($this->childB, 'preis_anomalie', 'b1');
    ($this->signal)($this->childB, 'preis_anomalie', 'b2');
    ($this->signal)($this->fremd, 'preis_anomalie', 'f1');
    $this->actingAs($this->inhaber);

    $this->standorte->setzeBrille($this->rootTeam, 'eigen');
    Livewire::test(Cockpit::class)->assertViewHas('kpi', fn ($k) => $k['geld_signale'] === 1);

    $this->standorte->setzeBrille($this->rootTeam, 'alle');
    Livewire::test(Cockpit::class)->assertViewHas('kpi', function ($k) {
        $je = collect($k['je_standort'])->keyBy('team_id');

        return $k['geld_signale'] === 4                                          // 1 + 1 + 2, der fremde Mandant fehlt
            && $je[$this->childA->id]['wert']['geld_signale'] === 1 && $je[$this->childB->id]['wert']['geld_signale'] === 2
            && $je[$this->rootTeam->id]['wert']['geld_signale'] === 1;
    });

    $this->standorte->setzeBrille($this->rootTeam, 'team', $this->childB->id);
    Livewire::test(Cockpit::class)->assertViewHas('kpi', fn ($k) => $k['geld_signale'] === 2);
});

it('alle: Fixkosten und Break-even summieren die Standorte mit je eigenem Profil', function () {
    $fix = app(\Platform\FoodAlchemist\Services\FixkostenService::class);
    $fix->create($this->childA, ['label' => 'Miete A', 'amount' => 1000, 'block_key' => 'gemeinkosten']);
    $fix->create($this->childB, ['label' => 'Miete B', 'amount' => 2000, 'block_key' => 'gemeinkosten']);
    app(TeamSettingsService::class)->update($this->rootTeam, ['target_food_cost_pct' => 30]);
    $this->actingAs($this->inhaber);

    $this->standorte->setzeBrille($this->rootTeam, 'alle');
    Livewire::test(Cockpit::class)->assertViewHas('kpi', fn ($k) => abs($k['fixkosten_monat'] - 3000) < 0.01 && abs($k['break_even'] - 3000 / 0.7) < 0.5);

    $this->standorte->setzeBrille($this->rootTeam, 'team', $this->childA->id);
    Livewire::test(Cockpit::class)->assertViewHas('kpi', fn ($k) => abs($k['fixkosten_monat'] - 1000) < 0.01);
});

it('Kennzahlen: die Eingabefelder starten mit den Werten des aktiven Kalkulationsprofils', function () {
    $nord = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord']);
    app(OutletSettingsService::class)->update($this->rootTeam, $nord, ['target_food_cost_pct' => 27, 'margin_pct' => 140]);
    app(TeamSettingsService::class)->update($this->rootTeam, ['target_food_cost_pct' => 35, 'margin_pct' => 90]);
    $this->actingAs($this->inhaber);

    app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)->set($this->rootTeam, $nord->id);
    Livewire::test(Kennzahlen::class)->assertSet('zielWe', '27')->assertSet('marge', '140');

    app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)->set($this->rootTeam, null);
    Livewire::test(Kennzahlen::class)->assertSet('zielWe', '35')->assertSet('marge', '90');   // Gegenprobe: ohne Profil der Team-Standard
});

it('Abweichung: das Ziel kommt aus dem aktiven Kalkulationsprofil', function () {
    $nord = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord']);
    app(OutletSettingsService::class)->update($this->rootTeam, $nord, ['target_food_cost_pct' => 27]);
    app(TeamSettingsService::class)->update($this->rootTeam, ['target_food_cost_pct' => 35]);
    $this->actingAs($this->inhaber);
    $svc = app(\Platform\FoodAlchemist\Services\WareneinsatzAbweichungService::class);

    app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)->set($this->rootTeam, $nord->id);
    expect($svc->analyse($this->rootTeam)['ziel_pct'])->toBe(27.0);

    app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)->set($this->rootTeam, null);
    expect($svc->analyse($this->rootTeam)['ziel_pct'])->toBe(35.0);   // Gegenprobe
});
