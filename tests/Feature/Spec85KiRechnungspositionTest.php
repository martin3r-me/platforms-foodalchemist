<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\Core\Models\TeamBillableUsage;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Services\GebuehrenZaehler;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * KI-Abrechnung PR 4 (Dominique 23.10.2026): eine Rechnungsposition „KI-Verbrauch“ je aktivem Mandant in team_billable_usages,
 * Betrag = eingefrorene Selbstkosten des Tages (inkl. Fehlläufe mit Anbieter-Kosten), erst ab foodalchemist.ai.abrechnung_ab,
 * Rundung je Tag mit Ausgleich am Monatsende, hinter demselben Schalter wie die übrigen Gebühren.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    if (! Schema::hasTable('team_billable_usages')) {
        Schema::create('team_billable_usages', function ($t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
            $t->string('billable_model');
            $t->string('billable_type');
            $t->string('label');
            $t->date('usage_date');
            $t->unsignedInteger('count')->default(0);
            $t->decimal('cost_per_unit', 8, 4);
            $t->decimal('total_cost', 10, 2);
            $t->json('pricing_snapshot')->nullable();
            $t->timestamps();
            $t->unique(['team_id', 'billable_model', 'usage_date']);
        });
    }
    config(['foodalchemist.master_team_id' => null, 'foodalchemist.ai.abrechnung_ab' => '2026-11-01']);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->kunde = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    FoodAlchemistMandant::create(['team_id' => $this->kunde->id, 'status' => 'aktiv']);
    $this->andere = Team::create(['name' => 'Andere', 'user_id' => 1, 'personal_team' => false]);
    $this->log = function (Team $zahler, float $eur, string $am, ?string $error = null, string $verursacher = 'mandant') {
        DB::table('foodalchemist_ai_call_log')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $zahler->id, 'feature' => 'test', 'model' => 'gpt-test',
            'tokens_in' => 1, 'tokens_out' => 1, 'error' => $error, 'abrechnung_team_id' => $verursacher === 'mandant' ? $zahler->id : null, 'verursacher' => $verursacher,
            'kosten_usd' => $eur, 'usd_eur' => 1.0, 'created_at' => $am, 'updated_at' => $am]);
    };
    $this->zaehler = app(GebuehrenZaehler::class);
    $this->ki = fn (string $tag) => TeamBillableUsage::where('team_id', $this->kunde->id)->where('billable_model', GebuehrenZaehler::KI_MODELL)->whereDate('usage_date', $tag)->first();
});

it('Tageswert = eingefrorene Kosten des Mandanten inkl. Fehlläufe; Aufrufe nur mit Ergebnis; Plattform und andere Mandanten nicht', function () {
    ($this->log)($this->kunde, 1.25, '2026-11-03 09:00:00');
    ($this->log)($this->kunde, 0.50, '2026-11-03 10:00:00', 'timeout');
    ($this->log)($this->kunde, 9.00, '2026-11-03 11:00:00', null, 'plattform');
    ($this->log)($this->andere, 7.00, '2026-11-03 12:00:00');
    ($this->log)($this->kunde, 4.00, '2026-11-04 09:00:00');   // anderer Tag

    $this->zaehler->zaehle($this->kunde, '2026-11-03');
    $z = ($this->ki)('2026-11-03');

    expect((float) $z->total_cost)->toBe(1.75)->and((int) $z->count)->toBe(1)->and($z->label)->toBe('Food Alchemist · KI-Verbrauch')
        ->and($z->pricing_snapshot)->toMatchArray(['fehllaeufe' => 1, 'aufschlag' => 0, 'embeddings' => 'nicht_erfasst']);
});

it('Vor dem Abrechnungsstart und an Tagen ohne Verbrauch entsteht keine Zeile', function () {
    ($this->log)($this->kunde, 3.0, '2026-10-30 09:00:00');
    $this->zaehler->zaehle($this->kunde, '2026-10-30');
    $this->zaehler->zaehle($this->kunde, '2026-11-02');

    expect(TeamBillableUsage::where('billable_model', GebuehrenZaehler::KI_MODELL)->count())->toBe(0);
});

it('Rundung: je Tag auf Cent, der letzte Monatstag gleicht auf die exakte Monatssumme aus; erneutes Zählen überschreibt', function () {
    foreach (range(1, 30) as $d) {
        ($this->log)($this->kunde, 0.333, sprintf('2026-11-%02d 09:00:00', $d));
    }
    foreach (range(1, 30) as $d) {
        $this->zaehler->zaehle($this->kunde, sprintf('2026-11-%02d', $d));
    }
    $this->zaehler->zaehle($this->kunde, '2026-11-30');   // idempotent

    $summe = (float) TeamBillableUsage::where('billable_model', GebuehrenZaehler::KI_MODELL)->sum('total_cost');
    expect(round($summe, 2))->toBe(round(30 * 0.333, 2))
        ->and(TeamBillableUsage::where('billable_model', GebuehrenZaehler::KI_MODELL)->count())->toBe(30);
});

it('Kommando: nur aktive Mandanten, nur mit Schalter; Projekt-Mandant bekommt keine KI-Position', function () {
    $projekt = Team::create(['name' => 'Projekt', 'user_id' => 1, 'personal_team' => false]);
    FoodAlchemistMandant::create(['team_id' => $projekt->id, 'status' => 'projekt']);
    ($this->log)($this->kunde, 2.0, '2026-11-05 09:00:00');
    ($this->log)($projekt, 2.0, '2026-11-05 09:00:00');

    config(['foodalchemist.gebuehren.zaehlen_aktiv' => false]);
    $this->artisan('foodalchemist:plan-nutzung-zaehlen', ['--datum' => '2026-11-05'])->assertFailed();
    expect(TeamBillableUsage::count())->toBe(0);

    config(['foodalchemist.gebuehren.zaehlen_aktiv' => true]);
    $this->artisan('foodalchemist:plan-nutzung-zaehlen', ['--datum' => '2026-11-05'])->assertSuccessful();
    expect(TeamBillableUsage::where('billable_model', GebuehrenZaehler::KI_MODELL)->pluck('team_id')->all())->toBe([$this->kunde->id]);
});
