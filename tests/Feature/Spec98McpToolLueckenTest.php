<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.3c — fehlende MCP-Tools: foodbooks.DUPLICATE, production_orders.LINE_ADD/LINE_REMOVE, concepts.KALKULATION,
 * recipes.DICHTEKLASSE. Verweisziele (Kopier-Quelle, kalkuliertes Concept) brauchen nur Sicht; geschrieben wird nur Eigenes.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->kontexte = [];
    $this->ctx = fn ($team) => $this->kontexte[$team->id] ??= new ToolContext($this->makeUser($team, 'MCP ' . $team->id), $team);
    $this->run = fn (string $name, array $args, $team = null) => app(ToolRegistry::class)->get($name)->execute($args, ($this->ctx)($team ?? $this->rootTeam));
});

// ── foodbooks.DUPLICATE ──────────────────────────────────────────────────────

it('foodbooks.DUPLICATE kopiert ein eigenes Foodbook (Entwurf, mit Kapiteln)', function () {
    $fb = $this->makeFoodbook($this->childA, 'Sommer');
    $this->makeChapter($fb, ['title' => 'Vorspeisen']);

    $r = ($this->run)('foodalchemist.foodbooks.DUPLICATE', ['id' => $fb->id], $this->childA);

    expect($r->success)->toBeTrue();
    $kopie = FoodAlchemistFoodbook::withoutGlobalScopes()->find($r->data['foodbook']['id']);
    expect((int) $kopie->team_id)->toBe((int) $this->childA->id)
        ->and($kopie->label)->toBe('Sommer (Kopie)')
        ->and($r->data['foodbook']['status'])->toBe('entwurf')
        ->and(FoodAlchemistFoodbookKapitel::withoutGlobalScopes()->where('foodbook_id', $kopie->id)->count())->toBe(1);
});

it('foodbooks.DUPLICATE: unsichtbares Foodbook eines Geschwister-Teams → NOT_FOUND; DELETE gibt es per MCP nicht', function () {
    $fremd = $this->makeFoodbook($this->childB, 'Geheim');

    $r = ($this->run)('foodalchemist.foodbooks.DUPLICATE', ['id' => $fremd->id], $this->childA);

    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('NOT_FOUND');
    expect(app(ToolRegistry::class)->get('foodalchemist.foodbooks.DELETE'))->toBeNull();
});

it('foodbooks.DUPLICATE: geerbtes Master-Foodbook → eigene Kopie im Kind-Team, das Original bleibt unberührt', function () {
    $master = $this->makeFoodbook($this->rootTeam, 'Master');
    $this->makeChapter($master, ['title' => 'Hauptgang']);

    $r = ($this->run)('foodalchemist.foodbooks.DUPLICATE', ['id' => $master->id], $this->childA);

    expect($r->success)->toBeTrue();
    $kopie = FoodAlchemistFoodbook::withoutGlobalScopes()->find($r->data['foodbook']['id']);
    expect((int) $kopie->team_id)->toBe((int) $this->childA->id)
        ->and((int) FoodAlchemistFoodbookKapitel::withoutGlobalScopes()->where('foodbook_id', $kopie->id)->value('team_id'))->toBe((int) $this->childA->id)
        ->and((int) $master->fresh()->team_id)->toBe((int) $this->rootTeam->id);
});

// ── production_orders.LINE_ADD / LINE_REMOVE ─────────────────────────────────

describe('freie Produktionspositionen', function () {
    beforeEach(function () {
        $rezept = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond-98', 'name' => 'Brauner Fond',
            'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 60,
        ]);
        $this->order = app(ProductionOrderService::class)->saveNew($this->rootTeam, '2026-08-20', 'MCP-Freie', [
            ['source_ref' => 'r:fond', 'recipe_id' => $rezept->id, 'amount_kg' => 6.0],
        ]);
    });

    it('LINE_ADD legt eine freie Position an, LINE_REMOVE entfernt sie nur mit confirm', function () {
        $r = ($this->run)('foodalchemist.production_orders.LINE_ADD', [
            'order_id' => $this->order->id, 'titel' => 'Brot beim Bäcker abholen', 'arbeitszeit_min' => 15, 'note' => 'früh',
        ]);

        expect($r->success)->toBeTrue()
            ->and($r->data['line']['origin'])->toBe('manual')
            ->and($r->data['line']['arbeitszeit_min'])->toBe(15);
        $lineId = $r->data['line']['id'];

        $ohne = ($this->run)('foodalchemist.production_orders.LINE_REMOVE', ['line_id' => $lineId]);
        expect($ohne->errorCode)->toBe('CONFIRM_REQUIRED')->and(Line::find($lineId))->not->toBeNull();

        $weg = ($this->run)('foodalchemist.production_orders.LINE_REMOVE', ['line_id' => $lineId, 'confirm' => true]);
        expect($weg->success)->toBeTrue()->and(Line::find($lineId))->toBeNull();
    });

    it('LINE_ADD ohne Titel → VALIDATION_ERROR; berechnete Zeile entfernen → NOT_ALLOWED (wie die übrigen Produktions-Tools)', function () {
        $r = ($this->run)('foodalchemist.production_orders.LINE_ADD', ['order_id' => $this->order->id, 'titel' => '  ']);
        expect($r->errorCode)->toBe('VALIDATION_ERROR');

        $berechnet = Line::where('production_order_id', $this->order->id)->first();
        $r = ($this->run)('foodalchemist.production_orders.LINE_REMOVE', ['line_id' => $berechnet->id, 'confirm' => true]);
        expect($r->errorCode)->toBe('NOT_ALLOWED')->and(Line::find($berechnet->id))->not->toBeNull();
    });

    it('D1: ein anderes Team kann keine freien Positionen anlegen oder entfernen', function () {
        $add = ($this->run)('foodalchemist.production_orders.LINE_ADD', ['order_id' => $this->order->id, 'titel' => 'Fremd'], $this->childA);
        expect($add->success)->toBeFalse()->and($add->errorCode)->toBeIn(['ACCESS_DENIED', 'NOT_FOUND']);

        $eigen = ($this->run)('foodalchemist.production_orders.LINE_ADD', ['order_id' => $this->order->id, 'titel' => 'Eigen']);
        $rm = ($this->run)('foodalchemist.production_orders.LINE_REMOVE', ['line_id' => $eigen->data['line']['id'], 'confirm' => true], $this->childA);
        expect($rm->success)->toBeFalse()->and($rm->errorCode)->toBeIn(['ACCESS_DENIED', 'NOT_FOUND'])
            ->and(Line::find($eigen->data['line']['id']))->not->toBeNull();
    });
});

// ── concepts.KALKULATION ────────────────────────────────────────────────────

it('concepts.KALKULATION rechnet ein sichtbares Master-Concept für eine Gästezahl (read-only)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Menü');
    $gericht = $this->makeRecipe($this->rootTeam, 'Lachs', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($gericht, 12.0);
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $gericht->id]);

    $r = ($this->run)('foodalchemist.concepts.KALKULATION', ['concept_id' => $concept->id, 'pax' => 40], $this->childA);

    expect($r->success)->toBeTrue()
        ->and($r->data['pax'])->toBe(40)
        ->and($r->data['kalkulation']['pax'])->toBe(40)
        ->and($r->data['kalkulation'])->toHaveKey('mek');
});

it('concepts.KALKULATION: pax < 1 → VALIDATION_ERROR, unsichtbares Concept → NOT_FOUND', function () {
    $concept = $this->makeConcept($this->childB, 'Fremd');

    expect(($this->run)('foodalchemist.concepts.KALKULATION', ['concept_id' => $concept->id, 'pax' => 0], $this->childB)->errorCode)
        ->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.concepts.KALKULATION', ['concept_id' => $concept->id, 'pax' => 10], $this->childA)->errorCode)
        ->toBe('NOT_FOUND');
});

// ── recipes.DICHTEKLASSE ────────────────────────────────────────────────────

it('recipes.DICHTEKLASSE setzt die Klasse (Herkunft manual) und leert sie wieder', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Püree', ['status' => 'draft']);

    $r = ($this->run)('foodalchemist.recipes.DICHTEKLASSE', ['recipe_id' => $rezept->id, 'dichteklasse' => 'dicht']);
    expect($r->success)->toBeTrue()
        ->and($rezept->refresh()->dichteklasse)->toBe('dicht')
        ->and($rezept->dichteklasse_source)->toBe('manual');

    $leer = ($this->run)('foodalchemist.recipes.DICHTEKLASSE', ['recipe_id' => $rezept->id, 'dichteklasse' => '']);
    expect($leer->success)->toBeTrue()
        ->and($rezept->refresh()->dichteklasse)->toBeNull()
        ->and($rezept->dichteklasse_source)->toBeNull();
});

it('recipes.DICHTEKLASSE: unbekannte Klasse → VALIDATION_ERROR, geerbtes Rezept → ACCESS_DENIED', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Suppe', ['status' => 'draft']);

    expect(($this->run)('foodalchemist.recipes.DICHTEKLASSE', ['recipe_id' => $rezept->id, 'dichteklasse' => 'zaeh'])->errorCode)
        ->toBe('VALIDATION_ERROR');
    expect(($this->run)('foodalchemist.recipes.DICHTEKLASSE', ['recipe_id' => $rezept->id, 'dichteklasse' => 'fluessig'], $this->childA)->errorCode)
        ->toBe('ACCESS_DENIED');
    expect($rezept->refresh()->dichteklasse)->toBeNull();
});
