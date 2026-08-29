<?php

use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — die Betriebs-„Lane" der Signale: `outlet_id` NULL = Team-Core, sonst der Betrieb.
 * Dieselbe dedup_key kollidiert nicht zwischen den Lanes; die Betriebsbrille filtert (Betrieb +
 * Team-Core); der Auto-Close-Sweep bleibt strikt in seiner Lane. Plus ein Detektor-Lauf, der zeigt,
 * dass ein Gericht nur unter der SCHÄRFEREN Betriebsschwelle ein (Betriebs-Lane-)Signal auslöst.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);
    $this->svc = app(SignalService::class);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Betrieb Nord']);

    // Zwei Signale mit GLEICHEM dedup_key, aber in verschiedenen Lanes.
    $this->zweiLanes = function () {
        $this->svc->erzeuge($this->childA, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Team-Core', ['dedup_key' => 'marge-recipe-1']);
        $this->svc->erzeuge($this->childA, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Betrieb', ['dedup_key' => 'marge-recipe-1', 'outlet_id' => $this->betrieb->id]);
    };
});

it('erzeuge: gleicher dedup_key in Team-Core- und Betriebs-Lane → zwei Signale, keine Kollision', function () {
    ($this->zweiLanes)();

    $alle = FoodAlchemistSignal::where('team_id', $this->childA->id)->where('type', SignalTyp::MargeUnterZiel->value)->get();
    expect($alle)->toHaveCount(2)
        ->and($alle->whereNull('outlet_id'))->toHaveCount(1)
        ->and($alle->where('outlet_id', $this->betrieb->id))->toHaveCount(1);

    // Erneutes Erzeugen in der Betriebs-Lane aktualisiert (Dedup), dupliziert nicht.
    $this->svc->erzeuge($this->childA, SignalTyp::MargeUnterZiel, SignalSeverity::Kritisch, 'Betrieb v2', ['dedup_key' => 'marge-recipe-1', 'outlet_id' => $this->betrieb->id]);
    expect(FoodAlchemistSignal::where('team_id', $this->childA->id)->where('type', SignalTyp::MargeUnterZiel->value)->count())->toBe(2);
});

it('Lane-Filter: Brille=Betrieb zeigt Betrieb + Team-Core; Brille=null nur Team-Core; ohne Lane alles', function () {
    ($this->zweiLanes)();

    expect($this->svc->offeneNachTyp($this->childA, $this->betrieb, nurLane: true)[SignalTyp::MargeUnterZiel->value] ?? 0)->toBe(2)
        ->and($this->svc->offeneNachTyp($this->childA, null, nurLane: true)[SignalTyp::MargeUnterZiel->value] ?? 0)->toBe(1)
        ->and($this->svc->offeneNachTyp($this->childA)[SignalTyp::MargeUnterZiel->value] ?? 0)->toBe(2);

    expect($this->svc->offeneCount($this->childA, $this->betrieb, nurLane: true))->toBe(2)
        ->and($this->svc->offeneCount($this->childA, null, nurLane: true))->toBe(1)
        ->and($this->svc->paginate([], $this->childA, 50, $this->betrieb, nurLane: true)->total())->toBe(2)
        ->and($this->svc->paginate([], $this->childA, 50, null, nurLane: true)->total())->toBe(1);
});

it('schliesseVerschwundene ist Lane-isoliert: der Betriebs-Sweep schließt nicht die Team-Core-Lane', function () {
    ($this->zweiLanes)();

    // Betriebs-Lauf ohne Live-Keys ⇒ schließt NUR die Betriebs-Lane.
    $geschlossen = $this->svc->schliesseVerschwundene($this->childA, SignalTyp::MargeUnterZiel, 'detektor', [], 'weg', $this->betrieb->id);
    expect($geschlossen)->toBe(1)
        ->and($this->svc->offeneCount($this->childA, null, nurLane: true))->toBe(1)          // Team-Core unberührt
        ->and($this->svc->offeneCount($this->childA, $this->betrieb, nurLane: true))->toBe(1); // = nur noch die Team-Core-Zeile (via Lane sichtbar)

    // Umgekehrt: der Team-Core-Sweep (outlet=null) räumt die NULL-Lane, nicht die Betriebs-Lane.
    ($this->zweiLanes)();   // Betriebs-Zeile neu
    $this->svc->schliesseVerschwundene($this->childA, SignalTyp::MargeUnterZiel, 'detektor', [], 'weg', null);
    expect(FoodAlchemistSignal::where('team_id', $this->childA->id)->whereNull('outlet_id')->where('status', 'offen')->count())->toBe(0)
        ->and(FoodAlchemistSignal::where('team_id', $this->childA->id)->where('outlet_id', $this->betrieb->id)->where('status', 'offen')->count())->toBe(1);
});

it('Detektor: Gericht nur unter der schärferen Betriebsschwelle → Signal in der Betriebs-Lane, nicht Team-Core', function () {
    // Team-Ziel-Wareneinsatz großzügig (50 %), Betrieb scharf (15 %). Gericht ~33 % (EK 10 / VK 30).
    app(TeamSettingsService::class)->update($this->childA, ['target_food_cost_pct' => 50]);
    app(OutletSettingsService::class)->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 15]);
    $this->makeRecipe($this->childA, 'Teurer Teller', ['is_sales_recipe' => true, 'sales_net' => 30.0, 'ek_total_eur' => 10.0, 'sales_unit_count' => 1]);

    $det = app(SignalDetektorService::class);
    expect($det->wareneinsatzUeberZiel($this->childA))->toBe(0)                 // Team-Core: 33 ≤ 50 → nichts
        ->and($det->wareneinsatzUeberZiel($this->childA, $this->betrieb))->toBe(1); // Betrieb: 33 > 15 → Signal

    expect(FoodAlchemistSignal::where('team_id', $this->childA->id)->where('type', SignalTyp::WareneinsatzUeberZiel->value)->whereNull('outlet_id')->count())->toBe(0)
        ->and(FoodAlchemistSignal::where('team_id', $this->childA->id)->where('type', SignalTyp::WareneinsatzUeberZiel->value)->where('outlet_id', $this->betrieb->id)->count())->toBe(1);
});
