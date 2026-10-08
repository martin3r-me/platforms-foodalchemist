<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D11: Bestell-Belegfacetten (Rechnung/Zahlung/Freigabe/Lieferantenbest./
 * Wareneingang/Reklamation) + Zeilen-Ops + Versand + Produktionsplaner + Anproduktion.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    // Spec 75c: Rechte kommen aus der Team-Rolle — der Test-Benutzer ist Inhaber seines Teams (vorher Nicht-Mitglied = Lesen).
    \Illuminate\Support\Facades\DB::table('team_user')->insert(['team_id' => $this->rootTeam->id, 'user_id' => $this->user->id, 'role' => 'owner']);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->svc = app(OrderService::class);

    $supplier = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
    $gp = $this->makeGp($this->rootTeam, 'Mehl');
    $this->item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Mehl 1kg', 'article_number' => 'ART-MEH', 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->item->id, 'gp_id' => $gp->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->item->id, 'price' => 1.50, 'status' => '0']);
    $gp->update(['lead_la_supplier_item_id' => $this->item->id]);
});

it('Registry-Smoke: alle 13 D11-Tools registriert mit type=object', function () {
    $namen = [
        'orders.UPDATE_INVOICE', 'orders.UPDATE_PAYMENT', 'orders.UPDATE_APPROVAL', 'orders.CONFIRM_SUPPLIER',
        'orders.RECEIPT', 'orders.CLAIM', 'orders.REMOVE_LINE', 'orders.SWITCH_ARTICLE', 'orders.DISPATCH',
        'production_plan.SUGGEST', 'production_plan.APPLY', 'speiseplan.ANPRODUKTION', 'angebote.ANPRODUKTION',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('UPDATE_APPROVAL (Draft) + REMOVE_LINE (confirm)', function () {
    $line = $this->svc->addManualLine($this->rootTeam, $this->item->id, 3.0);
    $orderId = (int) $line->order_id;

    $appr = ($this->run)('foodalchemist.orders.UPDATE_APPROVAL', ['order_id' => $orderId, 'approval_status' => 'approved']);
    expect($appr->success)->toBeTrue('appr: ' . ($appr->error ?? ''));

    expect(($this->run)('foodalchemist.orders.REMOVE_LINE', ['line_id' => $line->id])->errorCode)->toBe('CONFIRM_REQUIRED');
    $rm = ($this->run)('foodalchemist.orders.REMOVE_LINE', ['line_id' => $line->id, 'confirm' => true]);
    expect($rm->success)->toBeTrue('rm: ' . ($rm->error ?? ''));
    expect(FoodAlchemistOrderLine::find($line->id))->toBeNull();
});

it('Beleg-Facetten auf versendeter Bestellung: CONFIRM_SUPPLIER / UPDATE_INVOICE / UPDATE_PAYMENT', function () {
    $line = $this->svc->addManualLine($this->rootTeam, $this->item->id, 2.0);
    $order = $line->order;
    $order->update(['status' => 'sent']);   // ohne echten Mailversand in den Sent-Zustand

    $conf = ($this->run)('foodalchemist.orders.CONFIRM_SUPPLIER', ['order_id' => $order->id, 'supplier_order_number' => 'LO-99']);
    expect($conf->success)->toBeTrue('conf: ' . ($conf->error ?? ''));

    $inv = ($this->run)('foodalchemist.orders.UPDATE_INVOICE', ['order_id' => $order->id, 'invoice_number' => 'R-1', 'invoice_date' => '2027-01-10']);
    expect($inv->success)->toBeTrue('inv: ' . ($inv->error ?? ''));

    $pay = ($this->run)('foodalchemist.orders.UPDATE_PAYMENT', ['order_id' => $order->id, 'payment_status' => 'paid']);
    expect($pay->success)->toBeTrue('pay: ' . ($pay->error ?? ''));
    expect($order->fresh()->payment_status)->toBe('paid');
});

it('Confirm-Gates + SUGGEST + Guards', function () {
    $line = $this->svc->addManualLine($this->rootTeam, $this->item->id, 1.0);
    $orderId = (int) $line->order_id;

    // Outward/erzeugend → confirm Pflicht
    expect(($this->run)('foodalchemist.orders.DISPATCH', ['order_ids' => [$orderId]])->errorCode)->toBe('CONFIRM_REQUIRED');
    expect(($this->run)('foodalchemist.production_plan.APPLY', ['von' => '2027-01-04', 'bis' => '2027-01-10'])->errorCode)->toBe('CONFIRM_REQUIRED');
    expect(($this->run)('foodalchemist.angebote.ANPRODUKTION', ['id' => 1])->errorCode)->toBe('CONFIRM_REQUIRED');

    // SUGGEST (read) läuft
    $sug = ($this->run)('foodalchemist.production_plan.SUGGEST', ['von' => '2027-01-04', 'bis' => '2027-01-10']);
    expect($sug->success)->toBeTrue('sug: ' . ($sug->error ?? ''))->and($sug->data)->toHaveKey('vorschlag');

    // Cross-tenant
    expect(($this->run)('foodalchemist.orders.UPDATE_APPROVAL', ['order_id' => $orderId, 'approval_status' => 'approved'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->run)('foodalchemist.orders.REMOVE_LINE', ['line_id' => 999999, 'confirm' => true])->errorCode)->toBe('NOT_FOUND');
});

it('speiseplan.ANPRODUKTION läuft auf dem Erfolgspfad (Tool übergibt Illuminate-Carbon an den Service)', function () {
    // Regression 2026-10-07: das Tool baute \Carbon\Carbon, der Service typisiert Illuminate\Support\Carbon
    // → TypeError bei JEDEM Aufruf. Bisher war nur der CONFIRM_REQUIRED-Zweig getestet.
    $g = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'anprod-1', 'name' => 'G-Anproduktion', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 3.0, 'ek_total_eur' => 1.0,
    ]);
    $plan = app(\Platform\FoodAlchemist\Services\SpeiseplanService::class);
    $sp = $plan->create($this->rootTeam, ['name' => 'MCP-Anproduktion', 'start_date' => '2026-07-06']);
    $plan->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-07-06', 'mahlzeit' => 'mittag', 'sales_recipe_id' => $g->id]);

    $res = ($this->run)('foodalchemist.speiseplan.ANPRODUKTION', [
        'plan_id' => $sp->id, 'mahlzeit' => 'mittag', 'montag' => '2026-07-06', 'confirm' => true,
    ]);

    expect($res->success)->toBeTrue('anproduktion: ' . ($res->error ?? ''))
        ->and($res->data['result']['auftraege'])->toBe(1)
        ->and($res->data['result']['tage'])->toBe(['2026-07-06']);
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder::where('team_id', $this->rootTeam->id)->count())->toBe(1);
});
