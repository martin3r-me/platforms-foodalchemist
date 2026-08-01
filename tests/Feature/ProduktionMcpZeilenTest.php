<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\ProductionOrdersDeleteTool;
use Platform\FoodAlchemist\Tools\ProductionOrdersLineAssignTool;
use Platform\FoodAlchemist\Tools\ProductionOrdersLineOverrideTool;
use Platform\FoodAlchemist\Tools\ProductionOrdersLineStatusTool;
use Platform\FoodAlchemist\Tools\ProductionOrdersUpdateTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 30 E7 — MCP im Lockstep: was die Oberfläche kann, kann der Agent auch.
 * Reads sehen die Team-Kette nach oben, Writes nur das eigene Team (D1).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Brauner Fond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 60,
    ]);
    $this->order = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'MCP-Test', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->rezept->id, 'amount_kg' => 6.0],
    ]);
    $this->zeile = fn () => Line::where('production_order_id', $this->order->id)->first();

    $this->kontexte = [];
    $this->ctx = fn ($team) => $this->kontexte[$team->id] ??= new ToolContext($this->makeUser($team, 'MCP ' . $team->id), $team);
});

it('LINE_OVERRIDE setzt Ansätze und nimmt den Override wieder zurück', function () {
    $r = (new ProductionOrdersLineOverrideTool)->execute(
        ['line_id' => ($this->zeile)()->id, 'ansaetze' => 2],
        ($this->ctx)($this->rootTeam),
    );

    expect($r->success)->toBeTrue()
        ->and($r->data['ansaetze_effektiv'])->toBe(2.0)
        ->and($r->data['ansaetze_berechnet'])->toBe(3.0)
        ->and($r->data['ist_manuelle_ansaetze'])->toBeTrue();

    $r = (new ProductionOrdersLineOverrideTool)->execute(
        ['line_id' => ($this->zeile)()->id, 'ansaetze' => null],
        ($this->ctx)($this->rootTeam),
    );

    expect($r->data['ist_manuelle_ansaetze'])->toBeFalse()
        ->and($r->data['ansaetze_effektiv'])->toBe(3.0);
});

it('LINE_OVERRIDE streicht eine Zeile mit Grund', function () {
    $r = (new ProductionOrdersLineOverrideTool)->execute(
        ['line_id' => ($this->zeile)()->id, 'is_struck' => true, 'struck_reason' => 'Rest von gestern'],
        ($this->ctx)($this->rootTeam),
    );

    expect($r->data['is_struck'])->toBeTrue()
        ->and($r->data['struck_reason'])->toBe('Rest von gestern');
});

it('LINE_OVERRIDE ohne Änderungsfeld meldet NO_CHANGE statt still nichts zu tun', function () {
    $r = (new ProductionOrdersLineOverrideTool)->execute(
        ['line_id' => ($this->zeile)()->id],
        ($this->ctx)($this->rootTeam),
    );

    expect($r->success)->toBeFalse();
});

it('LINE_ASSIGN setzt Posten, Verantwortlichen und Vorlauf — plan_date folgt', function () {
    $posten = FoodAlchemistProductionStation::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'patisserie', 'name' => 'Patisserie',
    ]);

    $r = (new ProductionOrdersLineAssignTool)->execute(
        ['line_id' => ($this->zeile)()->id, 'station_id' => $posten->id, 'assignee' => 'Marek', 'vorlauf_tage' => 2],
        ($this->ctx)($this->rootTeam),
    );

    expect($r->success)->toBeTrue()
        ->and($r->data['station'])->toBe('Patisserie')
        ->and($r->data['assignee'])->toBe('Marek')
        ->and($r->data['plan_date'])->toBe('2026-08-18');   // Liefertag − 2
});

it('D1: fremdes Team kann per MCP weder überschreiben noch zuteilen', function () {
    $lineId = ($this->zeile)()->id;

    expect((new ProductionOrdersLineOverrideTool)->execute(
        ['line_id' => $lineId, 'ansaetze' => 9], ($this->ctx)($this->childA),
    )->success)->toBeFalse();

    expect((new ProductionOrdersLineAssignTool)->execute(
        ['line_id' => $lineId, 'assignee' => 'Fremd'], ($this->ctx)($this->childA),
    )->success)->toBeFalse();
});

it('LINE_STATUS hakt nur im laufenden Auftrag ab', function () {
    $lineId = ($this->zeile)()->id;

    expect((new ProductionOrdersLineStatusTool)->execute(
        ['line_id' => $lineId, 'status' => 'done'], ($this->ctx)($this->rootTeam),
    )->success)->toBeFalse();

    // Der Start rechnet ein letztes Mal durch — die Zeile wird dabei ersetzt, ihre ID also neu.
    $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::InProgress);
    $lineId = ($this->zeile)()->id;

    $r = (new ProductionOrdersLineStatusTool)->execute(
        ['line_id' => $lineId, 'status' => 'done'], ($this->ctx)($this->rootTeam),
    );

    expect($r->success)->toBeTrue()
        ->and($r->data['line_status'])->toBe('done')
        ->and($r->data['done_at'])->not->toBeNull()
        ->and(Line::find($lineId)->line_status)->toBe(ProductionLineStatus::Done);
});

it('UPDATE trägt buffer_pct und rechnet die Explosion neu', function () {
    $r = (new ProductionOrdersUpdateTool)->execute(
        ['order_id' => $this->order->id, 'buffer_pct' => 50],
        ($this->ctx)($this->rootTeam),
    );

    expect($r->success)->toBeTrue()
        ->and($r->data['buffer_pct'])->toBe(50.0)
        // 6 kg + 50 % = 9 kg ÷ 2 kg Basis-Yield ⇒ 5 Ansätze (aufgerundet)
        ->and((float) ($this->zeile)()->ansaetze)->toBe(5.0);
});

it('DELETE löscht den geplanten Auftrag, nicht den laufenden', function () {
    $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::InProgress);

    expect((new ProductionOrdersDeleteTool)->execute(
        ['order_id' => $this->order->id], ($this->ctx)($this->rootTeam),
    )->success)->toBeFalse();

    $zweiter = $this->svc->saveNew($this->rootTeam, '2026-08-22', 'Wegwerf', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->rezept->id, 'amount_kg' => 2.0],
    ]);

    expect((new ProductionOrdersDeleteTool)->execute(
        ['order_id' => $zweiter->id], ($this->ctx)($this->rootTeam),
    )->success)->toBeTrue()
        ->and(FoodAlchemistProductionOrder::find($zweiter->id))->toBeNull();
});
